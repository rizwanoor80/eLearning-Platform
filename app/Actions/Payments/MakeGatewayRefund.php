<?php

namespace App\Actions\Payments;

use App\Enums\PaymentRefundKind;
use App\Enums\PaymentRefundStatus;
use App\Enums\PaymentStatus;
use App\Events\Payments\PaymentNeedsAttention;
use App\Exceptions\PaymentCaptureException;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * 24b (R260): carries out one `requested` `payment_refunds` row. The row was written before this runs, so a
 * crash between the gateway's answer and the save leaves a row that says what was asked; the retry asks again
 * with the same key and the gateway answers with the refund it already made.
 *
 * The gateway is called with no lock held. The payment is locked only to finish the row and advance
 * `refunded_amount` / `status`, so a webhook for the same payment never waits on the gateway. A refusal
 * (`PaymentCaptureException`) is permanent: the row is `failed`, admins are told, and only an admin's re-queue
 * (a new attempt) asks again. Any other failure propagates so the queue retries. The ledger is never touched.
 */
class MakeGatewayRefund
{
    public function __construct(private readonly PaymentGatewayManager $gateways) {}

    /**
     * @return bool whether the gateway made the refund
     */
    public function __invoke(PaymentRefund $row): bool
    {
        if ($row->status !== PaymentRefundStatus::Requested || $row->idempotency_key === null) {
            return false;
        }

        $payment = $row->payment()->firstOrFail();

        try {
            $result = $this->gateways->forCode($payment->gateway)->refund($payment, $row->amount, $row->idempotency_key);
        } catch (PaymentCaptureException $e) {
            PaymentRefund::query()->whereKey($row->id)->where('status', PaymentRefundStatus::Requested->value)->update([
                'status' => PaymentRefundStatus::Failed->value,
                'failure_reason' => mb_substr($e->getMessage(), 0, 500),
                'completed_at' => now(),
            ]);
            PaymentNeedsAttention::dispatch($payment->fresh() ?? $payment, PaymentNeedsAttention::REFUND_FAILED);

            return false;
        }

        DB::transaction(function () use ($row, $result): void {
            $locked = Payment::query()->whereKey($row->payment_id)->lockForUpdate()->firstOrFail();

            // A reconcile that ran before this finished may have taken our own refund for someone else's.
            PaymentRefund::query()
                ->where('payment_id', $locked->id)
                ->where('gateway_refund_id', $result->gatewayRef)
                ->where('kind', PaymentRefundKind::External->value)
                ->delete();

            $row->update([
                'status' => PaymentRefundStatus::Succeeded->value,
                'gateway_refund_id' => $result->gatewayRef,
                'gateway_status' => isset($result->rawResponse['status']) ? mb_substr((string) $result->rawResponse['status'], 0, 32) : null,
                'failure_reason' => null,
                'completed_at' => now(),
            ]);

            $this->advance($locked, $row);
        });

        return true;
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
