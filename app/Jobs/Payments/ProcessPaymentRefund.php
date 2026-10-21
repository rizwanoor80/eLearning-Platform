<?php

namespace App\Jobs\Payments;

use App\Actions\Payments\MakeGatewayRefund;
use App\Enums\PaymentRefundKind;
use App\Enums\PaymentRefundStatus;
use App\Events\Payments\PaymentNeedsAttention;
use App\Models\PaymentRefund;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * 24b (R260): carries out one `requested` refund attempt an admin re-queued. Retries keep the attempt's own key, so a
 * retry after a crash finds the refund the gateway already made. The hourly sweep is the net under it: a `requested`
 * attempt this job lost is retried there once it is older than the grace period.
 */
class ProcessPaymentRefund implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $refundId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(MakeGatewayRefund $makeRefund): void
    {
        $row = PaymentRefund::query()->with('payment')->find($this->refundId);

        if ($row === null || $row->status !== PaymentRefundStatus::Requested) {
            return;
        }

        if ($makeRefund($row) && $row->kind === PaymentRefundKind::Unverified) {
            PaymentNeedsAttention::dispatch($row->payment, PaymentNeedsAttention::UNVERIFIED_CAPTURE_REFUNDED);
        }
    }

    public function failed(Throwable $e): void
    {
        $payment = PaymentRefund::query()->with('payment')->find($this->refundId)?->payment;

        if ($payment !== null) {
            PaymentNeedsAttention::dispatch($payment, PaymentNeedsAttention::REFUND_FAILED);
        }
    }
}
