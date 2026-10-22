<?php

namespace App\Listeners\Payments;

use App\Actions\Payments\RefundUnverifiedCapture;
use App\Events\Payments\PaymentNeedsAttention;
use App\Events\Payments\UnverifiedCaptureNeedsRefund;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * R259 (2): same retry shape as `RefundParentAtGatewayListener`. A transient failure is retried by the
 * queue; when the tries run out admins are told and `payments:retry-refunds` tries again.
 */
class RefundUnverifiedCaptureListener implements ShouldQueue
{
    public int $tries = 5;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(UnverifiedCaptureNeedsRefund $event): void
    {
        app(RefundUnverifiedCapture::class)($event->payment);
    }

    public function failed(UnverifiedCaptureNeedsRefund $event, Throwable $e): void
    {
        PaymentNeedsAttention::dispatch($event->payment, PaymentNeedsAttention::UNVERIFIED_REFUND_FAILED);
    }
}
