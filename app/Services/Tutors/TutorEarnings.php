<?php

namespace App\Services\Tutors;

use App\Actions\Lessons\OpenDispute;
use App\Actions\Lessons\OpenSuspensionDispute;
use App\Actions\Lessons\SettleSuspendedLesson;
use App\Enums\LedgerAccount;
use App\Enums\LedgerEntryType;
use App\Enums\LessonStatus;
use App\Enums\PayoutLineStatus;
use App\Models\LedgerEntry;
use App\Models\Lesson;
use App\Models\PayoutBatchLine;
use App\Models\TutorProfile;
use App\Support\Money;
use Carbon\CarbonImmutable;
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
 *   not yet released and not refunded) and that is not disputed; and (R258) money already released on a lesson that can
 *   still be disputed, until its dispute window closes. In progress is counted with the other two so a lesson that has
 *   started does not drop out of every bucket. `pendingAvailableAt` is the date the earliest such release moves to
 *   available.
 * - on hold: the tutor's share of a disputed lesson, whether its payment is still in escrow or was already released.
 *   Up to that amount: the settlement may pay the tutor less. A lesson that was released before it was disputed is
 *   counted here and nowhere else, so it cannot be paid out while the dispute is open; the settlement's reversal legs
 *   then take it out of the ledger.
 * - available (R258): released to the tutor (the ledger's `tutor` account, `release_tutor` and `release_reversal` legs)
 *   on lessons that are not disputed and are past their dispute window, and not yet in a payout batch. The window is
 *   `ends_at` + `OpenDispute::WINDOW_HOURS` on a completed lesson, `OpenSuspensionDispute::WINDOW_DAYS` when the lesson
 *   was settled after a suspension; a lesson in no other status can be disputed, so it has no window.
 * - processing: in a payout batch line that is not yet paid or failed (23h).
 * - paid to date: paid out; the `payout` entries on the ledger's `tutor` account (23h).
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

        $now = CarbonImmutable::now()->utc()->format('Y-m-d H:i:s');
        $disputed = LessonStatus::Disputed->value;

        // `window_closes_at` is null for a lesson that cannot be disputed; a released amount counts as available once it
        // is null or not after now, and as pending (with the date it opens) while it is still in the future.
        $row = DB::query()
            ->fromSub($this->perLesson($profile), 'per_lesson')
            ->selectRaw('coalesce(sum(case when status <> ? and escrow = price then tutor_amount else 0 end), 0) as pending', [$disputed])
            ->selectRaw('coalesce(sum(case when status = ? and (escrow = price or released > 0) then tutor_amount else 0 end), 0) as on_hold', [$disputed])
            ->selectRaw('coalesce(sum(case when status <> ? and window_closes_at > ? then released else 0 end), 0) as released_in_window', [$disputed, $now])
            ->selectRaw('coalesce(sum(case when status <> ? and (window_closes_at is null or window_closes_at <= ?) then released else 0 end), 0) as released', [$disputed, $now])
            ->selectRaw('min(case when status <> ? and released > 0 and window_closes_at > ? then window_closes_at end) as pending_available_at', [$disputed, $now])
            ->first();

        $released = Money::fils((int) $row->released);
        $inWindow = Money::fils((int) $row->released_in_window);

        return new EarningsBuckets(
            pending: Money::fils((int) $row->pending)->add($inWindow),
            onHold: Money::fils((int) $row->on_hold),
            available: $released->subtract($processing)->subtract($paid),
            processing: $processing,
            paidToDate: $paid,
            pendingAvailableAt: $row->pending_available_at === null ? null : CarbonImmutable::parse($row->pending_available_at, 'UTC'),
        );
    }

    /**
     * R258: whether the tutor's available bucket is below zero, which happens only when a dispute settlement took back
     * money that a payout had already sent. Admins see a flag; the next payout batch nets it.
     */
    public function isOverpaid(TutorProfile $profile): bool
    {
        return $this->buckets($profile)->available->isNegative();
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
     * One row per lesson of this tutor: its status, frozen price and tutor share, what is in escrow now, what has
     * been released to the tutor (net of reversals) and when its dispute window closes (null when it has none). One
     * query, never a balance lookup per lesson.
     *
     * @return Builder
     */
    private function perLesson(TutorProfile $profile)
    {
        return DB::table('lessons')
            ->leftJoin('ledger_entries as le', 'le.lesson_id', '=', 'lessons.id')
            ->where('lessons.tutor_profile_id', $profile->getKey())
            ->groupBy('lessons.id', 'lessons.status', 'lessons.price', 'lessons.tutor_amount', 'lessons.ends_at')
            ->select('lessons.status', 'lessons.price', 'lessons.tutor_amount')
            ->selectRaw(
                'case when lessons.status in (?, ?) then lessons.ends_at + (case when exists (select 1 from audit_logs al where al.action = ? and al.subject_type = ? and al.subject_id = lessons.id) then ?::int else ?::int end) * interval \'1 hour\' end as window_closes_at',
                [
                    LessonStatus::Completed->value,
                    LessonStatus::CompletedReported->value,
                    SettleSuspendedLesson::AUDIT_SETTLED,
                    Lesson::class,
                    OpenSuspensionDispute::WINDOW_DAYS * 24,
                    OpenDispute::WINDOW_HOURS,
                ],
            )
            ->selectRaw('coalesce(sum(le.amount) filter (where le.account = ?), 0) as escrow', [LedgerAccount::Escrow->value])
            ->selectRaw('coalesce(sum(le.amount) filter (where le.account = ? and le.type in (?, ?)), 0) as released', [
                LedgerAccount::Tutor->value, LedgerEntryType::ReleaseTutor->value, LedgerEntryType::ReleaseReversal->value,
            ]);
    }

    /**
     * The sum of the tutor's payout batch lines that are not yet paid or failed: money in a batch an admin has not
     * settled. Read from the lines, because nothing is in the ledger until a line is paid.
     */
    private function processing(TutorProfile $profile): Money
    {
        return Money::fils((int) PayoutBatchLine::query()
            ->where('tutor_profile_id', $profile->getKey())
            ->where('status', PayoutLineStatus::Pending->value)
            ->sum('amount'));
    }

    /**
     * What has left the platform for the tutor: minus the `payout` entries on their ledger `tutor` account. Read from the
     * ledger, not from the lines, so it is the money that really moved; `ledger:verify` ties it to the paid lines.
     */
    private function paidToDate(TutorProfile $profile): Money
    {
        return Money::fils(-(int) LedgerEntry::query()
            ->where('tutor_profile_id', $profile->getKey())
            ->where('account', LedgerAccount::Tutor->value)
            ->where('type', LedgerEntryType::Payout->value)
            ->sum('amount'));
    }
}
