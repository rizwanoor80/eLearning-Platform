<?php

use App\Enums\AbuseReportStatus;
use App\Enums\AbuseReportSubjectType;
use App\Enums\DisputeStatus;
use App\Enums\LessonStatus;
use App\Enums\PaymentStatus;
use App\Enums\TutorProfileStatus;
use App\Enums\UserStatus;
use App\Filament\Widgets\AdminOverviewWidget;
use App\Models\AbuseReport;
use App\Models\Dispute;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Reporting\AdminDashboardMetrics;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

/**
 * CP8 9d (R152). One test group per `AdminDashboardMetrics` method, each proving the boundary
 * edge (one hour/day either side) per the design-consult ADVISOR's explicit demand (CYCLE-LOG
 * 10:15), plus the widget's own `canView()` gate. `lessonsThisWeek()`/`revenueThisWeek()`/
 * `revenueThisMonth()` additionally each carry one exact-instant boundary test (CYCLE-LOG
 * 2026-09-29 11:11 ADVISOR) -- the one instant an inclusive `whereBetween` would have
 * double-counted, which "an hour/day either side" alone never exercises.
 */
function admMetrics(): AdminDashboardMetrics
{
    return app(AdminDashboardMetrics::class);
}

// ---- canView() gate ---------------------------------------------------------------------------

it('shows the overview widget only to an active admin', function () {
    $admin = User::factory()->admin()->create();
    $disabledAdmin = User::factory()->admin()->create(['status' => UserStatus::Suspended]);
    $tutor = User::factory()->tutor()->create();

    test()->actingAs($admin);
    expect(AdminOverviewWidget::canView())->toBeTrue();

    test()->actingAs($disabledAdmin);
    expect(AdminOverviewWidget::canView())->toBeFalse();

    test()->actingAs($tutor);
    expect(AdminOverviewWidget::canView())->toBeFalse();
});

/**
 * CYCLE-LOG 2026-09-29 11:11 ADVISOR: every other test in this file mounts the widget directly via
 * `Livewire::test(AdminOverviewWidget::class)`, which proves the widget itself works but never
 * proves the admin panel actually shows it on `/admin` -- `AdminPanelProvider::panel()` uses
 * `discoverWidgets(in: app_path('Filament/Widgets'), ...)` rather than a literal
 * `->widgets([AdminOverviewWidget::class, ...])` entry, so a widget that was renamed or moved out
 * of that directory would silently stop rendering with no test failing. Mirrors
 * `DisabledAdminAccessTest.php`'s own `Filament::getPanel('admin')->getResources()` precedent for
 * proving discovery, not just isolated behaviour.
 */
it('registers AdminOverviewWidget on the admin panel, via discovery', function () {
    $registered = Filament::getPanel('admin')->getWidgets();

    expect($registered)->toContain(AdminOverviewWidget::class);
});

it('renders every stat for an active admin', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(AdminOverviewWidget::class)
        ->assertSee('Lessons this week')
        ->assertSee('Revenue this week')
        ->assertSee('Revenue this month')
        ->assertSee('Reports overdue')
        ->assertSee('Permits expiring in 30 days')
        ->assertSee('Failed charges (7 days)')
        ->assertSee('Open safeguarding reports')
        ->assertSee('Open disputes');
});

// ---- lessonsThisWeek() ------------------------------------------------------------------------

it('counts a lesson starting inside this week, and excludes one an hour either side of the week boundary', function () {
    $weekStart = Date::now()->startOfWeek();

    Lesson::factory()->startingAt($weekStart->copy()->addHour())->create(); // inside
    Lesson::factory()->startingAt($weekStart->copy()->subHour())->create(); // before -- excluded
    Lesson::factory()->startingAt($weekStart->copy()->addWeek()->addHour())->create(); // next week -- excluded

    expect(admMetrics()->lessonsThisWeek())->toBe(1);
});

it('excludes a lesson starting at the exact next-week instant, so it never double-counts into both weeks', function () {
    // CYCLE-LOG 2026-09-29 11:11 ADVISOR: the fixture above only proves "an hour either side" --
    // this proves the half-open range itself (`>=` start, `<` end) at the one instant a
    // `whereBetween` (inclusive both ends) would have double-counted.
    $weekStart = Date::now()->startOfWeek();

    Lesson::factory()->startingAt($weekStart)->create(); // this week's own start instant -- included
    Lesson::factory()->startingAt($weekStart->copy()->addWeek())->create(); // next week's start instant -- excluded here

    expect(admMetrics()->lessonsThisWeek())->toBe(1);
});

