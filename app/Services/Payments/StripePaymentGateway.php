<?php

namespace App\Services\Payments;

use App\Exceptions\PaymentCapturedUnverified;
use App\Exceptions\PaymentCaptureException;
use App\Exceptions\PaymentGatewayUnavailable;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Money;
use App\Support\Payments\CheckoutIntent;
use Illuminate\Support\Carbon;
use LogicException;
use RuntimeException;
use SensitiveParameter;
use Stripe\Charge;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\Exception\IdempotencyException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\HttpClient\CurlClient;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod as StripePaymentMethod;
use Stripe\Refund;
use Stripe\StripeClient;
use Stripe\WebhookSignature;

/**
 * The one real collection gateway (ADR-030, R247), on `stripe/stripe-php` and nothing else: no
 * Cashier. It is built by `PaymentGatewayManager::forRow()` from the registry row's encrypted
 * credentials and holds them only in memory; nothing here logs, stores or returns a key, a card
 * number or a raw gateway body (invariants 15 and 16). Only the fields of `summary()` ever reach
 * `payments.raw_response` from this driver; what the refund actions ask and learn is in `payment_refunds` (24b).
 *
 * Money is integer fils on our side and Stripe's minor units for AED are fils, so amounts pass
 * through unchanged. Every amount sent and every amount checked is in `AED`.
 *
 * A decline is a `PaymentCaptureException`. For a saved-card charge any other failure (the network,
 * Stripe down, a rate limit) is rethrown as Stripe's own exception so the caller leaves the attempt
 * `pending` and the same idempotency key makes the retry safe (R101, `ChargeReservedLesson`). For the
 * checkout's `capture()` it is a `PaymentGatewayUnavailable` (R259): retryable, never a decline.
 */
final class StripePaymentGateway implements PaymentGateway
{
    public const CURRENCY = 'aed';

    /** The `metadata.kind` the checkout (23c) puts on the intent it creates for a booking. */
    public const KIND_CHECKOUT = 'checkout';

    private const KIND_RECURRING = 'recurring';

    /** R260: per request, so two calls (a refund's list and create) finish inside the 60-second queue timeout. */
    public const TIMEOUT_SECONDS = 20;

    public const CONNECT_TIMEOUT_SECONDS = 5;

    /** The instant format the checkout writes into `metadata.starts_at` (the booking form's own). */
    public const STARTS_AT_FORMAT = 'Y-m-d\TH:i:s\Z';

    /** Stripe's own default: a webhook timestamp older than this is a replay. */
    private const WEBHOOK_TOLERANCE_SECONDS = 300;

    private readonly StripeClient $client;

    public function __construct(
        #[SensitiveParameter] string $secretKey,
        #[SensitiveParameter] private readonly string $webhookSecret,
        private readonly bool $live = false,
        ?StripeClient $client = null,
    ) {
        // R260: Stripe's SDK waits 80 seconds by default. A refund is a list plus a create, so two calls at this
        // limit stay inside the 60-second queue timeout (`config/horizon.php`) that would otherwise kill a job
        // mid-call. The SDK keeps one shared HTTP client, so this applies to every Stripe call in the process.
        CurlClient::instance()->setTimeout(self::TIMEOUT_SECONDS)->setConnectTimeout(self::CONNECT_TIMEOUT_SECONDS);

        $this->client = $client ?? new StripeClient($secretKey);
    }

    public function driver(): string
    {
        return 'stripe';
    }

