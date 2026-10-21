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
 * `external` row. A `requested` row (the platform's own, or an external one recorded while Stripe reported it `pending`)
 * is settled by what the gateway lists now (25b, R267). Never writes the ledger. Safe to run twice: `(payment_id, gateway_refund_id)` is unique.
 *
 * The gateway is asked with no lock held; the rows are inserted under the payment's lock, so a refund the platform
 * is finishing at the same moment (`MakeGatewayRefund` locks the payment too) is never taken for someone else's.
 */
class RecordExternalRefunds
{
    public function __construct(private readonly PaymentGatewayManager $gateways, private readonly SettleGatewayRefund $settle) {}

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

            $rows = PaymentRefund::query()->where('payment_id', $payment->id)->get();
            $recorded = 0;

            foreach ($listed as $refund) {
                if ($refund['amount'] <= 0) {
                    continue;
                }

                // A refund the platform (or an earlier reconcile) already has a row for: the gateway's word on it
                // settles a `requested` row (25b), and fills in an id the row was waiting for.
                $known = $rows->first(fn (PaymentRefund $row) => $row->gateway_refund_id === $refund['id']
                    || ($refund['idempotency_key'] !== null && $row->idempotency_key === $refund['idempotency_key']));

                if ($known !== null) {
                    if ($known->status === PaymentRefundStatus::Requested) {
                        ($this->settle)($known, $refund['status'], $refund['id']);
                    }

                    continue;
                }

                if (in_array($refund['status'], ['failed', 'canceled'], true)) {
                    continue;
                }

                $at = now()->setTimestamp($refund['created'] > 0 ? $refund['created'] : now()->getTimestamp());
                $done = $refund['status'] === 'succeeded';

                // A refund Stripe reports `pending` is not money returned yet: it is recorded `requested` and settled
                // by a later reconcile or `refund.updated` (25b, R267).
                $recorded += DB::table('payment_refunds')->insertOrIgnore([
                    'payment_id' => $payment->id,
                    'ledger_entry_id' => null,
                    'kind' => PaymentRefundKind::External->value,
                    'attempt' => 1,
                    'idempotency_key' => null,
                    'amount' => $refund['amount'],
                    'gateway_refund_id' => $refund['id'],
                    'gateway_status' => mb_substr($refund['status'], 0, 32),
                    'status' => ($done ? PaymentRefundStatus::Succeeded : PaymentRefundStatus::Requested)->value,
                    'requested_at' => $at,
                    'completed_at' => $done ? $at : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $recorded;
        });
    }
}
