<?php

namespace App\Actions\Payments;

use App\Enums\PaymentRefundKind;
use App\Enums\PaymentRefundStatus;
use App\Enums\PaymentStatus;
use App\Events\Payments\PaymentNeedsAttention;
use App\Models\Payment;
use App\Models\PaymentRefund;
use Illuminate\Support\Facades\DB;

/**
 * R259 (2): a booking whose payment the gateway captured but which could not be confirmed (the capture
 * came back unclean, or the hold could not be written) leaves a `captured` payment with no ledger entry
 * at all. The platform never held that money, so there is nothing for the ledger to reverse
 * (`LedgerService` is untouched): the parent's card is refunded at the gateway and the payment row
 * records it, which also takes it off `ledger:verify`'s stranded list. Admins are told once it is done,
 * or when the gateway refuses.
 *
 * 24b (R260): the state is a `payment_refunds` row (kind `unverified`), written `requested` before the
 * gateway is asked and finished after it answers; the payment is never set `refunded` first. The refund key
 * is `unverified-{gateway_ref}`, so the queue, the hourly sweep and an admin cannot refund twice, and the
 * row tells the `charge.refunded` webhook this refund is the platform's own. A refusal is a `failed` row,
 * permanent and listed to admins (an admin re-queues it as a new attempt); any other failure propagates so
 * the queue retries the `requested` row.
 */
class RefundUnverifiedCapture
{
    public function __construct(private readonly MakeGatewayRefund $makeRefund) {}

    /**
     * @return bool whether this call made the refund
     */
    public function __invoke(Payment $payment): bool
    {
        $row = DB::transaction(function () use ($payment): ?PaymentRefund {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->first();

            if ($locked === null || $locked->gateway_ref === null || $locked->status !== PaymentStatus::Captured) {
                return null;
            }

            // Any ledger entry means the platform holds (or held) this money and its own rules refund it.
            if (DB::table('ledger_entries')->where('lesson_id', $locked->lesson_id)->exists()) {
                return null;
            }

            $latest = PaymentRefund::query()->where('payment_id', $locked->id)->where('kind', PaymentRefundKind::Unverified->value)->orderByDesc('attempt')->first();

            if ($latest !== null) {
                return $latest->status === PaymentRefundStatus::Requested ? $latest : null;
            }

            $received = $locked->raw_response['amount_received'] ?? null;
            $fils = (is_int($received) ? $received : $locked->amount->toFils()) - ($locked->refunded_amount?->toFils() ?? 0);

            if ($fils <= 0) {
                // Already refunded in full (the dashboard, or a run that died after Stripe answered): the
                // payment is closed so it is not swept again and `strandedPayments()` drops it.
                $locked->update(['status' => PaymentStatus::Refunded]);

                return null;
            }

            return PaymentRefund::query()->create([
                'payment_id' => $locked->id,
                'kind' => PaymentRefundKind::Unverified,
                'attempt' => 1,
                'idempotency_key' => "unverified-{$locked->gateway_ref}",
                'amount' => $fils,
                'status' => PaymentRefundStatus::Requested,
                'requested_at' => now(),
            ]);
        });

        if ($row === null || ! ($this->makeRefund)($row)) {
            return false;
        }

        PaymentNeedsAttention::dispatch($payment->fresh() ?? $payment, PaymentNeedsAttention::UNVERIFIED_CAPTURE_REFUNDED);

        return true;
    }
}
