<?php

namespace App\Actions\Payments;

use App\Enums\PaymentRefundStatus;
use App\Events\Payments\PaymentNeedsAttention;
use App\Exceptions\PaymentCaptureException;
use App\Models\PaymentRefund;
use App\Services\Payments\PaymentGatewayManager;

/**
 * 24b (R260): carries out one `requested` `payment_refunds` row. The row was written before this runs, so a
 * crash between the gateway's answer and the save leaves a row that says what was asked; the retry asks again
 * with the same key and the gateway answers with the refund it already made.
 *
 * The gateway is called with no lock held. The payment is locked only by `SettleGatewayRefund`, to finish the row
 * and advance `refunded_amount` / `status`, so a webhook for the same payment never waits on the gateway.
 * A refund the gateway reports `pending` stays `requested` (25b, R267) and is settled later. A refusal
 * (`PaymentCaptureException`) is permanent: the row is `failed`, admins are told, and only an admin's re-queue
 * (a new attempt) asks again. Any other failure propagates so the queue retries. The ledger is never touched.
 */
class MakeGatewayRefund
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly SettleGatewayRefund $settle,
    ) {}

    /**
     * @return bool whether the refund is made (the gateway reported it succeeded); false for a refusal and for one still `pending`
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
            $failed = PaymentRefund::query()->whereKey($row->id)->where('status', PaymentRefundStatus::Requested->value)->update([
                'status' => PaymentRefundStatus::Failed->value,
                'failure_reason' => mb_substr($e->getMessage(), 0, 500),
                'completed_at' => now(),
            ]);

            // Told once: a webhook that already failed the row through `SettleGatewayRefund` has raised the alert.
            if ($failed === 1) {
                PaymentNeedsAttention::dispatch($payment->fresh() ?? $payment, PaymentNeedsAttention::refundFailedFor($row->kind));
            }

            return false;
        }

        // The gateway's own word on the refund: `pending` is not money returned yet (25b, R267), so the row stays
        // `requested` until the webhook, the reconcile job or the next sweep sees it succeed or fail.
        return ($this->settle)($row, (string) ($result->rawResponse['status'] ?? 'succeeded'), $result->gatewayRef) === PaymentRefundStatus::Succeeded;
    }
}
