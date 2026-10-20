<?php

namespace App\Actions\Payments;

use App\Enums\PaymentStatus;
use App\Events\Payments\PaymentNeedsAttention;
use App\Models\Payment;
use App\Support\Money;

/**
 * 23b (R247): what a verified, first-seen gateway event does here. Deliberately little: the lesson
 * state machine and the ledger are never touched from a webhook (invariants 1 and 2).
 *
 * - `payment_intent.succeeded` / `payment_intent.payment_failed`: recorded only. Booking and the weekly
 *   charge already decided from the API response; a webhook that disagrees changes nothing.
 * - `charge.refunded`: the charge's total refunded amount is absolute, so the payment's
 *   `refunded_amount` is set to it only when it is larger (never lowered, so a late or out-of-order
 *   event cannot undo a newer one), and admins are told that the gateway moved money the platform did
 *   not. A refund the platform made itself (23d) is already recorded and so changes nothing here.
 * - `charge.dispute.created`: admins are told. No state changes.
 * - Anything else is ignored by the controller before it is stored.
 */
class ApplyPaymentWebhook
{
    public const HANDLED = [
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'charge.refunded',
        'charge.dispute.created',
    ];

    /**
     * @param  array<string, mixed>  $object  the event's `data.object`
     */
    public function __invoke(string $gatewayCode, string $type, array $object): void
    {
        if ($type !== 'charge.refunded' && $type !== 'charge.dispute.created') {
            return;
        }

        $intentId = $object['payment_intent'] ?? null;

        $payment = is_string($intentId) && $intentId !== ''
            ? Payment::query()->where('gateway', $gatewayCode)->where('gateway_ref', $intentId)->lockForUpdate()->first()
            : null;

        if ($payment === null) {
            return;
        }

        if ($type === 'charge.dispute.created') {
            PaymentNeedsAttention::dispatch($payment, PaymentNeedsAttention::DISPUTED_AT_GATEWAY);

            return;
        }

        $refunded = $object['amount_refunded'] ?? null;

        if (! is_int($refunded) || $refunded <= 0) {
            return;
        }

        $new = Money::fils(min($refunded, $payment->amount->toFils()));

        if ($payment->refunded_amount !== null && $new->compare($payment->refunded_amount) <= 0) {
            return;
        }

        $payment->forceFill([
            'refunded_amount' => $new,
            'status' => $new->equals($payment->amount) ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded,
        ])->save();

        PaymentNeedsAttention::dispatch($payment, PaymentNeedsAttention::REFUNDED_AT_GATEWAY);
    }
}