    /**
     * A dump or a serialisation of this object must not carry the keys it holds.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['driver' => $this->driver(), 'live' => $this->live];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('A payment gateway holds credentials and cannot be serialised.');
    }

    /**
     * Captures the authorisation the parent's browser confirmed (R248, R256). The checkout creates the
     * intent with `capture_method: manual`, so the browser's confirmation (3-D Secure included) only
     * holds the money and a booking that then fails costs the parent nothing: the checkout cancels the
     * hold (`CancelCheckoutIntent`) and Stripe releases it, with no refund and no ledger entry. Money
     * moves only here, after every check below has passed.
     *
     * `capture` takes no PaymentIntent id (the interface is frozen, R255), so the checkout names it in
     * the request-scoped `CheckoutIntent` before `BookLesson` runs; without one nothing is captured. The
     * intent must be held (`requires_capture`) for exactly this amount in AED, in this row's mode, and
     * made by the checkout for this very booking: this account, this learner, this tutor and this start
     * time, so one parent's authorisation cannot pay for another lesson. It must not already be the
     * reference of another payment (the unique index on `payments (gateway, gateway_ref)` closes the
     * race this read cannot). The capture's idempotency key comes from the intent, so a retry after a
     * lost response captures once.
     */
    public function capture(Lesson $lesson, Money $amount, string $idempotencyKey): PaymentCaptureResult
    {
        $intentId = app(CheckoutIntent::class)->get();

        if ($intentId === null) {
            throw new PaymentCaptureException('no_payment_intent');
        }

        try {
            $intent = $this->client->paymentIntents->retrieve($intentId, ['expand' => ['latest_charge']]);
        } catch (ApiErrorException $e) {
            // An id that does not exist (or is not this account's) is the caller's mistake, not an outage.
            if ($this->isPermanentRefusal($e)) {
                throw new PaymentCaptureException('payment_not_found');
            }

            throw new PaymentGatewayUnavailable($intentId, $e);
        }

        if ((bool) $intent->livemode !== $this->live) {
            throw new PaymentCaptureException('mode_mismatch');
        }

        // `succeeded` is an authorisation that was already taken. It is this booking's only when every
        // check below passes and no other payment owns it: that is a retry whose first capture went
        // through but whose answer was lost (R259), and it is then not captured a second time.
        $alreadyCaptured = $intent->status === PaymentIntent::STATUS_SUCCEEDED
            && $intent->capture_method === 'manual'
            && $intent->amount_received === $amount->toFils();

        if (! $alreadyCaptured && ($intent->status !== PaymentIntent::STATUS_REQUIRES_CAPTURE || $intent->capture_method !== 'manual')) {
            throw new PaymentCaptureException('payment_not_completed');
        }

        if ($intent->amount !== $amount->toFils() || (! $alreadyCaptured && $intent->amount_capturable !== $amount->toFils()) || strtolower((string) $intent->currency) !== self::CURRENCY) {
            throw new PaymentCaptureException('amount_mismatch');
        }

        // Only an intent the checkout made for a booking can pay for one: not a saved-card charge, and
        // not an intent of this account's that was made for something else.
        if (($intent->metadata['kind'] ?? null) !== self::KIND_CHECKOUT) {
            throw new PaymentCaptureException('not_a_checkout_intent');
        }

        if ((string) ($intent->metadata['account_user_id'] ?? '') !== (string) $lesson->booked_by_user_id) {
            throw new PaymentCaptureException('payer_mismatch');
        }

        $bound = [
            'learner_id' => (string) $lesson->learner_id,
            'tutor_profile_id' => (string) $lesson->tutor_profile_id,
            'starts_at' => $lesson->starts_at->clone()->utc()->format(self::STARTS_AT_FORMAT),
        ];

        foreach ($bound as $key => $expected) {
            if ((string) ($intent->metadata[$key] ?? '') !== $expected) {
                throw new PaymentCaptureException('booking_mismatch');
            }
        }

        if (Payment::query()->where('gateway', $this->driver())->where('gateway_ref', $intent->id)->exists()) {
            throw new PaymentCaptureException('payment_already_used');
        }

        $captured = $intent;

        if (! $alreadyCaptured) {
            try {
                $captured = $this->client->paymentIntents->capture(
                    $intent->id,
                    ['amount_to_capture' => $amount->toFils(), 'expand' => ['latest_charge']],
                    ['idempotency_key' => 'capture-'.$intent->id],
                );
            } catch (CardException $e) {
                throw new PaymentCaptureException($this->declineCode($e));
            } catch (ApiErrorException $e) {
                if ($this->isPermanentRefusal($e) && ! $e instanceof IdempotencyException) {
                    // Cancelled, expired or captured by someone else between the read and now.
                    throw new PaymentCaptureException('payment_not_completed');
                }

                $captured = $this->recoverCapture($intent->id, $e);
            }
        }

        $charge = $captured->latest_charge;

        // Stripe accepted the capture, so from here on this is not a decline: the money may have moved.
        if ($captured->status !== PaymentIntent::STATUS_SUCCEEDED || $captured->amount_received !== $amount->toFils() || ! $charge instanceof Charge || $charge->refunded || $charge->disputed) {
            throw new PaymentCapturedUnverified($captured->id, $this->summary($captured));
        }

        return new PaymentCaptureResult(gatewayRef: $captured->id, rawResponse: $this->summary($captured));
    }

