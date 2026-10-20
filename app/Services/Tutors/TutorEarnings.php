<?php

namespace App\Services\Tutors;

use App\Enums\LedgerAccount;
use App\Enums\LedgerEntryType;
use App\Enums\LessonStatus;
use App\Models\LedgerEntry;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * R250: what a tutor has earned, in five buckets, computed on every read from the lessons and the ledger. There is no
 * stored balance anywhere.
 *
 * Each lesson is read once: its escrow balance and what has been released to the tutor come from the ledger, its frozen
 * `tutor_amount` from the lesson row.
 *
 * - pending: the tutor's share of a lesson whose whole price is still in escrow (confirmed, in progress or completed,
 *   not yet released and not refunded) and that is not disputed. In progress is counted with the other two so a lesson
 *   that has started does not drop out of every bucket.
 * - on hold: the tutor's share of a disputed lesson, whether its payment is still in escrow or was already released.
 *   Up to that amount: the settlement may pay the tutor less. A lesson that was released before it was disputed is
 *   counted here and nowhere else, so it cannot be paid out while the dispute is open; the settlement's reversal legs
 *   then take it out of the ledger.
 * - available: released to the tutor (the ledger's `tutor` account, `release_tutor` and `release_reversal` legs) on
 *   lessons that are not disputed, and not yet in a payout batch.
 * - processing: in a payout batch line that is not yet paid. Always 0 until payout batches (23h) exist.
 * - paid to date: in a payout batch line that is paid. Always 0 until payout batches (23h) exist.
 *
 * `lifetime()` is the same total worked out a second way: straight from the ledger's tutor entries and the escrow
 * balances, with no status in it at all. The five buckets add up to it (the proof in the tests); a lesson that fell out
 * of every bucket would make them differ.
 */
class TutorEarnings
{
    public function buckets(TutorProfile $profile): EarningsBuckets
    {
        $processing = $this->processing($profile);
        $paid = $this->paidToDate($profile);

        $row = DB::query()
            ->fromSub($this->perLesson($profile), 'per_lesson')
            ->selectRaw('coalesce(sum(case when status <> ? and escrow = price then tutor_amount else 0 end), 0) as pending', [LessonStatus::Disputed->value])
            ->selectRaw('coalesce(sum(case when status = ? and (escrow = price or released > 0) then tutor_amount else 0 end), 0) as on_hold', [LessonStatus::Disputed->value])
            ->selectRaw('coalesce(sum(case when status <> ? then released else 0 end), 0) as released', [LessonStatus::Disputed->value])
            ->first();

        $released = Money::fils((int) $row->released);

        return new EarningsBuckets(
            pending: Money::fils((int) $row->pending),
            onHold: Money::fils((int) $row->on_hold),
            available: $released->subtract($processing)->subtract($paid),
            processing: $processing,
            paidToDate: $paid,
        );
    }

    /**
     * Everything the ledger says the tutor has earned: the tutor-account entries of every release and reversal, plus the
     * tutor's share of every lesson whose whole price is still in escrow. Written apart from `buckets()` and looking at
     * no status, so it can disagree with it.
     */
    public function lifetime(TutorProfile $profile): Money
    {
        $released = (int) LedgerEntry::query()
            ->where('tutor_profile_id', $profile->getKey())
            ->where('account', LedgerAccount::Tutor->value)
            ->whereIn('type', [LedgerEntryType::ReleaseTutor->value, LedgerEntryType::ReleaseReversal->value])
            ->sum('amount');

        $inEscrow = (int) Lesson::query()
            ->where('tutor_profile_id', $profile->getKey())
            ->whereRaw('(select coalesce(sum(le.amount), 0) from ledger_entries le where le.lesson_id = lessons.id and le.account = ?) = lessons.price', [LedgerAccount::Escrow->value])
            ->sum('tutor_amount');

        return Money::fils($released)->add(Money::fils($inEscrow));
    }

    /**
     * One row per lesson of this tutor: its status, frozen price and tutor share, what is in escrow now and what has
     * been released to the tutor (net of reversals). One query, never a balance lookup per lesson.
     *
     * @return Builder
     */
    private function perLesson(TutorProfile $profile)
    {
        return DB::table('lessons')
            ->leftJoin('ledger_entries as le', 'le.lesson_id', '=', 'lessons.id')
            ->where('lessons.tutor_profile_id', $profile->getKey())
            ->groupBy('lessons.id', 'lessons.status', 'lessons.price', 'lessons.tutor_amount')
            ->select('lessons.status', 'lessons.price', 'lessons.tutor_amount')
            ->selectRaw('coalesce(sum(le.amount) filter (where le.account = ?), 0) as escrow', [LedgerAccount::Escrow->value])
            ->selectRaw('coalesce(sum(le.amount) filter (where le.account = ? and le.type in (?, ?)), 0) as released', [
                LedgerAccount::Tutor->value, LedgerEntryType::ReleaseTutor->value, LedgerEntryType::ReleaseReversal->value,
            ]);
    }

    /**
     * Seam for 23h: the sum of the tutor's unpaid payout batch lines.
     */
    private function processing(TutorProfile $profile): Money
    {
        return Money::zero();
    }

    /**
     * Seam for 23h: the sum of the tutor's paid payout batch lines.
     */
    private function paidToDate(TutorProfile $profile): Money
    {
        return Money::zero();
    }
}
