<?php

namespace App\Services\Payments;

use App\Exceptions\PaymentCapturedUnverified;
use App\Exceptions\PaymentCaptureException;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Money;
use App\Support\Payments\CheckoutIntent;
use LogicException;
use RuntimeException;
use SensitiveParameter;
use Stripe\Charge;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\Exception\IdempotencyException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\SignatureVerificationException;
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
 * `payments.raw_response` from this driver; `RefundParentAtGateway` (23d) later adds the refund ids it
 * made (`refunds`) and the refusals it recorded (`refund_failures`), keyed by ledger entry.
 *
 * Money is integer fils on our side and Stripe's minor units for AED are fils, so amounts pass
 * through unchanged. Every amount sent and every amount checked is in `AED`.
 *
 * A decline is a `PaymentCaptureException`; any other failure (the network, Stripe down, a rate
 * limit) is rethrown as Stripe's own exception so the caller leaves the attempt `pending` and the
 * same idempotency key makes the retry safe (R101, `ChargeReservedLesson`).
 */
final class StripePaymentGateway implements PaymentGateway
{
    public const CURRENCY = 'aed';

    /** The `metadata.kind` the checkout (23c) puts on the intent it creates for a booking. */
    public const KIND_CHECKOUT = 'checkout';

    private const KIND_RECURRING = 'recurring';

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
            $intent = $this->client->paymentIntents->retrieve($intentId);
        } catch (InvalidRequestException) {
            // An id that does not exist (or is not this account's) is the caller's mistake, not an outage.
            throw new PaymentCaptureException('payment_not_found');
        }

        if ((bool) $intent->livemode !== $this->live) {
            throw new PaymentCaptureException('mode_mismatch');
        }

        // `succeeded` here means it was captured already (by another booking, or a manual-capture-less
        // intent): either way it is not an authorisation this booking may take.
        if ($intent->status !== PaymentIntent::STATUS_REQUIRES_CAPTURE || $intent->capture_method !== 'manual') {
            throw new PaymentCaptureException('payment_not_completed');
        }

        if ($intent->amount !== $amount->toFils() || $intent->amount_capturable !== $amount->toFils() || strtolower((string) $intent->currency) !== self::CURRENCY) {
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

        try {
            $captured = $this->client->paymentIntents->capture(
                $intent->id,
                ['amount_to_capture' => $amount->toFils(), 'expand' => ['latest_charge']],
                ['idempotency_key' => 'capture-'.$intent->id],
            );
        } catch (CardException $e) {
            throw new PaymentCaptureException($this->declineCode($e));
        } catch (InvalidRequestException|IdempotencyException) {
            // Cancelled, expired or captured by someone else between the read and now.
            throw new PaymentCaptureException('payment_not_completed');
        }

        $charge = $captured->latest_charge;

        // Stripe accepted the capture, so from here on this is not a decline: the money may have moved.
        if ($captured->status !== PaymentIntent::STATUS_SUCCEEDED || $captured->amount_received !== $amount->toFils() || ! $charge instanceof Charge || $charge->refunded || $charge->disputed) {
            throw new PaymentCapturedUnverified($captured->id, $this->summary($captured));
        }

        return new PaymentCaptureResult(gatewayRef: $captured->id, rawResponse: $this->summary($captured));
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
                ],
            ], ['idempotency_key' => $idempotencyKey]);
        } catch (CardException $e) {
            throw new PaymentCaptureException($this->declineCode($e));
        } catch (InvalidRequestException|IdempotencyException) {
            // Permanent: a missing or detached card, a deleted customer, a key reused with other
            // parameters. Retrying with the same key would replay the same refusal, so it is a failed
            // attempt (the next attempt has a new key). Outages and rate limits still rethrow.
            throw new PaymentCaptureException('card_unusable');
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
            throw new PaymentCaptureException($e->getStripeCode() ?? 'refund_refused');
        }

        return $this->refundResult($refund);
    }

    private function refundResult(Refund $refund): PaymentCaptureResult
    {
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
