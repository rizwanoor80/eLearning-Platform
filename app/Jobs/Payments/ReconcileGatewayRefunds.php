<?php

namespace App\Jobs\Payments;

use App\Enums\PaymentRefundKind;
use App\Enums\PaymentRefundStatus;
use App\Events\Payments\PaymentNeedsAttention;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\StripePaymentGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
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

    public function handle(PaymentGatewayManager $gateways): void
    {
        $payment = Payment::query()->find($this->paymentId);

        if ($payment === null) {
            return;
        }

        $gateway = $gateways->forCode($payment->gateway);

        // A driver that cannot list its refunds cannot tell whose they are: say so, as the webhook always did.
        if (! $gateway instanceof StripePaymentGateway) {
            PaymentNeedsAttention::dispatch($payment, PaymentNeedsAttention::REFUNDED_AT_GATEWAY);

            return;
        }

        $ownIds = PaymentRefund::query()->where('payment_id', $payment->id)->whereNotNull('gateway_refund_id')->pluck('gateway_refund_id')->all();
        $ownKeys = PaymentRefund::query()->where('payment_id', $payment->id)->whereNotNull('idempotency_key')->pluck('idempotency_key')->all();
        $recorded = 0;

        foreach ($gateway->listRefunds($payment) as $refund) {
            if (in_array($refund['status'], ['failed', 'canceled'], true) || $refund['amount'] <= 0) {
                continue;
            }

            if (in_array($refund['id'], $ownIds, true) || ($refund['idempotency_key'] !== null && in_array($refund['idempotency_key'], $ownKeys, true))) {
                continue;
            }

            $at = now()->setTimestamp($refund['created'] > 0 ? $refund['created'] : now()->getTimestamp());

            $recorded += DB::table('payment_refunds')->insertOrIgnore([
                'payment_id' => $payment->id,
                'ledger_entry_id' => null,
                'kind' => PaymentRefundKind::External->value,
                'attempt' => 1,
                'idempotency_key' => null,
                'amount' => $refund['amount'],
                'gateway_refund_id' => $refund['id'],
                'gateway_status' => mb_substr($refund['status'], 0, 32),
                'status' => PaymentRefundStatus::Succeeded->value,
                'requested_at' => $at,
                'completed_at' => $at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($recorded > 0) {
            PaymentNeedsAttention::dispatch($payment, PaymentNeedsAttention::REFUNDED_AT_GATEWAY);
        }
    }

    public function failed(Throwable $e): void
    {
        $payment = Payment::query()->find($this->paymentId);

        if ($payment !== null) {
            PaymentNeedsAttention::dispatch($payment, PaymentNeedsAttention::REFUNDED_AT_GATEWAY);
        }
    }
}
