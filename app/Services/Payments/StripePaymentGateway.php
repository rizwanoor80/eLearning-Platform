<?php

namespace App\Services\Payments;

use App\Exceptions\PaymentCaptureException;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Money;
use App\Support\Payments\CheckoutIntent;
use SensitiveParameter;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod as StripePaymentMethod;
use Stripe\StripeClient;
use Stripe\WebhookSignature;

/**
 * The one real collection gateway (ADR-030, R247), on `stripe/stripe-php` and nothing else: no
 * Cashier. It is built by `PaymentGatewayManager::forRow()` from the registry row's encrypted
 * credentials and holds them only in memory; nothing here logs, stores or returns a key, a card
 * number or a raw gateway body (invariants 15 and 16). Only the fields of `summary()` ever reach
 * `payments.raw_response`.
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
     * Verifies, and moves no money: the parent's browser already confirmed the intent (R256). `capture`
     * takes no PaymentIntent id (the interface is frozen, R255), so the checkout (23c) names it in the
     * request-scoped `CheckoutIntent` before `BookLesson` runs; without one nothing is captured. The
     * intent must have succeeded and been received in full, for exactly this amount in AED, in this
     * row's mode, and have been created for this booking's account; and it must not already be the
     * reference of another payment, so one intent cannot pay for two lessons (the unique index on
     * `payments (gateway, gateway_ref)` closes the race this read cannot). The `$idempotencyKey` is
     * not used: a retrieval has nothing to repeat.
     */
    public function capture(Lesson $lesson, Money $amount, string $idempotencyKey): PaymentCaptureResult
    {
        $intentId = app(CheckoutIntent::class)->get();

        if ($intentId === null) {
            throw new PaymentCaptureException('no_payment_intent');
        }

        $intent = $this->client->paymentIntents->retrieve($intentId);

        if ((bool) $intent->livemode !== $this->live) {
            throw new PaymentCaptureException('mode_mismatch');
        }

        if ($intent->status !== PaymentIntent::STATUS_SUCCEEDED) {
            throw new PaymentCaptureException('payment_not_completed');
        }

        if ($intent->amount !== $amount->toFils() || $intent->amount_received !== $amount->toFils() || strtolower((string) $intent->currency) !== self::CURRENCY) {
            throw new PaymentCaptureException('amount_mismatch');
        }

        if ((string) ($intent->metadata['account_user_id'] ?? '') !== (string) $lesson->booked_by_user_id) {
            throw new PaymentCaptureException('payer_mismatch');
        }

        if (Payment::query()->where('gateway', $this->driver())->where('gateway_ref', $intent->id)->exists()) {
            throw new PaymentCaptureException('payment_already_used');
        }

        return new PaymentCaptureResult(gatewayRef: $intent->id, rawResponse: $this->summary($intent));
    }

    /**
     * `$selection` is the id of the SetupIntent the parent's browser confirmed (Stripe Elements). The
     * card it produced is attached to the SetupIntent's customer when it is not yet, and only the
     * token, brand, last four and expiry come back (invariant 15).
     */
    public function saveCard(User $account, string $selection): SavedCard
    {
        $setup = $this->client->setupIntents->retrieve($selection, ['expand' => ['payment_method']]);

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
        try {
            $intent = $this->client->paymentIntents->create([
                'amount' => $amount->toFils(),
                'currency' => self::CURRENCY,
                'customer' => $method->gateway_customer_ref,
                'payment_method' => $method->gateway_token,
                'off_session' => true,
                'confirm' => true,
                'metadata' => [
                    'lesson_id' => (string) $lesson->id,
                    'attempt' => (string) ($lesson->charge_attempts + 1),
                    'account_user_id' => (string) $method->account_user_id,
                ],
            ], ['idempotency_key' => $idempotencyKey]);
        } catch (CardException $e) {
            throw new PaymentCaptureException($this->declineCode($e));
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
     */
    public function refund(Payment $payment, Money $amount, string $idempotencyKey): PaymentCaptureResult
    {
        if ($payment->gateway !== $this->driver() || $payment->gateway_ref === null) {
            throw new PaymentCaptureException('not_a_stripe_payment');
        }

        try {
            $refund = $this->client->refunds->create([
                'payment_intent' => $payment->gateway_ref,
                'amount' => $amount->toFils(),
                'metadata' => ['payment_id' => (string) $payment->id],
            ], ['idempotency_key' => $idempotencyKey]);
        } catch (CardException|InvalidRequestException $e) {
            throw new PaymentCaptureException($e->getStripeCode() ?? 'refund_refused');
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