it('excludes a lesson this week whose status frees the tutor slot', function () {
    $weekStart = Date::now()->startOfWeek();

    Lesson::factory()->startingAt($weekStart->copy()->addHour())->withStatus(LessonStatus::CancelledByParent)->create();
    Lesson::factory()->startingAt($weekStart->copy()->addHours(2))->withStatus(LessonStatus::Confirmed)->create();

    expect(admMetrics()->lessonsThisWeek())->toBe(1);
});

// ---- revenueThisWeek() / revenueThisMonth() ----------------------------------------------------

it('sums Platform-account entries with no type filter, including a ReleaseReversal leg, not just ReleaseCommission', function () {
    // Mirrors LedgerServiceTest.php's "settles an already-released lesson" fixture exactly:
    // release() writes +2500 release_commission, settle() reverses it (-2500 release_reversal)
    // then writes its own +2500 release_commission -- net Platform balance 2500, not 5000.
    $lesson = Lesson::factory()->create(['price' => 10000, 'commission_pct' => 25, 'commission_amount' => 2500, 'tutor_amount' => 7500]);
    $ledger = app(LedgerService::class);
    $ledger->hold($lesson);
    $ledger->release($lesson);
    $dispute = Dispute::factory()->for($lesson)->create();
    $ledger->settle($lesson, $dispute, 0, 100);

    expect(admMetrics()->revenueThisWeek()->toFils())->toBe(2500);
});

it('excludes a Platform entry an hour before the week starts, and includes one an hour after', function () {
    // ledger_entries is append-only (invariant #1, DB-trigger-enforced) -- a boundary fixture must
    // control the clock at write time and never UPDATE a row after the fact.
    $weekStart = Date::now()->startOfWeek();

    test()->travelTo($weekStart->copy()->subHour());
    $before = Lesson::factory()->create(['price' => 10000, 'commission_pct' => 25, 'commission_amount' => 2500, 'tutor_amount' => 7500]);
    $ledger = app(LedgerService::class);
    $ledger->hold($before);
    $ledger->release($before);
    test()->travelBack();

    expect(admMetrics()->revenueThisWeek()->toFils())->toBe(0);

    test()->travelTo($weekStart->copy()->addHour());
    $after = Lesson::factory()->create(['price' => 10000, 'commission_pct' => 25, 'commission_amount' => 2500, 'tutor_amount' => 7500]);
    $ledger->hold($after);
    $ledger->release($after);
    test()->travelBack();

    expect(admMetrics()->revenueThisWeek()->toFils())->toBe(2500);
});

it('excludes a Platform entry written at the exact next-week instant, so it never double-counts into both weeks', function () {
    // CYCLE-LOG 2026-09-29 11:11 ADVISOR: proves the half-open range at the one instant a
    // `whereBetween` (inclusive both ends) would have counted a boundary-instant entry into both
    // this week and next -- not just "an hour either side" of it.
    $weekStart = Date::now()->startOfWeek();
    $ledger = app(LedgerService::class);

    test()->travelTo($weekStart->copy()->addWeek());
    $lesson = Lesson::factory()->create(['price' => 10000, 'commission_pct' => 25, 'commission_amount' => 2500, 'tutor_amount' => 7500]);
    $ledger->hold($lesson);
    $ledger->release($lesson);
    test()->travelBack();

    expect(admMetrics()->revenueThisWeek()->toFils())->toBe(0);
});

it('sums revenue for the calendar month, excluding a day either side', function () {
    $monthStart = Date::now()->startOfMonth();
    $ledger = app(LedgerService::class);

    test()->travelTo($monthStart->copy()->subDay());
    $before = Lesson::factory()->create(['price' => 10000, 'commission_pct' => 25, 'commission_amount' => 2500, 'tutor_amount' => 7500]);
    $ledger->hold($before);
    $ledger->release($before);
    test()->travelBack();

    expect(admMetrics()->revenueThisMonth()->toFils())->toBe(0);

    test()->travelTo($monthStart->copy()->addDay());
    $inside = Lesson::factory()->create(['price' => 10000, 'commission_pct' => 25, 'commission_amount' => 2500, 'tutor_amount' => 7500]);
    $ledger->hold($inside);
    $ledger->release($inside);
    test()->travelBack();

    expect(admMetrics()->revenueThisMonth()->toFils())->toBe(2500);

    test()->travelTo($monthStart->copy()->addMonthNoOverflow()->addDay());
    $nextMonth = Lesson::factory()->create(['price' => 10000, 'commission_pct' => 25, 'commission_amount' => 2500, 'tutor_amount' => 7500]);
    $ledger->hold($nextMonth);
    $ledger->release($nextMonth);
    test()->travelBack();

    // Only $inside's +2500 falls inside the calendar month -- $before and $nextMonth are outside it.
    expect(admMetrics()->revenueThisMonth()->toFils())->toBe(2500);
});