    /**
     * A refusal that says something about the request rather than about Stripe's health: a malformed or
     * unknown id, an intent in the wrong state. A rate limit (a subclass of InvalidRequestException) and a
     * lock timeout are Stripe asking to try again, as in `refund()`, and a connection or API error is an
     * outage; none of those is permanent.
     */
    private function isPermanentRefusal(ApiErrorException $e): bool
    {
        return $e instanceof InvalidRequestException
            && ! $e instanceof RateLimitException
            && $e->getStripeCode() !== 'lock_timeout';
    }

    /**
     * The capture call ended without a usable answer, so whether the money moved is not known. One
     * look at the intent decides: `succeeded` means the capture went through and the booking carries
     * on; still `requires_capture` means it did not, and the parent retries on the same hold; any other
     * state is not one this booking may take.
     */
    private function recoverCapture(string $intentId, ApiErrorException $cause): PaymentIntent
    {
        try {
            $now = $this->client->paymentIntents->retrieve($intentId, ['expand' => ['latest_charge']]);
        } catch (ApiErrorException) {
            throw new PaymentGatewayUnavailable($intentId, $cause);
        }

        if ($now->status === PaymentIntent::STATUS_SUCCEEDED) {
            return $now;
        }

        if ($now->status === PaymentIntent::STATUS_REQUIRES_CAPTURE) {
            throw new PaymentGatewayUnavailable($intentId, $cause);
        }

        throw new PaymentCaptureException('payment_not_completed');
    }

    /**
     * `$selection` is the id of the SetupIntent the parent's browser confirmed (Stripe Elements). The
     * card it produced is attached to the SetupIntent's customer when it is not yet, and only the
     * token, brand, last four and expiry come back (invariant 15).
     */
    public function saveCard(User $account, string $selection): SavedCard
    {
        try {
            $setup = $this->client->setupIntents->retrieve($selection, ['expand' => ['payment_method']]);
        } catch (InvalidRequestException) {
            throw new PaymentCaptureException('setup_not_found');
        }

        if ($setup->status !== 'succeeded' || $setup->payment_method === null || $setup->customer === null) {
            throw new PaymentCaptureException('setup_not_completed');
        }

        if ((string) ($setup->metadata['account_user_id'] ?? '') !== (string) $account->id) {
            throw new PaymentCaptureException('payer_mismatch');
        }

        $method = $setup->payment_method;
        $customer = (string) $setup->customer;

        if (! $method instanceof StripePaymentMethod) {
            throw new PaymentCaptureException('setup_not_completed');
        }

        if ($method->customer === null) {
            $method = $this->client->paymentMethods->attach($method->id, ['customer' => $customer]);
        } elseif ((string) $method->customer !== $customer) {
            throw new PaymentCaptureException('payer_mismatch');
        }

        $card = $method->card;

        if ($card === null) {
            throw new PaymentCaptureException('not_a_card');
        }

        return new SavedCard(
            gatewayCustomerRef: $customer,
            gatewayToken: $method->id,
            brand: ucfirst((string) $card->brand),
            last4: (string) $card->last4,
            expMonth: (int) $card->exp_month,
            expYear: (int) $card->exp_year,
        );
    }

