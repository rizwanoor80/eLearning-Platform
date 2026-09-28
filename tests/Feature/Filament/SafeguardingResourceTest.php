<?php

use App\Enums\AbuseReportStatus;
use App\Enums\AbuseReportSubjectType;
use App\Enums\CurriculumCode;
use App\Enums\LessonCancelReason;
use App\Enums\LessonStatus;
use App\Enums\PaymentStatus;
use App\Enums\TutorProfileStatus;
use App\Enums\UserStatus;
use App\Filament\Resources\Safeguarding\Pages\ListAbuseReports;
use App\Filament\Resources\Safeguarding\SafeguardingResource;
use App\Models\AbuseReport;
use App\Models\AvailabilityRule;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\LedgerEntry;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

// Kept local to this file — see AbuseReportsTest.php's note on why a test-only helper is never
// shared across test files in this codebase.

/**
 * A confirmed, paid lesson for a given tutor, via the real `LedgerService::hold()` call.
 */
function sgConfirmedLesson(TutorProfile $tutor): Lesson
{
    $parent = User::factory()->create();
    $learner = Learner::factory()->create([
        'account_user_id' => $parent->id,
        'curriculum_id' => Curriculum::query()->firstOrCreate(['code' => CurriculumCode::Gcse], ['name' => CurriculumCode::Gcse->value, 'sort' => 0])->id,
    ]);
    $lesson = Lesson::factory()->create(['tutor_profile_id' => $tutor->id, 'learner_id' => $learner->id]);
    Payment::factory()->create(['lesson_id' => $lesson->id, 'payer_user_id' => $parent->id, 'amount' => $lesson->price]);
    app(LedgerService::class)->hold($lesson);

    return $lesson->fresh();
}

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();

    // Only the one `$this->seed()` test below needs this — `AdminUserSeeder` (part of
    // `DatabaseSeeder`) hard-stops on an unset password by design (R10), and `.env.example`
    // deliberately ships no default (a public repo, so a real value there would be a published
    // admin password). Matches the config-override precedent in DatabaseSeederTest.php /
    // DemoTutorSeederTest.php rather than touching `.env.example` or the seeder's own guard.
    config([
        'seeding.admin.email' => 'admin@project-elearning.test',
        'seeding.admin.password' => 'a-strong-seed-password',
    ]);
});

// ---- access -------------------------------------------------------------------------------------

it('refuses a non-admin and an inactive admin access to the safeguarding queue', function () {
    $tutor = User::factory()->tutor()->create();
    $inactiveAdmin = User::factory()->admin()->create(['status' => UserStatus::Suspended]);

    test()->actingAs($tutor)->get(ListAbuseReports::getUrl())->assertForbidden();
    test()->actingAs($inactiveAdmin)->get(ListAbuseReports::getUrl())->assertForbidden();
});

it('offers no delete action anywhere on the resource', function () {
    $report = AbuseReport::factory()->create();

    expect(SafeguardingResource::canDelete($report))->toBeFalse()
        ->and(SafeguardingResource::canDeleteAny())->toBeFalse()
        ->and(SafeguardingResource::canCreate())->toBeFalse()
        ->and(SafeguardingResource::canEdit($report))->toBeFalse();
});

it('lists reports for an active admin', function () {
    $report = AbuseReport::factory()->create();

    Livewire::actingAs($this->admin)
        ->test(ListAbuseReports::class)
        ->assertCanSeeTableRecords([$report]);
});

// ---- reviewing / close ----------------------------------------------------------------------

it('marks an open report as reviewing and records who picked it up', function () {
    $report = AbuseReport::factory()->create(['status' => AbuseReportStatus::Open]);

    Livewire::actingAs($this->admin)
        ->test(ListAbuseReports::class)
        ->assertTableActionVisible('reviewing', $report)
        ->callTableAction('reviewing', $report);

    expect($report->fresh())
        ->status->toBe(AbuseReportStatus::Reviewing)
        ->handled_by->toBe($this->admin->id);
});

it('hides reviewing once a report is already reviewing or closed', function () {
    $reviewing = AbuseReport::factory()->reviewing()->create();
    $closed = AbuseReport::factory()->closed()->create();

    Livewire::actingAs($this->admin)
        ->test(ListAbuseReports::class)
        ->assertTableActionHidden('reviewing', $reviewing)
        ->assertTableActionHidden('reviewing', $closed);
});

