<?php

namespace App\Jobs\Payments;

use App\Actions\Payments\RecordExternalRefunds;
use App\Events\Payments\PaymentNeedsAttention;
use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * 24b (R260): a `charge.refunded` webhook reported more refunded at the gateway than the platform's own
 * refunds explain. This lists the gateway's refunds (the webhook's locked action never calls Stripe, so the
 * call is made here, queued and after the commit), takes the ones the platform did not make (a refund the
 * platform made carries its idempotency key as metadata, and has a row with that key) and records each as an
 * `external` row, then tells admins. It never writes the ledger: whether money the gateway returned should
 * change a lesson's books is an admin's decision. Running twice is a no-op, because `(payment_id,
 * gateway_refund_id)` is unique.
 */
class ReconcileGatewayRefunds implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly int $paymentId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(RecordExternalRefunds $record): void
    {
        $payment = Payment::query()->find($this->paymentId);

        if ($payment === null) {
            return;
        }

        $recorded = $record($payment);

        // A driver that cannot list its refunds cannot tell whose they are: say so, as the webhook always did.
        // Otherwise only a refund recorded just now is news (R277: the mail says the gateway refunded money, so it
        // is sent only where the payment shows a refunded amount).
        if ($recorded === null || $recorded > 0) {
            $this->tell($payment);
        }
    }

    public function failed(Throwable $e): void
    {
        $payment = Payment::query()->find($this->paymentId);

        if ($payment !== null) {
            $this->tell($payment);
        }
    }

    /** R277: "the gateway has refunded X" is only true where the payment carries a refunded amount above zero. */
    private function tell(Payment $payment): void
    {
        $refunded = $payment->refresh()->refunded_amount;

        if ($refunded !== null && $refunded->toFils() > 0) {
            PaymentNeedsAttention::dispatch($payment, PaymentNeedsAttention::REFUNDED_AT_GATEWAY);
        }
    }
}
