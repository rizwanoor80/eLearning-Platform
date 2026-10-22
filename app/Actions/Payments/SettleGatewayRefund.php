<?php

namespace App\Actions\Payments;

use App\Enums\LedgerEntryType;
use App\Enums\PaymentRefundKind;
use App\Enums\PaymentRefundStatus;
use App\Enums\PaymentStatus;
use App\Events\Payments\PaymentNeedsAttention;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * 25b (R267): the one place a `payment_refunds` row moves, whoever learned the gateway's word on the refund (the
 * call that made it, a `refund.updated` webhook, the reconcile job or the sweep's re-ask). It takes the payment's
 * lock, re-reads the row, and acts only on a `requested` one, so two paths that learn the same thing at once
 * settle it once and a late or out-of-order report changes nothing.
 *
 * - `succeeded`: the row is finished and the payment advanced.
 * - `failed` / `canceled`: the row is `failed` and admins are told. The ledger is never reversed.
 * - anything else (`pending`, `requires_action`): the row stays `requested`; only the gateway's status and refund id
 *   are recorded. Pending is not money returned.
 * - A row already settled is never moved back. A refund Stripe fails after reporting it succeeded is reported to
 *   admins and the row is left as it is.
 *
 * Nested inside the reconcile job's own payment-locked transaction it reuses that lock (Postgres row locks are
 * re-entrant within one transaction). Never calls the gateway, never writes the ledger.
 */
class SettleGatewayRefund
{
    /** The gateway statuses that mean the refund will never reach the card. */
    private const FAILED = ['failed', 'canceled'];

    /**
     * @return PaymentRefundStatus the row's status afterwards
     */
    public function __invoke(PaymentRefund $row, string $gatewayStatus, ?string $gatewayRefundId = null): PaymentRefundStatus
    {
        $alerts = [];

        $status = DB::transaction(function () use ($row, $gatewayStatus, $gatewayRefundId, &$alerts): PaymentRefundStatus {
            $locked = Payment::query()->whereKey($row->payment_id)->lockForUpdate()->firstOrFail();
            $fresh = PaymentRefund::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            $gatewayStatus = mb_substr($gatewayStatus, 0, 32);

            if ($fresh->status !== PaymentRefundStatus::Requested) {
                // Stripe can fail a refund it reported succeeded: the row is not reverted, admins are told.
                if ($fresh->status === PaymentRefundStatus::Succeeded && in_array($gatewayStatus, self::FAILED, true)) {
                    $alerts[] = PaymentNeedsAttention::refundFailedFor($fresh->kind);
                }

                return $fresh->status;
            }

            $updates = ['gateway_status' => $gatewayStatus];

            if ($gatewayRefundId !== null && $fresh->gateway_refund_id === null) {
                // A reconcile that ran before this finished may have taken our own refund for someone else's.
                PaymentRefund::query()
                    ->where('payment_id', $locked->id)
                    ->where('gateway_refund_id', $gatewayRefundId)
                    ->where('kind', PaymentRefundKind::External->value)
                    ->whereKeyNot($fresh->id)
                    ->delete();

                $updates['gateway_refund_id'] = $gatewayRefundId;
            }

            if (in_array($gatewayStatus, self::FAILED, true)) {
                $fresh->update($updates + [
                    'status' => PaymentRefundStatus::Failed->value,
                    'failure_reason' => 'refund_'.$gatewayStatus,
                    'completed_at' => now(),
                ]);
                $alerts[] = PaymentNeedsAttention::refundFailedFor($fresh->kind);

                return PaymentRefundStatus::Failed;
            }

            if ($gatewayStatus !== 'succeeded') {
                $fresh->update($updates);

                return PaymentRefundStatus::Requested;
            }

            $fresh->update($updates + [
                'status' => PaymentRefundStatus::Succeeded->value,
                'failure_reason' => null,
                'completed_at' => now(),
            ]);

            // A refund made at the gateway by someone else advances only a payment the platform holds: the
            // `charge.refunded` webhook decides what any other payment shows (it keeps a capture the platform never held
            // visible to `ledger:verify`). On a held one it counts with the rest, so a dashboard refund that
            // succeeds after a platform refund is not lost from `refunded_amount`.
            if ($fresh->kind !== PaymentRefundKind::External || $this->isHeld($locked)) {
                $this->advance($locked, $fresh);
            }

            if ($fresh->kind === PaymentRefundKind::Unverified) {
                $alerts[] = PaymentNeedsAttention::UNVERIFIED_CAPTURE_REFUNDED;
            }

            return PaymentRefundStatus::Succeeded;
        });

        if ($alerts !== []) {
            $payment = Payment::query()->find($row->payment_id);

            foreach ($alerts as $reason) {
                if ($payment !== null) {
                    PaymentNeedsAttention::dispatch($payment, $reason);
                }
            }
        }

        return $status;
    }

    private function isHeld(Payment $payment): bool
    {
        return DB::table('ledger_entries')->where('lesson_id', $payment->lesson_id)->where('type', LedgerEntryType::Hold->value)->exists();
    }

    /**
     * Never downgrades the payment, and never lowers `refunded_amount` (a webhook may have set it from the
     * gateway's own total). A refund of a capture the platform never held closes the payment.
     */
    private function advance(Payment $locked, PaymentRefund $row): void
    {
        $paid = $locked->amount->toFils();
        $confirmed = (int) PaymentRefund::query()
            ->where('payment_id', $locked->id)
            ->where('status', PaymentRefundStatus::Succeeded->value)
            ->sum('amount');

        if ($row->kind === PaymentRefundKind::Unverified) {
            $received = $locked->raw_response['amount_received'] ?? null;
            $confirmed = max($confirmed, is_int($received) ? $received : $paid);
        }

        $refunded = Money::fils(min($confirmed, $paid));

        if ($locked->refunded_amount !== null && $locked->refunded_amount->compare($refunded) > 0) {
            $refunded = $locked->refunded_amount;
        }

        $status = $locked->status;

        if ($row->kind === PaymentRefundKind::Unverified || $refunded->compare($locked->amount) >= 0) {
            $status = PaymentStatus::Refunded;
        } elseif ($locked->status === PaymentStatus::Captured) {
            $status = PaymentStatus::PartiallyRefunded;
        }

        // The payment's status, written with `update()` like `LessonSettlement::refundParent` and `CancelLesson` do.
        $locked->update(['status' => $status, 'refunded_amount' => $refunded]);
    }
}