    /**
     * An off-session charge of the saved card, confirmed at once. A card that needs the cardholder
     * (`authentication_required`, or any status short of `succeeded`) is an ordinary failed attempt:
     * the weekly job's retry and email path handles it, and the intent is cancelled so it cannot be
     * completed later behind that decision's back.
     */
    public function chargeSavedCard(Lesson $lesson, Money $amount, PaymentMethod $method, string $idempotencyKey): PaymentCaptureResult
    {
        // A card saved while another gateway was active (the fake, in a test environment) has no meaning
        // here; sending its token would be a permanent error that the caller would retry for ever.
        if ($method->gateway !== $this->driver()) {
            throw new PaymentCaptureException('payment_method_not_stripe');
        }

        // Stripe forgets an idempotency key after 24 hours. An attempt that has been pending for long
        // enough that its key may be gone first looks for the intent it made, so the retry never creates
        // a second charge (R259 (6)).
        $intent = $this->staleAttempt($lesson) ? $this->findAttemptIntent($method, $idempotencyKey, $lesson, $lesson->charge_attempts + 1) : null;

        if ($intent === null) {
            try {
                $intent = $this->client->paymentIntents->create([
                    'amount' => $amount->toFils(),
                    'currency' => self::CURRENCY,
                    'customer' => $method->gateway_customer_ref,
                    'payment_method' => $method->gateway_token,
                    'off_session' => true,
                    'confirm' => true,
                    'metadata' => [
                        'kind' => self::KIND_RECURRING,
                        'lesson_id' => (string) $lesson->id,
                        'attempt' => (string) ($lesson->charge_attempts + 1),
                        'account_user_id' => (string) $method->account_user_id,
                        'idempotency_key' => $idempotencyKey,
                    ],
                ], ['idempotency_key' => $idempotencyKey]);
            } catch (CardException $e) {
                throw new PaymentCaptureException($this->declineCode($e));
            } catch (IdempotencyException $e) {
                // The key was used before with other parameters (an attempt started before the metadata key
                // existed, or a changed card token). The first call may have charged the card: take the
                // intent it made, and if it cannot be found leave the attempt pending, never a failed
                // attempt that the next key would charge again.
                $intent = $this->findAttemptIntent($method, $idempotencyKey, $lesson, $lesson->charge_attempts + 1)
                    ?? throw new RuntimeException('The attempt\'s idempotency key is in use and its intent was not found.', previous: $e);
            } catch (ApiErrorException $e) {
                if (! $this->isPermanentRefusal($e)) {
                    // An outage, a rate limit or a lock timeout: the attempt stays pending and is retried.
                    throw $e;
                }

                // Permanent: a missing or detached card, a deleted customer. Retrying with the same key would
                // replay the same refusal, so it is a failed attempt (the next attempt has a new key).
                throw new PaymentCaptureException('card_unusable');
            }
        }

        if ($intent->status === PaymentIntent::STATUS_PROCESSING) {
            // Not an outcome yet, and Stripe will not cancel it: leave the attempt pending so the retry
            // reuses the key and sees the final state, instead of a second charge on a new key.
            throw new RuntimeException('The card payment is still processing.');
        }

        if ($intent->status !== PaymentIntent::STATUS_SUCCEEDED) {
            $this->cancelQuietly($intent);

            throw new PaymentCaptureException('authentication_required');
        }

        return new PaymentCaptureResult(gatewayRef: $intent->id, rawResponse: $this->summary($intent));
    }

    /** How old a payment attempt must be before Stripe may have forgotten the idempotency key it used. */
    private const STALE_ATTEMPT_HOURS = 12;

    private function staleAttempt(Lesson $lesson): bool
    {
        return Payment::query()
            ->where('lesson_id', $lesson->id)
            ->where('attempt_no', $lesson->charge_attempts + 1)
            ->where('created_at', '<=', now()->subHours(self::STALE_ATTEMPT_HOURS))
            ->exists();
    }

