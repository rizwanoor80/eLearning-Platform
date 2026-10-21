<?php

namespace App\Actions\Payments;

use App\Enums\PaymentRefundKind;
use App\Enums\PaymentRefundStatus;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\StripePaymentGateway;
use Illuminate\Support\Facades\DB;

/**
 * 24b (R260): lists the gateway's refunds for a payment, takes the ones the platform did not make (a refund the
 * platform made carries its idempotency key as metadata, and has a row with that key or id) and records each as an
 * `external` row. Never writes the ledger. Safe to run twice: `(payment_id, gateway_refund_id)` is unique.
 *
 * The gateway is asked with no lock held; the rows are inserted under the payment's lock, so a refund the platform
 * is finishing at the same moment (`MakeGatewayRefund` locks the payment too) is never taken for someone else's.
 */
class RecordExternalRefunds
{
    public function __construct(private readonly PaymentGatewayManager $gateways) {}

    /**
     * @return int|null how many new external refunds were recorded; null when the driver cannot list its refunds
     */
    public function __invoke(Payment $payment): ?int
    {
        $gateway = $this->gateways->forCode($payment->gateway);

        if (! $gateway instanceof StripePaymentGateway) {
            return null;
        }

        $listed = $gateway->listRefunds($payment);

        return DB::transaction(function () use ($payment, $listed): int {
            Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $ownIds = PaymentRefund::query()->where('payment_id', $payment->id)->whereNotNull('gateway_refund_id')->pluck('gateway_refund_id')->all();
            $ownKeys = PaymentRefund::query()->where('payment_id', $payment->id)->whereNotNull('idempotency_key')->pluck('idempotency_key')->all();
            $recorded = 0;

            foreach ($listed as $refund) {
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

            return $recorded;
        });
    }
}