it('closes a report with the admin note as the admin-only action_taken', function () {
    $report = AbuseReport::factory()->create(['status' => AbuseReportStatus::Open]);

    Livewire::actingAs($this->admin)
        ->test(ListAbuseReports::class)
        ->callTableAction('close', $report, ['note' => 'Reviewed, no further action.']);

    expect($report->fresh())
        ->status->toBe(AbuseReportStatus::Closed)
        ->action_taken->toBe('Reviewed, no further action.')
        ->handled_by->toBe($this->admin->id)
        ->closed_at->not->toBeNull();
});

it('hides close once a report is already closed', function () {
    $closed = AbuseReport::factory()->closed()->create();

    Livewire::actingAs($this->admin)
        ->test(ListAbuseReports::class)
        ->assertTableActionHidden('close', $closed);
});

// ---- suspendTutor / reinstateTutor -------------------------------------------------------------

it('offers suspendTutor only for an approved reported tutor', function () {
    $approved = TutorProfile::factory()->approved()->create();
    $pending = TutorProfile::factory()->create(['status' => TutorProfileStatus::PendingReview]);
    $reportOnApproved = AbuseReport::factory()->create(['subject_type' => AbuseReportSubjectType::TutorProfile, 'subject_id' => $approved->id]);
    $reportOnPending = AbuseReport::factory()->create(['subject_type' => AbuseReportSubjectType::TutorProfile, 'subject_id' => $pending->id]);

    Livewire::actingAs($this->admin)
        ->test(ListAbuseReports::class)
        ->assertTableActionVisible('suspendTutor', $reportOnApproved)
        ->assertTableActionHidden('suspendTutor', $reportOnPending);
});

it('suspends the reported tutor with a fixed neutral review_note, never the admin queue note, and closes the report with that note kept admin-only', function () {
    $tutor = TutorProfile::factory()->approved()->create();
    $report = AbuseReport::factory()->create(['status' => AbuseReportStatus::Open, 'subject_type' => AbuseReportSubjectType::TutorProfile, 'subject_id' => $tutor->id]);

    Livewire::actingAs($this->admin)
        ->test(ListAbuseReports::class)
        ->callTableAction('suspendTutor', $report, ['note' => 'Parent alleged inappropriate messages off-platform.']);

    expect($tutor->fresh())
        ->status->toBe(TutorProfileStatus::Suspended)
        ->review_note->toBe('Your tutor profile has been suspended following a safeguarding review.')
        ->review_note->not->toContain('inappropriate');

    expect($report->fresh())
        ->status->toBe(AbuseReportStatus::Closed)
        ->action_taken->toBe('Parent alleged inappropriate messages off-platform.');
});

it('offers reinstateTutor only for a suspended reported tutor, and reinstates them', function () {
    // approvable() + a valid permit so TutorApprovalReadiness has nothing to object to (matching
    // TutorStatusTransitionsTest's trAttempt() precedent) — a bare ['status' => Suspended]
    // profile would legitimately route ReinstateTutor to ChangesRequested instead of Approved.
    $suspended = TutorProfile::factory()->approvable()->create([
        'status' => TutorProfileStatus::Suspended,
        'permit_expires_at' => now()->addYear()->toDateString(),
    ]);
    $approved = TutorProfile::factory()->approved()->create();
    $reportOnSuspended = AbuseReport::factory()->create(['subject_type' => AbuseReportSubjectType::TutorProfile, 'subject_id' => $suspended->id]);
    $reportOnApproved = AbuseReport::factory()->create(['subject_type' => AbuseReportSubjectType::TutorProfile, 'subject_id' => $approved->id]);

    Livewire::actingAs($this->admin)
        ->test(ListAbuseReports::class)
        ->assertTableActionVisible('reinstateTutor', $reportOnSuspended)
        ->assertTableActionHidden('reinstateTutor', $reportOnApproved)
        ->callTableAction('reinstateTutor', $reportOnSuspended);

    expect($suspended->fresh()->status)->toBe(TutorProfileStatus::Approved);
});