    /**
     * The intent an earlier try of this attempt made, found by the key written onto its metadata. The
     * list is by customer and creation time (read-your-writes consistent, unlike the search API).
     */
    private function findAttemptIntent(PaymentMethod $method, string $idempotencyKey, Lesson $lesson, int $attempt): ?PaymentIntent
    {
        $since = Payment::query()
            ->where('lesson_id', $lesson->id)
            ->where('attempt_no', $attempt)
            ->min('created_at');

        try {
            $found = $this->client->paymentIntents->all([
                'customer' => $method->gateway_customer_ref,
                'created' => ['gte' => max(0, ($since === null ? now()->subDay() : Carbon::parse($since))->subHour()->getTimestamp())],
                'limit' => 100,
            ]);
        } catch (InvalidRequestException $e) {
            if ($this->isPermanentRefusal($e)) {
                // A deleted customer: nothing to find, and the charge itself then ends as card_unusable.
                return null;
            }

            throw $e;
        }

        foreach ($found->data as $candidate) {
            $meta = $candidate->metadata;

            // The key itself, or (an intent made before the key was written onto the metadata) the lesson
            // and attempt number it carries.
            if (($meta['idempotency_key'] ?? null) === $idempotencyKey
                || (($meta['kind'] ?? null) === self::KIND_RECURRING
                    && (string) ($meta['lesson_id'] ?? '') === (string) $lesson->id
                    && (string) ($meta['attempt'] ?? '') === (string) $attempt)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Refunds part or all of a captured payment. The payment's `gateway_ref` is the PaymentIntent id.
     * A refusal is a `PaymentCaptureException` (the caller lists it to admins, R256); the network
     * and the like rethrow so the queue retries with the same key.
     *
     * Stripe forgets an idempotency key after 24 hours, and a retry (a queue retry, a sweep, an admin
     * re-running a failed job) can come later than that. The key is therefore also written onto the
     * refund as metadata and looked up before anything is created, so the same ledger entry never
     * refunds twice whatever the age of the retry.
     */
    public function refund(Payment $payment, Money $amount, string $idempotencyKey): PaymentCaptureResult
    {
        if ($payment->gateway !== $this->driver() || $payment->gateway_ref === null) {
            throw new PaymentCaptureException('not_a_stripe_payment');
        }

        try {
            $existing = $this->client->refunds->all(['payment_intent' => $payment->gateway_ref, 'limit' => 100]);

            foreach ($existing->data as $made) {
                if (($made->metadata['idempotency_key'] ?? null) === $idempotencyKey) {
                    return $this->refundResult($made);
                }
            }

            $refund = $this->client->refunds->create([
                'payment_intent' => $payment->gateway_ref,
                'amount' => $amount->toFils(),
                'metadata' => ['payment_id' => (string) $payment->id, 'idempotency_key' => $idempotencyKey],
            ], ['idempotency_key' => $idempotencyKey]);
        } catch (CardException|InvalidRequestException $e) {
            // A rate limit (a subclass of InvalidRequestException) or a lock timeout is Stripe asking us to try
            // again, not refusing: it must reach the queue, because a refusal is recorded as permanent.
            if ($e instanceof RateLimitException || $e->getStripeCode() === 'lock_timeout') {
                throw $e;
            }

            throw new PaymentCaptureException($e->getStripeCode() ?? 'refund_refused');
        }

        return $this->refundResult($refund);
    }

    /**
     * 24b (R260): the refunds Stripe holds for the payment, for `ReconcileGatewayRefunds` to tell the platform's
     * own from ones made in the Stripe dashboard. Read-only; a gateway-level failure propagates so the queue
     * retries.
     *
     * @return list<array{id: string, amount: int, status: string, idempotency_key: ?string, created: int}>
     */
    public function listRefunds(Payment $payment): array
    {
        if ($payment->gateway !== $this->driver() || $payment->gateway_ref === null) {
            return [];
        }

        $found = [];

        foreach ($this->client->refunds->all(['payment_intent' => $payment->gateway_ref, 'limit' => 100])->data as $refund) {
            $key = $refund->metadata['idempotency_key'] ?? null;

            $found[] = [
                'id' => $refund->id,
                'amount' => (int) $refund->amount,
                'status' => (string) $refund->status,
                'idempotency_key' => is_string($key) ? $key : null,
                'created' => (int) $refund->created,
            ];
        }

        return $found;
    }

    private function refundResult(Refund $refund): PaymentCaptureResult
    {
        // A refund Stripe reports as failed or canceled (also one found by its metadata key) returned nothing to
        // the card; it is a refusal for admins, not a confirmation.
        if (in_array($refund->status, ['failed', 'canceled'], true)) {
            throw new PaymentCaptureException('refund_'.$refund->status);
        }

        return new PaymentCaptureResult(
            gatewayRef: $refund->id,
            rawResponse: ['driver' => $this->driver(), 'id' => $refund->id, 'status' => $refund->status, 'amount' => $refund->amount, 'currency' => $refund->currency],
        );
    }

    /**
     * Stripe's own check of the `Stripe-Signature` header (`t=…,v1=…`): HMAC-SHA256 of `t.payload`
     * with the endpoint secret, constant-time compared, timestamp within five minutes. It builds no
     * event and calls no API.
     */
    public function verifyWebhook(string $payload, array $headers): bool
    {
        $header = $headers['stripe-signature'] ?? '';

        if ($header === '' || $this->webhookSecret === '') {
            return false;
        }

        try {
            return WebhookSignature::verifyHeader($payload, $header, $this->webhookSecret, self::WEBHOOK_TOLERANCE_SECONDS);
        } catch (SignatureVerificationException) {
            return false;
        }
    }

    /**
     * R259 (5): what became of an intent a booking request recorded but never finished with (it died, or
     * the gateway was unreachable). An open hold (`requires_capture`) is released so the parent's card is
     * not left blocked; nothing else is changed. Not part of `PaymentGateway` (frozen, R255): the
     * reconciliation sweep asks for it by class.
     *
     * @return array{status: string, amount: int, amount_received: int}
     *
     * @throws PaymentGatewayUnavailable when the gateway cannot be reached
     */
    public function settleStrandedIntent(string $intentId, string $accountUserId): array
    {
        try {
            $intent = $this->client->paymentIntents->retrieve($intentId);

            // The id came from the client: an intent that is not this account's checkout hold is left alone.
            if (($intent->metadata['kind'] ?? null) !== self::KIND_CHECKOUT || (string) ($intent->metadata['account_user_id'] ?? '') !== $accountUserId) {
                return ['status' => 'missing', 'amount' => 0, 'amount_received' => 0];
            }

            if ($intent->status === 'requires_capture') {
                $intent = $this->client->paymentIntents->cancel($intentId);
            }
        } catch (ApiErrorException $e) {
            if ($this->isPermanentRefusal($e)) {
                return ['status' => 'missing', 'amount' => 0, 'amount_received' => 0];
            }

            throw new PaymentGatewayUnavailable($intentId, $e);
        }

        return ['status' => $intent->status, 'amount' => $intent->amount, 'amount_received' => $intent->amount_received];
    }

    /**
     * The only fields of an intent that are kept: no customer, no payment method id, no card data.
     *
     * @return array<string, mixed>
     */
    private function summary(PaymentIntent $intent): array
    {
        return [
            'driver' => $this->driver(),
            'id' => $intent->id,
            'status' => $intent->status,
            'amount' => $intent->amount,
            'amount_received' => $intent->amount_received,
            'currency' => $intent->currency,
            'latest_charge' => is_string($intent->latest_charge) ? $intent->latest_charge : $intent->latest_charge?->id,
        ];
    }

    private function declineCode(CardException $e): string
    {
        $code = $e->getDeclineCode() ?? $e->getStripeCode();

        return is_string($code) && preg_match('/^[a-z0-9_]{1,64}$/', $code) === 1 ? $code : 'card_declined';
    }

    private function cancelQuietly(PaymentIntent $intent): void
    {
        try {
            $this->client->paymentIntents->cancel($intent->id);
        } catch (ApiErrorException) {
            // Already terminal, or unreachable: the attempt is recorded as failed either way.
        }
    }
}
