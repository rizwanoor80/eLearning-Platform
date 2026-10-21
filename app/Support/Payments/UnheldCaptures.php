<?php

namespace App\Support\Payments;

use App\Enums\LedgerAccount;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 25b (R267): the lesson-level check for a capture the platform does not hold exactly. `LedgerService::strandedPayments()`
 * finds a captured payment whose lesson has no Hold at all; `RefundUnverifiedCapture` treats "any ledger entry on the
 * lesson" as "held" and leaves such a payment alone. This closes the gap between the two: a payment the gateway took
 * (captured, partially refunded or refunded) whose lesson has ledger entries must have a Hold, and the escrow side of
 * it must be exactly the payment's amount (integer fils; the hold and its entries are written in one transaction, so
 * there is no grace window). Report-only: nothing is refunded or written from here. It lives outside `LedgerService`,
 * which is frozen.
 */
class UnheldCaptures
{
    /**
     * @return Collection<int, array{payment_id: int, lesson_id: int, status: 'captured'|'failed'|'partially_refunded'|'pending'|'refunded', held: int, amount: int}>
     */
    public function __invoke(): Collection
    {
        $held = DB::table('ledger_entries')
            ->selectRaw('lesson_id, count(*) as holds, coalesce(sum(amount), 0) as held')
            ->where('account', LedgerAccount::Escrow->value)
            ->where('type', LedgerEntryType::Hold->value)
            ->groupBy('lesson_id');

        return Payment::query()
            ->whereNotNull('payments.gateway_ref')
            ->whereIn('payments.status', [PaymentStatus::Captured->value, PaymentStatus::PartiallyRefunded->value, PaymentStatus::Refunded->value])
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('ledger_entries as any_entry')->whereColumn('any_entry.lesson_id', 'payments.lesson_id'))
            ->leftJoinSub($held, 'holds', 'holds.lesson_id', '=', 'payments.lesson_id')
            ->where(fn ($query) => $query->whereNull('holds.holds')->orWhereColumn('holds.held', '<>', 'payments.amount'))
            ->select('payments.*', DB::raw('coalesce(holds.held, 0) as held_fils'))
            ->orderBy('payments.lesson_id')
            ->get()
            ->map(fn (Payment $payment): array => [
                'payment_id' => $payment->id,
                'lesson_id' => $payment->lesson_id,
                'status' => $payment->status->value,
                'held' => (int) $payment->getAttribute('held_fils'),
                'amount' => $payment->amount->toFils(),
            ])
            ->values();
    }
}