// ---- suspendAccount / reinstateAccount -----------------------------------------------------------

it('offers suspendAccount only for an active non-admin reported user', function () {
    $parent = User::factory()->create(['status' => UserStatus::Active]);
    $alreadySuspended = User::factory()->create(['status' => UserStatus::Suspended]);
    $otherAdmin = User::factory()->admin()->create();

    $reportOnParent = AbuseReport::factory()->create(['subject_type' => AbuseReportSubjectType::User, 'subject_id' => $parent->id]);
    $reportOnSuspended = AbuseReport::factory()->create(['subject_type' => AbuseReportSubjectType::User, 'subject_id' => $alreadySuspended->id]);
    $reportOnAdmin = AbuseReport::factory()->create(['subject_type' => AbuseReportSubjectType::User, 'subject_id' => $otherAdmin->id]);

    Livewire::actingAs($this->admin)
        ->test(ListAbuseReports::class)
        ->assertTableActionVisible('suspendAccount', $reportOnParent)
        ->assertTableActionHidden('suspendAccount', $reportOnSuspended)
        ->assertTableActionHidden('suspendAccount', $reportOnAdmin);
});

it('suspends the reported account with the admin note passed directly as suspended_reason (admin-only field)', function () {
    $parent = User::factory()->create();
    $report = AbuseReport::factory()->create(['status' => AbuseReportStatus::Open, 'subject_type' => AbuseReportSubjectType::User, 'subject_id' => $parent->id]);

    Livewire::actingAs($this->admin)
        ->test(ListAbuseReports::class)
        ->callTableAction('suspendAccount', $report, ['note' => 'Repeated harassment reports.']);

    expect($parent->fresh())
        ->status->toBe(UserStatus::Suspended)
        ->suspended_reason->toBe('Repeated harassment reports.');

    expect($report->fresh())
        ->status->toBe(AbuseReportStatus::Closed)
        ->action_taken->toBe('Repeated harassment reports.');
});

it('offers reinstateAccount only for a suspended non-admin reported user, and reinstates them', function () {
    $suspended = User::factory()->create(['status' => UserStatus::Suspended]);
    $active = User::factory()->create(['status' => UserStatus::Active]);
    $suspendedAdmin = User::factory()->admin()->create(['status' => UserStatus::Suspended]);

    $reportOnSuspended = AbuseReport::factory()->create(['subject_type' => AbuseReportSubjectType::User, 'subject_id' => $suspended->id]);
    $reportOnActive = AbuseReport::factory()->create(['subject_type' => AbuseReportSubjectType::User, 'subject_id' => $active->id]);
    $reportOnSuspendedAdmin = AbuseReport::factory()->create(['subject_type' => AbuseReportSubjectType::User, 'subject_id' => $suspendedAdmin->id]);

    Livewire::actingAs($this->admin)
        ->test(ListAbuseReports::class)
        ->assertTableActionVisible('reinstateAccount', $reportOnSuspended)
        ->assertTableActionHidden('reinstateAccount', $reportOnActive)
        ->assertTableActionHidden('reinstateAccount', $reportOnSuspendedAdmin)
        ->callTableAction('reinstateAccount', $reportOnSuspended);

    expect($suspended->fresh()->status)->toBe(UserStatus::Active);
});

// ---- the dedicated ledger:verify-with-suspension-refunds proof (PLAN step 5 done-means) ---------

