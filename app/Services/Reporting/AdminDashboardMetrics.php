<?php

namespace App\Services\Reporting;

use App\Enums\AbuseReportStatus;
use App\Enums\DisputeStatus;
use App\Enums\LedgerAccount;
use App\Enums\LessonStatus;
use App\Enums\PaymentStatus;
use App\Enums\TutorProfileStatus;
use App\Models\AbuseReport;
use App\Models\Dispute;
use App\Models\LedgerEntry;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\TutorProfile;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;

/**
 * CP8 9d (R152): one read-only query per admin dashboard stat. Deliberately not on
 * `LedgerService` (frozen after CP5, and 9d is not authorised to touch it regardless):
 * `revenueThisWeek()`/`revenueThisMonth()` read `ledger_entries` directly, the same way any
 * other read-only report would, never through a write path.
 *
 * All boundaries use `now()`/`Date::today()` in the app default timezone (UTC — invariant #4),
 * matching `CheckTutorPermits`'s own precedent: there is no existing convention in this codebase
 * for converting an admin dashboard's boundaries to the viewing admin's personal timezone, and
 * UTC is also what every timestamp is stored in, so no edge conversion is introduced here.
 */
class AdminDashboardMetrics
{
    /**
     * Lessons starting inside the current UTC week (Mon 00:00 -> next Mon 00:00), excluding any
     * status that frees the tutor's slot (cancelled/expired/refunded — the same set invariant
     * #5's neighbouring "does this lesson still count" checks use via `LessonStatus::freeingSlot()`).
     *
     * CYCLE-LOG 2026-09-29 11:11 ADVISOR: `whereBetween` is inclusive on both ends, so a lesson
     * starting at exactly next Monday 00:00:00 would double-count into both this week and next.
     * Half-open range (`>=` start, `<` end) instead — the same boundary shape `platformRevenue()`
     * now uses.
     */
    public function lessonsThisWeek(): int
    {
        return Lesson::query()
            ->where('starts_at', '>=', Date::now()->startOfWeek())
            ->where('starts_at', '<', Date::now()->startOfWeek()->addWeek())
            ->whereNotIn('status', LessonStatus::freeingSlotValues())
            ->count();
    }

    /**
     * Net platform revenue: every `ledger_entries` row on the Platform account in the window,
     * no `type` filter (`ReleaseCommission`, `Goodwill`, `ReleaseReversal` all included — a
     * `Goodwill` or `ReleaseReversal` leg can be negative, so this is a net figure, not a sum of
     * only-positive commission legs).
     */
    public function revenueThisWeek(): Money
    {
        return $this->platformRevenue(Date::now()->startOfWeek(), Date::now()->startOfWeek()->addWeek());
    }

    public function revenueThisMonth(): Money
    {
        return $this->platformRevenue(Date::now()->startOfMonth(), Date::now()->startOfMonth()->addMonthNoOverflow());
    }

    /**
     * CYCLE-LOG 2026-09-29 11:11 ADVISOR: `whereBetween` is inclusive on both ends, so a ledger
     * entry written at exactly the period's end instant (e.g. next Monday 00:00:00.000000) would
     * double-count into both this period and the next. Half-open range instead: `$from` is
     * inside, `$to` is not.
     */
    private function platformRevenue(CarbonInterface $from, CarbonInterface $to): Money
    {
        $fils = (int) LedgerEntry::query()
            ->where('account', LedgerAccount::Platform)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->sum('amount');

        return Money::fils($fils);
    }

    /**
     * A completed lesson whose report is now overdue. Reuses the exact fields 9c's own
     * `TutorProfile::lateReportFlags()` and `TutorDashboardController`'s `$reportsDue` query
     * already use (`report_due_at`, `report_late_at`, the `progressReport` relation) so this
     * count agrees with the tutor-facing detail view, rather than inventing a second, competing
     * definition of "overdue":
     * - still `completed` and past its `report_due_at` (the report window has closed), or
     * - auto-released to `completed_reported` with `report_late_at` set and still no report filed.
     */
    public function reportsOverdue(): int
    {
        return Lesson::query()
            ->where(fn (Builder $q) => $q
                ->where('status', LessonStatus::Completed)
                ->where('report_due_at', '<', Date::now()))
            ->orWhere(fn (Builder $q) => $q
                ->where('status', LessonStatus::CompletedReported)
                ->whereNotNull('report_late_at')
                ->whereDoesntHave('progressReport'))
            ->count();
    }

    /**
     * Approved, bookable tutors whose permit expires within the next 30 days (inclusive of day
     * 30, exclusive of today). Mirrors `CheckTutorPermits`'s own bucketing exactly:
     * `$daysRemaining <= 0` (expiring today or already past) is its separate `permit_expired`
     * bucket, not a "warning" — a permit expiring today is already unbookable
     * (`TutorProfile::bookable()`/`permitIsValid()` both use a strict `>` on today, invariant #5),
     * so this dashboard count stays consistent with what search already hides.
     *
     * CYCLE-LOG 2026-09-29 11:11 ADVISOR: this docblock previously (in-session, never committed —
     * `app/Services/Reporting/` was untracked at the time) claimed today (day 0) was included in
     * the 30-day warning window; that was wrong. The code below has always excluded it.
     */
    public function permitsExpiringSoon(): int
    {
        $today = Date::today();

        return TutorProfile::query()
            ->where('status', TutorProfileStatus::Approved)
            ->whereNotNull('permit_expires_at')
            ->whereDate('permit_expires_at', '>', $today)
            ->whereDate('permit_expires_at', '<=', $today->copy()->addDays(30))
            ->count();
    }

    /**
     * Payments that failed in the last 7 days. `Payment` has no dedicated `failed_at` column;
     * `updated_at` is the existing convention for "when did this payment last change status"
     * (`LedgerService::strandedPayments()` uses the same field the same way).
     */
    public function failedChargesThisWeek(): int
    {
        return Payment::query()
            ->where('status', PaymentStatus::Failed)
            ->where('updated_at', '>=', Date::now()->subDays(7))
            ->count();
    }

    /**
     * Safeguarding reports still needing action. The Safeguarding queue's own table
     * (`AbuseReportsTable`) applies no default status filter — it lists every status and lets
     * the admin filter manually — so there is no existing "default view" definition of open to
     * match. "Open" here means not yet closed: `Open` or `Reviewing`.
     */
    public function openReports(): int
    {
        return AbuseReport::query()
            ->whereIn('status', [AbuseReportStatus::Open, AbuseReportStatus::Reviewing])
            ->count();
    }

    public function openDisputes(): int
    {
        return Dispute::query()->where('status', DisputeStatus::Open)->count();
    }
}
