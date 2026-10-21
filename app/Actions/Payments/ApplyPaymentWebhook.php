<?php

namespace App\Actions\Payments;

use App\Enums\LedgerAccount;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentRefundKind;
use App\Enums\PaymentRefundStatus;
use App\Enums\PaymentStatus;
use App\Events\Payments\PaymentNeedsAttention;
use App\Jobs\Payments\CheckOrphanCapture;
use App\Jobs\Payments\ReconcileGatewayRefunds;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * 23b (R247): what a verified, first-seen gateway event does here. Deliberately little: the lesson
 * state machine and the ledger are never touched from a webhook (invariants 1 and 2).
 *
 * - `payment_intent.succeeded` / `payment_intent.payment_failed`: recorded only. Booking and the weekly
 *   charge already decided from the API response; a webhook that disagrees changes nothing. A success for
 *   an intent no payment carries is checked again later and, if still unknown, reported (R259 (4)).
 * - `charge.refunded`: the charge's total refunded amount is absolute, so the payment's
 *   `refunded_amount` is set to it only when it is larger (never lowered, so a late or out-of-order
 *   event cannot undo a newer one), and admins are told that the gateway moved money the platform did
 *   not. A refund the platform made itself (23d) is already recorded and so changes nothing here; one
 *   that only the ledger has recorded so far (the gateway call is queued) is recorded on the payment
 *   but not reported. A total larger than the ledger explains queues `ReconcileGatewayRefunds` (24b), which
 *   lists the gateway's refunds, records the ones the platform did not make and tells admins.
 * - `charge.dispute.created`: admins are told. No state changes.
 * - Anything else is ignored by the controller before it is stored.
 */
class ApplyPaymentWebhook
{
    public const HANDLED = [
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'charge.refunded',
        'charge.dispute.created',
    ];

    /**
     * @param  array<string, mixed>  $object  the event's `data.object`
     */
    public function __invoke(string $gatewayCode, string $type, array $object): void
    {
        if ($type === 'payment_intent.succeeded') {
            $this->checkForOrphan($gatewayCode, $object);

            return;
        }

        if ($type !== 'charge.refunded' && $type !== 'charge.dispute.created') {
            return;
        }

        $intentId = $object['payment_intent'] ?? null;

        $payment = is_string($intentId) && $intentId !== ''
            ? Payment::query()->where('gateway', $gatewayCode)->where('gateway_ref', $intentId)->lockForUpdate()->first()
            : null;

        if ($payment === null) {
            return;
        }

        if ($type === 'charge.dispute.created') {
            PaymentNeedsAttention::dispatch($payment, PaymentNeedsAttention::DISPUTED_AT_GATEWAY);

            return;
        }

        $refunded = $object['amount_refunded'] ?? null;

        if (! is_int($refunded) || $refunded <= 0) {
            return;
        }

        $new = Money::fils(min($refunded, $payment->amount->toFils()));

        if ($payment->refunded_amount !== null && $new->compare($payment->refunded_amount) <= 0) {
            return;
        }

        $payment->refunded_amount = $new;

        // A captured payment with no hold is a stranded one (`LedgerService::strandedPayments()`); moving
        // its status would hide it from `ledger:verify` while the platform still holds the money. The
        // refunded amount is recorded either way, and admins are told below.
        $held = DB::table('ledger_entries')->where('lesson_id', $payment->lesson_id)->where('type', LedgerEntryType::Hold->value)->exists();

        if ($held) {
            $payment->status = $new->equals($payment->amount) ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded;
        }

        $payment->save();

        // A refund the ledger already returned to the parent is the platform's own (23d), arriving back
        // as a webhook before or after the listener recorded it: recorded above, but nothing to tell anyone.
        $ledgerRefunded = (int) DB::table('ledger_entries')
            ->where('lesson_id', $payment->lesson_id)
            ->where('account', LedgerAccount::Refund->value)
            ->where('amount', '>', 0)
            ->sum('amount');

        // R259 (2): a refund the platform made itself for an unverified capture is already reported.
        $ownRefund = PaymentRefund::query()->where('payment_id', $payment->id)->where('kind', PaymentRefundKind::Unverified->value)->where('status', '<>', PaymentRefundStatus::Failed->value)->exists();

        if ($new->toFils() > $ledgerRefunded && ! $ownRefund) {
            // 24b (R260): which refund is not the platform's is for the gateway to say, and this action holds the
            // payment's lock, so the lookup is a queued job that runs once this has committed.
            dispatch(new ReconcileGatewayRefunds($payment->id))->afterCommit();
        }
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function checkForOrphan(string $gatewayCode, array $object): void
    {
        $intentId = $object['id'] ?? null;
        $amount = $object['amount_received'] ?? $object['amount'] ?? null;

        if (! is_string($intentId) || $intentId === '' || ! is_int($amount)) {
            return;
        }

        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $lessonId = ctype_digit((string) ($metadata['lesson_id'] ?? '')) ? (int) $metadata['lesson_id'] : null;
        $attempt = ctype_digit((string) ($metadata['attempt'] ?? '')) ? (int) $metadata['attempt'] : null;

        $job = new CheckOrphanCapture($gatewayCode, $intentId, $amount, strtoupper((string) ($object['currency'] ?? 'aed')), $lessonId, $attempt);

        if ($job->known()) {
            return;
        }

        dispatch($job)
            ->delay(now()->addMinutes(CheckOrphanCapture::WAIT_MINUTES))
            ->afterCommit();
    }
}
