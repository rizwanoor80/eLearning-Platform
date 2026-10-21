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
 * - `refund.updated` (and the older `charge.refund.updated`, whose object is the same refund): the gateway's word on
 *   one refund (25b, R267). It settles the platform's own `requested` row through `SettleGatewayRefund` (found by the
 *   idempotency key in the refund's metadata first, since the webhook can arrive before the refund id is stored, then by
 *   id). A refund no row explains queues `ReconcileGatewayRefunds`. `charge.refunded` never settles a row: it cannot say
 *   which refund moved. While any row is `requested` it only queues the reconcile, and the platform's own open refunds
 *   are taken out of the total it records, because the gateway counts a `pending` refund as refunded.
 * - `charge.dispute.created`: admins are told. No state changes.
 * - Anything else is ignored by the controller before it is stored.
 */
class ApplyPaymentWebhook
{
    public const HANDLED = [
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'charge.refunded',
        'refund.updated',
        'charge.refund.updated',
        'charge.dispute.created',
    ];

    public function __construct(private readonly SettleGatewayRefund $settle) {}

    /**
     * @param  array<string, mixed>  $object  the event's `data.object`
     */
    public function __invoke(string $gatewayCode, string $type, array $object): void
    {
        if ($type === 'payment_intent.succeeded') {
            $this->checkForOrphan($gatewayCode, $object);

            return;
        }

        if (! in_array($type, ['charge.refunded', 'charge.dispute.created', 'refund.updated', 'charge.refund.updated'], true)) {
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

        if ($type !== 'charge.refunded') {
            $this->applyRefundUpdate($payment, $object);

            return;
        }

        $refunded = $object['amount_refunded'] ?? null;

        if (! is_int($refunded) || $refunded <= 0) {
            return;
        }

        $open = PaymentRefund::query()->where('payment_id', $payment->id)->where('status', PaymentRefundStatus::Requested->value);
        $hasOpen = $open->clone()->exists();

        // The gateway counts a refund it reports `pending` in the charge's total. The platform's own open refunds are
        // taken out (all of them, whether or not the refund id is stored yet: counting too low is safe, because the
        // amount is never lowered and a later event raises it, and counting too high is not), floored at what is
        // confirmed. A pending refund made in the Stripe dashboard still counts until the reconcile records it.
        $pending = (int) $open->clone()->where('kind', '<>', PaymentRefundKind::External->value)->sum('amount');
        $confirmed = (int) PaymentRefund::query()->where('payment_id', $payment->id)->where('status', PaymentRefundStatus::Succeeded->value)->sum('amount');
        $new = Money::fils(min(max($refunded - $pending, $confirmed), $payment->amount->toFils()));

        if ($new->toFils() <= 0 || ($payment->refunded_amount !== null && $new->compare($payment->refunded_amount) <= 0)) {
            if ($hasOpen) {
                dispatch(new ReconcileGatewayRefunds($payment->id))->afterCommit();
            }

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

        if ($hasOpen || ($refunded > $ledgerRefunded && ! $ownRefund)) {
            // 24b (R260): which refund is not the platform's is for the gateway to say, and this action holds the
            // payment's lock, so the lookup is a queued job that runs once this has committed.
            dispatch(new ReconcileGatewayRefunds($payment->id))->afterCommit();
        }
    }

    /**
     * 25b (R267): `$object` is the refund itself. Its own status is the word on one refund.
     *
     * @param  array<string, mixed>  $object
     */
    private function applyRefundUpdate(Payment $payment, array $object): void
    {
        $id = is_string($object['id'] ?? null) && $object['id'] !== '' ? $object['id'] : null;
        $status = is_string($object['status'] ?? null) ? $object['status'] : null;
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $key = is_string($metadata['idempotency_key'] ?? null) && $metadata['idempotency_key'] !== '' ? $metadata['idempotency_key'] : null;

        if ($id === null || $status === null) {
            return;
        }

        $rows = PaymentRefund::query()->where('payment_id', $payment->id);
        $row = ($key !== null ? $rows->clone()->where('idempotency_key', $key)->first() : null)
            ?? $rows->clone()->where('gateway_refund_id', $id)->first();

        if ($row !== null) {
            ($this->settle)($row, $status, $id);

            return;
        }

        // A refund no row explains (made in the Stripe dashboard): the reconcile lists the gateway's refunds and records it.
        if (! in_array($status, ['failed', 'canceled'], true)) {
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