it('excludes a Platform entry written at the exact next-month instant, so it never double-counts into both months', function () {
    $monthStart = Date::now()->startOfMonth();
    $ledger = app(LedgerService::class);

    test()->travelTo($monthStart->copy()->addMonthNoOverflow());
    $lesson = Lesson::factory()->create(['price' => 10000, 'commission_pct' => 25, 'commission_amount' => 2500, 'tutor_amount' => 7500]);
    $ledger->hold($lesson);
    $ledger->release($lesson);
    test()->travelBack();

    expect(admMetrics()->revenueThisMonth()->toFils())->toBe(0);
});

// ---- reportsOverdue() ---------------------------------------------------------------------------

it('counts a completed lesson past its report_due_at, and excludes one an hour before it', function () {
    Lesson::factory()->withStatus(LessonStatus::Completed)->create(['report_due_at' => Date::now()->subHour()]);
    Lesson::factory()->withStatus(LessonStatus::Completed)->create(['report_due_at' => Date::now()->addHour()]);

    expect(admMetrics()->reportsOverdue())->toBe(1);
});

it('counts an auto-released completed_reported lesson with report_late_at set and no report filed', function () {
    Lesson::factory()->withStatus(LessonStatus::CompletedReported)->create(['report_late_at' => Date::now()->subHour()]);
    // Not late -- excluded.
    Lesson::factory()->withStatus(LessonStatus::CompletedReported)->create(['report_late_at' => null]);

    expect(admMetrics()->reportsOverdue())->toBe(1);
});

// ---- permitsExpiringSoon() ------------------------------------------------------------------

it('counts an approved tutor permit expiring within 30 days, excluding today itself, an already-expired one, and one 31 days out', function () {
    $today = Date::today();

    TutorProfile::factory()->approved()->create(['permit_expires_at' => $today->copy()->addDays(30)]); // inside, boundary inclusive
    TutorProfile::factory()->approved()->create(['permit_expires_at' => $today->copy()->addDays(31)]); // excluded -- one day past the window
    TutorProfile::factory()->approved()->create(['permit_expires_at' => $today]); // excluded -- already today (>, not >=)
    TutorProfile::factory()->approved()->create(['permit_expires_at' => $today->copy()->subDay()]); // excluded -- already expired
    TutorProfile::factory()->create(['status' => TutorProfileStatus::Suspended, 'permit_expires_at' => $today->copy()->addDays(10)]); // excluded -- not approved

    expect(admMetrics()->permitsExpiringSoon())->toBe(1);
});

// ---- failedChargesThisWeek() ----------------------------------------------------------------

it('counts a failed payment updated within the last 7 days, excluding one an hour past the boundary', function () {
    $failedRecent = Payment::factory()->failed()->create();
    $failedRecent->forceFill(['updated_at' => Date::now()->subDays(7)->addHour()])->saveQuietly();

    $failedOld = Payment::factory()->failed()->create();
    $failedOld->forceFill(['updated_at' => Date::now()->subDays(7)->subHour()])->saveQuietly();

    $captured = Payment::factory()->create(['status' => PaymentStatus::Captured]);

    expect(admMetrics()->failedChargesThisWeek())->toBe(1);
});

// ---- openReports() --------------------------------------------------------------------------

it('counts open and reviewing safeguarding reports, excluding closed ones', function () {
    AbuseReport::factory()->create(['status' => AbuseReportStatus::Open, 'subject_type' => AbuseReportSubjectType::TutorProfile, 'subject_id' => TutorProfile::factory()->approved()->create()->id]);
    AbuseReport::factory()->create(['status' => AbuseReportStatus::Reviewing, 'subject_type' => AbuseReportSubjectType::TutorProfile, 'subject_id' => TutorProfile::factory()->approved()->create()->id]);
    AbuseReport::factory()->create(['status' => AbuseReportStatus::Closed, 'subject_type' => AbuseReportSubjectType::TutorProfile, 'subject_id' => TutorProfile::factory()->approved()->create()->id]);

    expect(admMetrics()->openReports())->toBe(2);
});

// ---- openDisputes() -------------------------------------------------------------------------

it('counts open disputes, excluding resolved ones', function () {
    Dispute::factory()->create(['status' => DisputeStatus::Open]);
    Dispute::factory()->resolved()->create();

    expect(admMetrics()->openDisputes())->toBe(1);
});