it('suspending a tutor from the safeguarding queue removes them from search and cancels their reserved lessons without deleting anything, staying ledger-balanced', function () {
    // CP7's done-means says "on a seeded DB" — the real curricula/subjects/price-bands/settings
    // baseline, not just this test's own ad hoc factory rows.
    $this->seed();

    $tutor = TutorProfile::factory()->approved()->create();
    // Every weekday, wide open — so the tutor has a slot in TutorSearch's 14-day window
    // regardless of which day the suite happens to run on, no fixed-clock travel needed.
    foreach (range(0, 6) as $weekday) {
        AvailabilityRule::factory()->create([
            'tutor_profile_id' => $tutor->id,
            'weekday' => $weekday,
            'start_time' => '00:00:00',
            'end_time' => '23:00:00',
            'timezone' => 'UTC',
        ]);
    }

    // A confirmed, paid lesson: must be refunded in full, not just cancelled.
    $confirmed = sgConfirmedLesson($tutor);
    // A reserved (future, unpaid) lesson: must be released free, no ledger call needed for it.
    // A distinct slot (well clear of sgConfirmedLesson()'s default +3 days/startOfHour) so the
    // two lessons for the same tutor never trip the `lessons_tutor_no_overlap` exclusion constraint.
    $reserved = Lesson::factory()->create([
        'tutor_profile_id' => $tutor->id,
        'status' => LessonStatus::Reserved,
        'starts_at' => now()->addDays(10)->startOfHour(),
        'ends_at' => now()->addDays(10)->startOfHour()->addHour(),
    ]);

    $report = AbuseReport::factory()->create(['status' => AbuseReportStatus::Open, 'subject_type' => AbuseReportSubjectType::TutorProfile, 'subject_id' => $tutor->id]);

    // Proven through the real, uncached search endpoint (TutorSearch's own docblock: "nothing
    // here is cached, so a tutor suspended a second ago is gone on the next request") — not just
    // the bookable() scope, which is the mechanism, not the user-facing claim CP7 makes.
    $before = test()->get(route('tutors.index'))->assertOk();
    $before->assertInertia(fn ($page) => $page->where('tutors', fn ($rows) => collect($rows)->pluck('id')->contains($tutor->id)));

    $lessonsBefore = Lesson::query()->count();
    $paymentsBefore = Payment::query()->count();
    $ledgerEntriesBefore = LedgerEntry::query()->count();
    $abuseReportsBefore = AbuseReport::query()->count();

    Livewire::actingAs($this->admin)
        ->test(ListAbuseReports::class)
        ->callTableAction('suspendTutor', $report, ['note' => 'Safeguarding concern upheld.']);

    // Invariant #5: bookable() excludes anything but Approved — suspension alone removes the tutor from search.
    expect($tutor->fresh())->status->toBe(TutorProfileStatus::Suspended)
        ->and(TutorProfile::bookable()->whereKey($tutor->id)->exists())->toBeFalse();

    $after = test()->get(route('tutors.index'))->assertOk();
    $after->assertInertia(fn ($page) => $page->where('tutors', fn ($rows) => ! collect($rows)->pluck('id')->contains($tutor->id)));

    expect($confirmed->fresh())
        ->status->toBe(LessonStatus::CancelledByTutor)
        ->cancel_reason->toBe(LessonCancelReason::TutorSuspended->value);
    expect($reserved->fresh())
        ->status->toBe(LessonStatus::CancelledByTutor)
        ->cancel_reason->toBe(LessonCancelReason::TutorSuspended->value);

    // The confirmed lesson was genuinely refunded (its Payment flips to Refunded, matching
    // AutoChargeTest's precedent for the same `LessonSettlement::refundParent()` call) — a bare
    // ledger-entry-count-grew check would also pass on a hold that was merely released to the
    // tutor by mistake, so this is the assertion that actually distinguishes "refunded" from
    // "paid out" or "left alone".
    expect(Payment::query()->where('lesson_id', $confirmed->id)->sole())
        ->status->toBe(PaymentStatus::Refunded)
        ->refunded_amount->toFils()->toBe($confirmed->price->toFils());

    // The reserved lesson was released free — it was never charged, so it must carry no ledger
    // entries at all (a hold would have been the bug: `hold()` requires an actual Payment row).
    expect(LedgerEntry::query()->where('lesson_id', $reserved->id)->count())->toBe(0);

    // Nothing was deleted (CP7's acceptance line): every row count stayed at or above what it was,
    // ledger entries only ever grow (the refund is new entries, never an edit or delete of a hold).
    expect(Lesson::query()->count())->toBe($lessonsBefore)
        ->and(Payment::query()->count())->toBe($paymentsBefore)
        ->and(LedgerEntry::query()->count())->toBeGreaterThan($ledgerEntriesBefore)
        ->and(AbuseReport::query()->count())->toBe($abuseReportsBefore);

    expect(app(LedgerService::class)->sum($confirmed->fresh()))->toBe(0);
    expect(Artisan::call('ledger:verify'))->toBe(0);
});
