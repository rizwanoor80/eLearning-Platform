<?php

namespace App\Services\Payments;

use App\Exceptions\PaymentCaptureException;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Money;

/**
 * Invariant 16: driver and credentials resolve from the `payment_gateways`
 * admin registry (CP5). Until then `PaymentGatewayServiceProvider` binds the
 * fake driver on `local`, `testing` and `rehearsal` only (R107, ADR-016);
 * everywhere else, `production` above all, the interface stays unbound so
 * resolving it fails loudly rather than pretending a gateway exists.
 */
interface PaymentGateway
{
    /**
     * The registry driver key this implementation is stored under, e.g.
     * `fake` or `stripe`. Written to `payments.gateway`.
     */
    public function driver(): string;

    /**
     * @throws PaymentCaptureException
     */
    public function capture(Lesson $lesson, Money $amount, string $idempotencyKey): PaymentCaptureResult;

    /**
     * Saves a card for the account and returns its token and display details (R100). `$selection`
     * is the driver's own input: the fake driver takes one of its two test-card keys; a real driver
     * will take the token its hosted field produced. A card number never passes through here.
     */
    public function saveCard(User $account, string $selection): SavedCard;

    /**
     * Charges the account's saved card for one lesson (R100/R101). `$idempotencyKey` is derived from
     * the lesson id and the attempt number, so a repeat of the same attempt cannot charge twice.
     *
     * @throws PaymentCaptureException when the charge is declined or fails
     */
    public function chargeSavedCard(Lesson $lesson, Money $amount, PaymentMethod $method, string $idempotencyKey): PaymentCaptureResult;

    /**
     * Returns money to the payer's card (R247, R256). `$amount` is the part to refund, never more
     * than the payment still holds; `$idempotencyKey` is derived from the ledger entry that caused
     * the refund, so a repeat of the same refund cannot pay out twice. The result's `gatewayRef` is the
     * gateway's refund id (R255 allows no new result type in this directory).
     *
     * @throws PaymentCaptureException when the gateway refuses or cannot be reached
     */
    public function refund(Payment $payment, Money $amount, string $idempotencyKey): PaymentCaptureResult;

    /**
     * Whether a webhook body really came from this gateway: the signature check, nothing else.
     * `$headers` are lower-cased names to their first value.
     *
     * @param  array<string, string>  $headers
     */
    public function verifyWebhook(string $payload, array $headers): bool;
}
