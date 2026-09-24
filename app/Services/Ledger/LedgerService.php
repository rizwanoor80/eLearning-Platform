<?php

namespace App\Services\Ledger;

use App\Enums\LedgerAccount;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentStatus;
use App\Exceptions\LedgerException;
use App\Models\LedgerEntry;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

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
 *
 * DATA_MODEL v1.3 wrote HOLD as a bare `escrow +price`, which cannot sum to zero;
 * the `gateway` leg is the counter-entry (v1.4, ADR-008). RELEASE is two
 * zero-sum pairs so each escrow leg has an honest `type`. SETTLE and PAYOUT are
 * declared for CP5 and refuse to run until then.
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
     * Declared for the two-dial dispute (CP8); nothing calls it in CP3.
     */
    public function settle(): never
    {
        throw new LogicException('LedgerService::settle() arrives with disputes (CP8).');
    }

    /**
     * Declared for tutor payouts (CP5); nothing calls it in CP3.
     */
    public function payout(): never
    {
        throw new LogicException('LedgerService::payout() arrives with payouts (CP5).');
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
     * @param  list<array{0: LedgerAccount, 1: LedgerEntryType, 2: int, 3: int|null}>  $legs  account, type, signed fils, tutor profile
     */
    private function write(Lesson $lesson, ?User $by, array $legs, string $memo): void
    {
        self::$writing++;

        try {
            foreach ($legs as [$account, $type, $amount, $tutorProfileId]) {
                LedgerEntry::query()->create([
                    'lesson_id' => $lesson->getKey(),
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
