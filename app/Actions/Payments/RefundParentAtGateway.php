<?php

namespace App\Actions\Payments;

use App\Enums\LedgerAccount;
use App\Enums\PaymentStatus;
use App\Events\Payments\PaymentNeedsAttention;
use App\Exceptions\PaymentCaptureException;
use App\Models\LedgerEntry;
use App\Models\Lesson;
use App\Models\Payment;
use App\Services\Payments\NoActivePaymentGateway;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * 23d (R256): money the ledger has already returned to the parent is returned to the parent's card.
 * The ledger is the source of truth and is never touched here (invariant 1); this only makes the
 * payment gateway agree with it. Each `Refund`-account ledger entry of the lesson is one gateway
 * refund, for that entry's own amount, keyed `refund-{gateway_ref}-{entry id}` — the same entry can
 * therefore never refund twice, whether it is the queue retrying, the hourly sweep, or an admin
 * re-running a failed job (the Stripe driver also looks the key up on Stripe's side, since Stripe
 * forgets an idempotency key after 24 hours).
 *
 * What was confirmed is kept in `payments.raw_response`, no new table (R252 authorises none):
 * `refunds.{entry id}` = `{id, amount_fils, at}` for a refund the gateway made, and
 * `refund_failures.{entry id}` = `{code, at}` for one it refused. A refusal is permanent, is listed to
 * admins and is left alone by the queue and the sweep; any other failure propagates so the queue
 * retries it. A failed refund never reverses the ledger.
 *
 * The gateway is called with no lock held; the payment row is locked only to record the outcome,
 * so a webhook for the same payment never waits on Stripe.
 */
class RefundParentAtGateway
{
    public function __construct(private readonly PaymentGatewayManager $gateways) {}

    /**
     * @return int how many gateway refunds were made by this call
     */
    public function __invoke(Lesson $lesson): int
    {
        $payment = $lesson->payment()->first();

        if ($payment === null || $payment->gateway_ref === null || ! in_array($payment->status, [PaymentStatus::Captured, PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded], true)) {
            return 0;
        }

        $made = 0;

        foreach ($this->owed($lesson, $payment) as $entry) {
            if ($this->refundEntry($payment, $entry)) {
                $made++;
            }
        }

        return $made;
    }

    /**
     * The lesson's refund entries that neither succeeded nor were refused yet.
     *
     * @return list<LedgerEntry>
     */
    public function owed(Lesson $lesson, Payment $payment): array
    {
        $raw = $payment->fresh()->raw_response ?? [];

        return array_values(LedgerEntry::query()
            ->where('lesson_id', $lesson->id)
            ->where('account', LedgerAccount::Refund->value)
            ->where('amount', '>', 0)
            ->orderBy('id')
            ->get()
            ->reject(fn (LedgerEntry $e): bool => isset($raw['refunds'][$e->id]) || isset($raw['refund_failures'][$e->id]))
            ->all());
    }

    private function refundEntry(Payment $payment, LedgerEntry $entry): bool
    {
        $amount = Money::fils(min($entry->amount, $payment->amount->toFils()));

        try {
            $result = $this->gateways->forCode($payment->gateway)->refund($payment, $amount, "refund-{$payment->gateway_ref}-{$entry->id}");
        } catch (PaymentCaptureException|NoActivePaymentGateway $e) {
            $this->recordFailure($payment, $entry, $e instanceof PaymentCaptureException ? $e->getMessage() : 'gateway_unavailable');

            return false;
        }

        DB::transaction(function () use ($payment, $entry, $amount, $result): void {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $raw = $locked->raw_response ?? [];
            $raw['refunds'][$entry->id] = ['id' => $result->gatewayRef, 'amount_fils' => $amount->toFils(), 'at' => now()->toIso8601String()];
            unset($raw['refund_failures'][$entry->id]);

            if (($raw['refund_failures'] ?? null) === []) {
                unset($raw['refund_failures']);
            }

            $confirmed = array_sum(array_column($raw['refunds'], 'amount_fils'));
            $refunded = Money::fils(min($confirmed, $locked->amount->toFils()));

            if ($locked->refunded_amount === null || $refunded->compare($locked->refunded_amount) > 0) {
                $locked->refunded_amount = $refunded;
            }

            // Never downgrade: the eager callers already set Refunded for a full refund. This is the payment's
            // status; written with `update()` like `LessonSettlement::refundParent` and `CancelLesson` do.
            $status = $locked->status;

            if ($locked->refunded_amount->compare($locked->amount) >= 0) {
                $status = PaymentStatus::Refunded;
            } elseif ($locked->status === PaymentStatus::Captured) {
                $status = PaymentStatus::PartiallyRefunded;
            }

            $locked->update(['status' => $status, 'raw_response' => $raw]);
        });

        return true;
    }

    private function recordFailure(Payment $payment, LedgerEntry $entry, string $code): void
    {
        DB::transaction(function () use ($payment, $entry, $code): void {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $raw = $locked->raw_response ?? [];
            $raw['refund_failures'][$entry->id] = ['code' => $code, 'at' => now()->toIso8601String()];
            $locked->raw_response = $raw;
            $locked->save();
        });

        PaymentNeedsAttention::dispatch($payment->fresh() ?? $payment, PaymentNeedsAttention::REFUND_FAILED);
    }
}
