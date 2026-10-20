<?php

namespace App\Services\Ledger;

use App\Enums\LedgerAccount;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentStatus;
use App\Enums\PayoutLineStatus;
use App\Exceptions\LedgerException;
use App\Models\Dispute;
use App\Models\LedgerEntry;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PayoutBatchLine;
use App\Models\TutorProfile;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `ledger_entries` (invariant #1, R52). Amounts are signed
 * integer fils read from the lesson's FROZEN price and split (invariants #3, #6).
 *
 * Every operation is one transaction that LOCKS THE LESSON ROW first — a ledger
 * is append-only, so there is no entry row to lock — checks its precondition on
 * the sums it reads inside that lock (so two concurrent HOLDs or RELEASEs cannot
 * both pass), writes its legs, and re-sums the lesson: unless the lesson's
 * entries add up to exactly zero it throws and the transaction rolls back.
 *
 *   HOLD     gateway −price · escrow +price
 *   RELEASE  escrow −tutor_amount · tutor +tutor_amount · escrow −commission_amount · platform +commission_amount
 *   REFUND   escrow −price · refund +price                       (full refund)
 *   SETTLE   escrow −refund · refund +refund · escrow −tutor · tutor +tutor · escrow −delta · platform +delta
 *            (dispute, R150; delta = price − refund − tutor, may be negative → `goodwill`; a lesson already
 *            RELEASEd when the dispute opened gets typed reversal legs first, restoring escrow to price)
 *
 * DATA_MODEL v1.3 wrote HOLD as a bare `escrow +price`, which cannot sum to zero;
 * the `gateway` leg is the counter-entry (v1.4, ADR-008). RELEASE is two
 * zero-sum pairs so each escrow leg has an honest `type`.
 *
 *   PAYOUT   tutor −amount · bank +amount                       (23h, R253; belongs to a payout batch line, not to a lesson)
 *
 * A payout has no lesson: it pays out what many lessons released. So it is balanced by the line it pays (`payout_id`),
 * and `unbalancedPayouts()` is to payouts what `unbalancedLessons()` is to lessons.
 */
final class LedgerService
{
    private static int $writing = 0;

    /**
     * Grace window for `strandedPayments()` (R77 item 3): a payment inside this
     * many minutes of its relevant timestamp is mid-request, not stranded.
     */
    private const STRANDED_GRACE_MINUTES = 5;

    /**
     * Whether an operation of this service is inserting right now — the only
     * time `LedgerEntry` accepts a new row.
     */
    public static function isWriting(): bool
    {
        return self::$writing > 0;
    }

    public function hold(Lesson $lesson, ?User $by = null): void
    {
        $this->operate($lesson, function (Lesson $locked) use ($by): void {
            $price = $this->price($locked);

            if ($price <= 0) {
                throw new LedgerException('A lesson with no price cannot be held.');
            }

            if ($this->entriesOfType($locked, LedgerEntryType::Hold) > 0) {
                throw new LedgerException("Lesson {$locked->id} already has a hold.");
            }

            $this->write($locked, $by, [
                [LedgerAccount::Gateway, LedgerEntryType::Hold, -$price, null],
                [LedgerAccount::Escrow, LedgerEntryType::Hold, $price, null],
            ], 'Hold');
        });
    }

    public function release(Lesson $lesson, ?User $by = null): void
    {
        $this->operate($lesson, function (Lesson $locked) use ($by): void {
            $price = $this->price($locked);
            $tutor = $locked->tutor_amount->toFils();
            $commission = $locked->commission_amount->toFils();

            $this->assertEscrowEqualsPrice($locked, $price, 'released');

            $this->write($locked, $by, [
                [LedgerAccount::Escrow, LedgerEntryType::ReleaseTutor, -$tutor, null],
                [LedgerAccount::Tutor, LedgerEntryType::ReleaseTutor, $tutor, $locked->tutor_profile_id],
                [LedgerAccount::Escrow, LedgerEntryType::ReleaseCommission, -$commission, null],
                [LedgerAccount::Platform, LedgerEntryType::ReleaseCommission, $commission, null],
            ], 'Release');
        });
    }

    public function refund(Lesson $lesson, ?User $by = null): void
    {
        $this->operate($lesson, function (Lesson $locked) use ($by): void {
            $price = $this->price($locked);

            $this->assertEscrowEqualsPrice($locked, $price, 'refunded');

            $this->write($locked, $by, [
                [LedgerAccount::Escrow, LedgerEntryType::Refund, -$price, null],
                [LedgerAccount::Refund, LedgerEntryType::Refund, $price, null],
            ], 'Refund');
        });
    }

    /**
     * The two-dial dispute resolution (R150, DATA_MODEL SETTLE row). `$dispute`
     * tags every leg written here with `dispute_id`; the caller (the resolve
     * action) is responsible for the dispute row's own resolution columns and
     * the lesson's `disputed -> settled` transition — this method only writes
     * the ledger side, inside its own locked, zero-sum-checked operation.
     *
     * `r`/`t` are computed from the lesson's FROZEN `price`/`tutor_amount`
     * (invariant #6 — these never change after booking), so they are safe to
     * compute before the lock. `d` (the platform's residual) may be negative,
     * written as `Goodwill` rather than `ReleaseCommission` when it is.
     *
     * A `completed` lesson still has full escrow (balance == price): the three
     * SETTLE leg-pairs below (refund, tutor, platform) are DATA_MODEL's row
     * exactly, read as three independent zero-sum pairs — each written only
     * when its amount is non-zero, so a 0% dial never leaves a zero-fil leg
     * for a later per-type count to misread as a real release. A
     * `completed_reported` lesson has already been RELEASEd by the time a
     * dispute can open on it (every path there writes RELEASE first) — escrow
     * balance == 0 — so this first claws the RELEASE back into escrow with
     * typed reversal legs before writing the same three SETTLE pairs
     * (docs/CYCLE-LOG.md, the 9b reversal-leg DECISION). Any other escrow
     * balance is a ledger inconsistency, not a case to silently handle, and
     * throws — this also catches a never-held lesson (escrow 0 but the tutor/
     * platform were never paid, so the reversal-balance assert below fails).
     *
     * Guarded against being called twice on the same lesson (advisor consult,
     * 9b money-path review): a second settle would see escrow == 0 —
     * indistinguishable from "already released" — and wrongly claw back
     * amounts that were never paid out via RELEASE. `dispute_id`-tagged or
     * `ReleaseReversal` entries already on the lesson refuse a second call
     * outright, and the reversal-balance assert is a second, independent
     * check of the same thing.
     *
     * @return array{refund: int, tutor: int, platform_delta: int} the fils actually written, for the caller to persist on the dispute row
     */
    public function settle(Lesson $lesson, Dispute $dispute, int $parentRefundPct, int $tutorPayPct, ?User $by = null): array
    {
        if ($dispute->lesson_id !== $lesson->getKey()) {
            throw new LedgerException("Dispute {$dispute->id} does not belong to lesson {$lesson->id}.");
        }

        if ($parentRefundPct < 0 || $parentRefundPct > 100 || $tutorPayPct < 0 || $tutorPayPct > 100) {
            throw new LedgerException('Dispute dials must each be 0-100.');
        }

        $price = $this->price($lesson);
        $tutorAmount = $lesson->tutor_amount->toFils();
        $commissionAmount = $lesson->commission_amount->toFils();

        $refund = Money::fils($price)->percentage($parentRefundPct)->toFils();
        $tutor = Money::fils($tutorAmount)->percentage($tutorPayPct)->toFils();
        $platformDelta = $price - $refund - $tutor;

        $this->operate($lesson, function (Lesson $locked) use ($dispute, $by, $price, $tutorAmount, $commissionAmount, $refund, $tutor, $platformDelta): void {
            if ($this->alreadySettled($locked)) {
                throw new LedgerException("Lesson {$locked->id} has already been settled.");
            }

            $escrow = $this->balance($locked, LedgerAccount::Escrow);

            if ($escrow !== $price && $escrow !== 0) {
                throw new LedgerException("Lesson {$locked->id} cannot be settled: its escrow ({$escrow}) is neither the price nor zero.");
            }

            $legs = [];

            if ($escrow === 0) {
                if ($this->balance($locked, LedgerAccount::Tutor) !== $tutorAmount || $this->balance($locked, LedgerAccount::Platform) !== $commissionAmount) {
                    throw new LedgerException("Lesson {$locked->id} cannot be settled: escrow is 0 but it was never released (tutor/platform balances don't match the frozen split).");
                }

                $legs[] = [LedgerAccount::Escrow, LedgerEntryType::ReleaseReversal, $tutorAmount, null];
                $legs[] = [LedgerAccount::Tutor, LedgerEntryType::ReleaseReversal, -$tutorAmount, $locked->tutor_profile_id];
                $legs[] = [LedgerAccount::Escrow, LedgerEntryType::ReleaseReversal, $commissionAmount, null];
                $legs[] = [LedgerAccount::Platform, LedgerEntryType::ReleaseReversal, -$commissionAmount, null];
            }

            if ($refund !== 0) {
                $legs[] = [LedgerAccount::Escrow, LedgerEntryType::Refund, -$refund, null];
                $legs[] = [LedgerAccount::Refund, LedgerEntryType::Refund, $refund, null];
            }

            if ($tutor !== 0) {
                $legs[] = [LedgerAccount::Escrow, LedgerEntryType::ReleaseTutor, -$tutor, null];
                $legs[] = [LedgerAccount::Tutor, LedgerEntryType::ReleaseTutor, $tutor, $locked->tutor_profile_id];
            }

            if ($platformDelta !== 0) {
                $platformType = $platformDelta < 0 ? LedgerEntryType::Goodwill : LedgerEntryType::ReleaseCommission;
                $legs[] = [LedgerAccount::Escrow, $platformType, -$platformDelta, null];
                $legs[] = [LedgerAccount::Platform, $platformType, $platformDelta, null];
            }

            $this->write($locked, $by, $legs, 'Settle', $dispute->id);
        });

        return ['refund' => $refund, 'tutor' => $tutor, 'platform_delta' => $platformDelta];
    }

    /**
     * Pays one payout batch line (R253): `tutor −amount`, `bank +amount`, both tagged with the line (`payout_id`) and the
     * tutor, neither with a lesson. The caller (`MarkPayoutLinePaid`) owns the line's own `paid` columns; this writes the
     * ledger side only, in its own transaction that:
     *
     *  - locks the tutor's profile row, then the line row (the order every payout action uses, and `OpenDispute` takes the
     *    profile first too), so two payers of the same line (or two lines of the same tutor) are taken one at a time;
     *  - refuses a line that is not `pending`, or that already has entries (a second call would pay it twice);
     *  - refuses an amount larger than the tutor's whole balance on the ledger's `tutor` account, the one limit that
     *    needs no status: money that was never released to the tutor cannot be paid to them;
     *  - re-sums the line's entries and throws, rolling back, unless they add up to exactly zero.
     *
     * The tutor account's balance may be driven below zero later by a dispute settlement that reverses a lesson which
     * was already paid out (a clawback). That is a fact of the ledger, not an error here.
     */
    public function payout(PayoutBatchLine $line, ?User $by = null): void
    {
        DB::transaction(function () use ($line, $by): void {
            // One lock order everywhere: the tutor's profile first, then the line. `tutor_profile_id` never changes on a line.
            TutorProfile::query()->whereKey($line->tutor_profile_id)->lockForUpdate()->firstOrFail();
            $locked = PayoutBatchLine::query()->whereKey($line->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== PayoutLineStatus::Pending) {
                throw new LedgerException("Payout line {$locked->id} is {$locked->status->value}, not pending.");
            }

            $amount = $locked->amount->toFils();

            if ($amount <= 0) {
                throw new LedgerException("Payout line {$locked->id} has no amount to pay.");
            }

            if (LedgerEntry::query()->where('payout_id', $locked->id)->exists()) {
                throw new LedgerException("Payout line {$locked->id} has already been paid.");
            }

            $balance = (int) LedgerEntry::query()
                ->where('tutor_profile_id', $locked->tutor_profile_id)
                ->where('account', LedgerAccount::Tutor->value)
                ->sum('amount');

            if ($amount > $balance) {
                throw new LedgerException("Payout line {$locked->id} ({$amount} fils) is more than the tutor's ledger balance ({$balance} fils).");
            }

            $memo = "Payout — line {$locked->id}";

            self::$writing++;

            try {
                foreach ([[LedgerAccount::Tutor, -$amount, $locked->tutor_profile_id], [LedgerAccount::Bank, $amount, null]] as [$account, $signed, $tutorProfileId]) {
                    LedgerEntry::query()->create([
                        'payout_id' => $locked->id,
                        'account' => $account,
                        'type' => LedgerEntryType::Payout,
                        'amount' => $signed,
                        'tutor_profile_id' => $tutorProfileId,
                        'memo' => $memo,
                        'created_by_user_id' => $by?->getKey(),
                    ]);
                }
            } finally {
                self::$writing--;
            }

            $total = (int) LedgerEntry::query()->where('payout_id', $locked->id)->sum('amount');

            if ($total !== 0) {
                throw new LedgerException("Payout line {$locked->id} would not sum to zero (it sums to {$total} fils).");
            }
        });
    }

    /**
     * Σ amount of the lesson's entries — zero after every operation.
     */
    public function sum(Lesson $lesson): int
    {
        return (int) LedgerEntry::query()->where('lesson_id', $lesson->getKey())->sum('amount');
    }

    public function balance(Lesson $lesson, LedgerAccount $account): int
    {
        return (int) LedgerEntry::query()->where('lesson_id', $lesson->getKey())->where('account', $account)->sum('amount');
    }

    /**
     * Every lesson whose entries do not add up to zero — what `ledger:verify`
     * reports. Empty means the ledger is sound.
     *
     * @return Collection<int, array{lesson_id: int, total: int}>
     */
    public function unbalancedLessons(): Collection
    {
        return LedgerEntry::query()
            ->whereNotNull('lesson_id')
            ->selectRaw('lesson_id, SUM(amount) as total')
            ->groupBy('lesson_id')
            ->havingRaw('SUM(amount) <> 0')
            ->orderBy('lesson_id')
            ->get()
            ->map(fn (LedgerEntry $row): array => [
                'lesson_id' => (int) $row->getAttribute('lesson_id'),
                'total' => (int) $row->getAttribute('total'),
            ])
            ->values();
    }

    /**
     * Every payout batch line whose ledger entries do not tell the same story as the line: its entries do not add up to
     * zero, it is `paid` and the tutor-account entries are not exactly minus its amount, or it is not `paid` and has entries
     * at all. What `ledger:verify` adds for payouts, which have no lesson for `unbalancedLessons()` to group by.
     * Empty means every payout is sound.
     *
     * @return Collection<int, array{payout_id: int, status: string, total: int, tutor_total: int, amount: int}>
     */
    public function unbalancedPayouts(): Collection
    {
        return DB::table('payout_batch_lines as l')
            ->leftJoin('ledger_entries as le', 'le.payout_id', '=', 'l.id')
            ->groupBy('l.id', 'l.status', 'l.amount')
            ->selectRaw('l.id as payout_id, l.status, l.amount, coalesce(sum(le.amount), 0) as total, coalesce(sum(le.amount) filter (where le.account = ?), 0) as tutor_total, count(le.id) as entries', [LedgerAccount::Tutor->value])
            ->havingRaw('coalesce(sum(le.amount), 0) <> 0 or (l.status = ? and coalesce(sum(le.amount) filter (where le.account = ?), 0) <> -l.amount) or (l.status <> ? and count(le.id) > 0)', [PayoutLineStatus::Paid->value, LedgerAccount::Tutor->value, PayoutLineStatus::Paid->value])
            ->orderBy('l.id')
            ->get()
            ->map(fn (object $row): array => [
                'payout_id' => (int) $row->payout_id,
                'status' => (string) $row->status,
                'total' => (int) $row->total,
                'tutor_total' => (int) $row->tutor_total,
                'amount' => (int) $row->amount,
            ])
            ->values();
    }

    /**
     * Ids of ledger entries that belong to neither a lesson nor a payout line. Both of the other checks group by one of
     * those two, so such an entry would be invisible to them. The database refuses a payout entry without a line, so this
     * is a backstop for any other kind of entry written without a lesson. Empty means there are none.
     *
     * @return Collection<int, int>
     */
    public function strayEntries(): Collection
    {
        return LedgerEntry::query()->whereNull('lesson_id')->whereNull('payout_id')->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id);
    }

    /**
     * Every payment that is `captured` with no `hold` ledger entry for its lesson,
     * or `pending` past the grace window (an unknown capture outcome) — the two
     * shapes invariant #1 breaks into if a `Throwable` interrupts `BookLesson`
     * after the gateway has already captured the money (R77 item 3). Excludes
     * anything still inside the grace window so an in-flight booking is never
     * flagged mid-request. Empty means nothing is stranded.
     *
     * @return Collection<int, array{payment_id: int, lesson_id: int, status: string}>
     */
    public function strandedPayments(): Collection
    {
        $cutoff = Carbon::now()->subMinutes(self::STRANDED_GRACE_MINUTES);

        $capturedWithoutHold = Payment::query()
            ->where('status', PaymentStatus::Captured)
            ->where('updated_at', '<=', $cutoff)
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('ledger_entries')
                ->whereColumn('ledger_entries.lesson_id', 'payments.lesson_id')
                ->where('ledger_entries.type', LedgerEntryType::Hold))
            ->get();

        $stalePending = Payment::query()
            ->where('status', PaymentStatus::Pending)
            ->where('created_at', '<=', $cutoff)
            ->get();

        return $capturedWithoutHold->concat($stalePending)
            ->sortBy('lesson_id')
            ->values()
            ->map(fn (Payment $payment): array => [
                'payment_id' => $payment->id,
                'lesson_id' => $payment->lesson_id,
                'status' => $this->statusValue($payment),
            ]);
    }

    /**
     * A plain `string`, not the literal-value union PHPStan tracks through
     * `PaymentStatus::value` — the declared return type is the widening
     * boundary `strandedPayments()` needs (Collection's TValue is invariant).
     */
    private function statusValue(Payment $payment): string
    {
        return $payment->status->value;
    }

    /**
     * @param  callable(Lesson): void  $work
     */
    private function operate(Lesson $lesson, callable $work): void
    {
        DB::transaction(function () use ($lesson, $work): void {
            $locked = Lesson::query()->whereKey($lesson->getKey())->lockForUpdate()->firstOrFail();

            $work($locked);

            $total = $this->sum($locked);

            if ($total !== 0) {
                throw new LedgerException("Lesson {$locked->id} would not sum to zero (it sums to {$total} fils).");
            }
        });
    }

    /**
     * The price, after checking the frozen split adds up to it exactly (R53).
     */
    private function price(Lesson $lesson): int
    {
        $price = $lesson->price->toFils();

        if ($lesson->tutor_amount->toFils() + $lesson->commission_amount->toFils() !== $price) {
            throw new LedgerException("Lesson {$lesson->id}: commission_amount + tutor_amount does not equal the price.");
        }

        return $price;
    }

    private function assertEscrowEqualsPrice(Lesson $lesson, int $price, string $verb): void
    {
        if ($this->balance($lesson, LedgerAccount::Escrow) !== $price) {
            throw new LedgerException("Lesson {$lesson->id} cannot be {$verb}: its escrow does not equal its price.");
        }
    }

    private function entriesOfType(Lesson $lesson, LedgerEntryType $type): int
    {
        return LedgerEntry::query()->where('lesson_id', $lesson->getKey())->where('type', $type)->count();
    }

    /**
     * Whether `settle()` has already written to this lesson — checked for
     * `dispute_id` (SETTLE always tags its legs) rather than any single leg
     * type, since a 0% dial can now leave a settle with no `ReleaseReversal`
     * and no `ReleaseTutor`/`Goodwill`/`ReleaseCommission` leg at all.
     */
    private function alreadySettled(Lesson $lesson): bool
    {
        return LedgerEntry::query()
            ->where('lesson_id', $lesson->getKey())
            ->where(fn ($query) => $query->whereNotNull('dispute_id')->orWhere('type', LedgerEntryType::ReleaseReversal))
            ->exists();
    }

    /**
     * @param  list<array{0: LedgerAccount, 1: LedgerEntryType, 2: int, 3: int|null}>  $legs  account, type, signed fils, tutor profile
     */
    private function write(Lesson $lesson, ?User $by, array $legs, string $memo, ?int $disputeId = null): void
    {
        self::$writing++;

        try {
            foreach ($legs as [$account, $type, $amount, $tutorProfileId]) {
                LedgerEntry::query()->create([
                    'lesson_id' => $lesson->getKey(),
                    'dispute_id' => $disputeId,
                    'account' => $account,
                    'type' => $type,
                    'amount' => $amount,
                    'tutor_profile_id' => $tutorProfileId,
                    'memo' => "{$memo} — lesson {$lesson->getKey()}",
                    'created_by_user_id' => $by?->getKey(),
                ]);
            }
        } finally {
            self::$writing--;
        }
    }
}
