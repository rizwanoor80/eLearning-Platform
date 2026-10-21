<?php

namespace App\Actions\Payments;

use App\Enums\LedgerAccount;
use App\Enums\PaymentRefundKind;
use App\Enums\PaymentRefundStatus;
use App\Enums\PaymentStatus;
use App\Models\LedgerEntry;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentRefund;
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
 * 24b (R260): what was asked and answered is a `payment_refunds` row per attempt (`kind` = ledger), written
 * `requested` before the gateway is called, so the state can be queried, locked and constrained, and a
 * partial unique index lets only one live attempt exist per entry. A refusal is a `failed` row: permanent,
 * listed to admins and left alone by the queue and the sweep until an admin re-queues it as a new attempt
 * (`RequeueFailedRefund`). A `requested` row the queue or the sweep finds is retried with its own key. A
 * failed refund never reverses the ledger.
 */
class RefundParentAtGateway
{
    public function __construct(private readonly MakeGatewayRefund $makeRefund) {}

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

        foreach ($this->owed($lesson) as $entry) {
            $row = $this->requestFor($payment, $entry);

            if ($row !== null && ($this->makeRefund)($row)) {
                $made++;
            }
        }

        return $made;
    }

    /**
     * The lesson's refund entries whose latest attempt neither succeeded nor was refused: no attempt yet, or
     * one still `requested`.
     *
     * @return list<LedgerEntry>
     */
    public function owed(Lesson $lesson): array
    {
        $entries = LedgerEntry::query()
            ->where('lesson_id', $lesson->id)
            ->where('account', LedgerAccount::Refund->value)
            ->where('amount', '>', 0)
            ->orderBy('id')
            ->get();

        $latest = $this->latestAttempts($entries->pluck('id')->values()->all());

        return array_values($entries
            ->reject(fn (LedgerEntry $e): bool => in_array($latest[$e->id]->status ?? null, [PaymentRefundStatus::Succeeded, PaymentRefundStatus::Failed], true))
            ->all());
    }

    /**
     * @param  array<int, int>  $entryIds
     * @return array<int, PaymentRefund> the highest attempt of each entry
     */
    private function latestAttempts(array $entryIds): array
    {
        return PaymentRefund::query()->whereIn('ledger_entry_id', $entryIds)->orderBy('attempt')->get()
            ->keyBy('ledger_entry_id')
            ->all();
    }

    /**
     * The live attempt for the entry, created `requested` if there is none. The insert is a no-op when
     * another worker wrote it first (the partial unique index), and both then carry the same key.
     */
    private function requestFor(Payment $payment, LedgerEntry $entry): ?PaymentRefund
    {
        $existing = $this->latestAttempts([$entry->id])[$entry->id] ?? null;

        if ($existing !== null) {
            return $existing->status === PaymentRefundStatus::Requested ? $existing : null;
        }

        DB::table('payment_refunds')->insertOrIgnore([
            'payment_id' => $payment->id,
            'ledger_entry_id' => $entry->id,
            'kind' => PaymentRefundKind::Ledger->value,
            'attempt' => 1,
            'idempotency_key' => "refund-{$payment->gateway_ref}-{$entry->id}",
            'amount' => min($entry->amount, $payment->amount->toFils()),
            'status' => PaymentRefundStatus::Requested->value,
            'requested_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = PaymentRefund::query()->where('ledger_entry_id', $entry->id)->where('attempt', 1)->first();

        return $row?->status === PaymentRefundStatus::Requested ? $row : null;
    }
}
