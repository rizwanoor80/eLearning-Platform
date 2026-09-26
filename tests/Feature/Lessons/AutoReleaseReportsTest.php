<?php

use App\Actions\Lessons\MarkNoShow;
use App\Actions\Lessons\MarkProviderFailure;
use App\Actions\Lessons\RecordAttendance;
use App\Actions\Lessons\ReviewLateReports;
use App\Actions\Lessons\SubmitProgressReport;
use App\Enums\CurriculumCode;
use App\Enums\LedgerAccount;
use App\Enums\LedgerEntryType;
use App\Enums\LessonStatus;
use App\Enums\SettingGroup;
use App\Enums\StrikeType;
use App\Enums\VideoParticipant;
use App\Events\Tutor\TutorLateReportsFlagged;
use App\Exceptions\ProgressReportException;
use App\Mail\Admin\AdminLateReportsMail;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\LedgerEntry;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\TutorProfile;
use App\Models\TutorStrike;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Lessons\LessonStateMachine;
use App\Support\Facades\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

afterEach(fn () => Carbon::setTestNow());

/**
 * A paid, held `completed` lesson for `$tutor` (a fresh one when null) that ended `$endedHoursAgo` hours ago,
 * with `auto_release_at` frozen 72 h after its end unless `$autoReleaseAt` says otherwise.
 *
 * @return array{lesson: Lesson, tutor: TutorProfile, parent: User}
 */
function arCompleted(int $endedHoursAgo, ?TutorProfile $tutor = null, mixed $autoReleaseAt = 'default', bool $hold = true): array
{
    $tutor ??= TutorProfile::factory()->approved()->create();
    // A tutor cannot hold two overlapping lessons: each further lesson for the same tutor sits two hours earlier.
    $earlier = 2 * Lesson::query()->where('tutor_profile_id', $tutor->id)->count();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create([
        'account_user_id' => $parent->id,
        'curriculum_id' => Curriculum::query()->firstOrCreate(['code' => CurriculumCode::Gcse], ['name' => CurriculumCode::Gcse->value, 'sort' => 0])->id,
    ]);
    $endsAt = now()->subHours($endedHoursAgo + $earlier);

    $lesson = Lesson::factory()->withStatus(LessonStatus::Completed)->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'starts_at' => $endsAt->copy()->subHour(),
        'ends_at' => $endsAt,
        'completed_at' => $endsAt,
        'report_due_at' => $endsAt->copy()->addHours(24),
        'auto_release_at' => $autoReleaseAt === 'default' ? $endsAt->copy()->addHours(72) : $autoReleaseAt,
    ]);
    Payment::factory()->create(['lesson_id' => $lesson->id, 'payer_user_id' => $parent->id, 'amount' => $lesson->price]);
    if ($hold) {
        app(LedgerService::class)->hold($lesson);
    }

    return ['lesson' => $lesson->fresh(), 'tutor' => $tutor, 'parent' => $parent];
}

function arEntries(Lesson $lesson): int
{
    return LedgerEntry::query()->where('lesson_id', $lesson->id)->count();
}

// ---- the sweep ----------------------------------------------------------------------------------------------

it('releases a lesson at its frozen deadline and not a minute before, flagging it late', function () {
    Mail::fake();
    ['lesson' => $lesson] = arCompleted(71);

    $this->artisan('lessons:auto-release-reports')->assertSuccessful();
    expect($lesson->fresh()->status)->toBe(LessonStatus::Completed)->and(arEntries($lesson))->toBe(2);

    Carbon::setTestNow(now()->addHour()->addMinute());
    $this->artisan('lessons:auto-release-reports')->assertSuccessful();

    $ledger = app(LedgerService::class);
    $lesson->refresh();
    expect($lesson->status)->toBe(LessonStatus::CompletedReported)
        ->and($lesson->escrow_released_at)->not->toBeNull()
        ->and($lesson->report_late_at)->not->toBeNull()
        ->and($ledger->balance($lesson, LedgerAccount::Escrow))->toBe(0)
        ->and($ledger->balance($lesson, LedgerAccount::Tutor))->toBe($lesson->tutor_amount->toFils())
        ->and($ledger->sum($lesson))->toBe(0);

    $this->artisan('ledger:verify --no-interaction')->assertSuccessful();
});

it('releases once: a second run adds no ledger entry and keeps the first flag time', function () {
    Mail::fake();
    ['lesson' => $lesson] = arCompleted(80);

    $this->artisan('lessons:auto-release-reports')->assertSuccessful();
    $entries = arEntries($lesson);
    $flaggedAt = $lesson->fresh()->report_late_at;

    Carbon::setTestNow(now()->addHours(5));
    $this->artisan('lessons:auto-release-reports')->assertSuccessful();

    expect(arEntries($lesson))->toBe($entries)
        ->and(LedgerEntry::query()->where('lesson_id', $lesson->id)->where('type', LedgerEntryType::ReleaseTutor)->count())->toBe(2)
        ->and($lesson->fresh()->report_late_at->toIso8601String())->toBe($flaggedAt->toIso8601String())
        ->and(app(LedgerService::class)->sum($lesson))->toBe(0);
});

it('uses the deadline frozen on the lesson, not the current setting', function () {
    Mail::fake();
    ['lesson' => $lesson] = arCompleted(30); // due in 42 h by its own frozen deadline

    Settings::set('auto_release_hours', 1, SettingGroup::Platform);

    try {
        $this->artisan('lessons:auto-release-reports')->assertSuccessful();
        expect($lesson->fresh()->status)->toBe(LessonStatus::Completed)->and(arEntries($lesson))->toBe(2);
    } finally {
        Settings::set('auto_release_hours', 72, SettingGroup::Platform);
    }
});

it('falls back to the end plus the setting for a lesson completed before the deadline was frozen', function () {
    Mail::fake();
    ['lesson' => $old] = arCompleted(73, autoReleaseAt: null);
    ['lesson' => $recent] = arCompleted(10, autoReleaseAt: null);

    $this->artisan('lessons:auto-release-reports')->assertSuccessful();

    // Released, but not flagged: it never had a report form to be late for.
    expect($old->fresh()->status)->toBe(LessonStatus::CompletedReported)
        ->and($old->fresh()->report_late_at)->toBeNull()
        ->and($recent->fresh()->status)->toBe(LessonStatus::Completed);
});

it('leaves a disputed, an already-reported and a not-yet-due lesson alone', function () {
    Mail::fake();
    ['lesson' => $disputed] = arCompleted(100);
    LessonStateMachine::transition($disputed, LessonStatus::Disputed);
    ['lesson' => $reported, 'tutor' => $tutor] = arCompleted(100);
    app(SubmitProgressReport::class)($tutor->user, $reported, [
        'topics_covered' => 'a', 'went_well' => 'b', 'work_on_next' => 'c', 'homework' => 'd', 'engagement' => 3,
    ]);
    ['lesson' => $early] = arCompleted(2);
    $entries = [arEntries($disputed), arEntries($reported), arEntries($early)];

    $this->artisan('lessons:auto-release-reports')->assertSuccessful();

    expect($disputed->fresh()->status)->toBe(LessonStatus::Disputed)
        ->and($disputed->fresh()->report_late_at)->toBeNull()
        ->and($reported->fresh()->report_late_at)->toBeNull()
        ->and($early->fresh()->status)->toBe(LessonStatus::Completed)
        ->and([arEntries($disputed), arEntries($reported), arEntries($early)])->toBe($entries);
});

it('keeps going past a lesson whose release fails, and releases the rest', function () {
    Mail::fake();
    ['lesson' => $broken] = arCompleted(90, hold: false); // no escrow held: release() refuses
    ['lesson' => $fine] = arCompleted(90);

    $this->artisan('lessons:auto-release-reports')->assertSuccessful();

    expect($broken->fresh()->status)->toBe(LessonStatus::Completed)
        ->and($broken->fresh()->escrow_released_at)->toBeNull()
        ->and($fine->fresh()->status)->toBe(LessonStatus::CompletedReported);
});

// ---- three late flags in 90 days ------------------------------------------------------------------------

it('opens one admin review on the third late flag in 90 days, and none on a fourth', function () {
    Mail::fake();
    User::factory()->admin()->create();
    $tutor = TutorProfile::factory()->approved()->create();

    foreach ([1, 2] as $n) {
        ['lesson' => $lesson] = arCompleted(80, $tutor);
        $this->artisan('lessons:auto-release-reports')->assertSuccessful();
    }
    expect(TutorStrike::query()->where('type', StrikeType::LateReportX3)->count())->toBe(0);
    Mail::assertNothingQueued();

    ['lesson' => $third] = arCompleted(80, $tutor);
    $this->artisan('lessons:auto-release-reports')->assertSuccessful();

    $strike = TutorStrike::query()->where('type', StrikeType::LateReportX3)->sole();
    expect($strike->tutor_profile_id)->toBe($tutor->id)->and($strike->lesson_id)->toBe($third->id);
    Mail::assertQueued(AdminLateReportsMail::class, 1);

    ['lesson' => $fourth] = arCompleted(80, $tutor);
    $this->artisan('lessons:auto-release-reports')->assertSuccessful();

    expect($fourth->fresh()->report_late_at)->not->toBeNull()
        ->and(TutorStrike::query()->where('type', StrikeType::LateReportX3)->count())->toBe(1);
    Mail::assertQueued(AdminLateReportsMail::class, 1);
    expect($tutor->fresh()->status->value)->toBe('approved'); // history and an email, not a suspension
});

it('counts only flags inside the 90-day window, and only this tutor\'s', function () {
    Mail::fake();
    $tutor = TutorProfile::factory()->approved()->create();
    $other = TutorProfile::factory()->approved()->create();

    foreach ([1, 2] as $n) {
        // Not yet due, so the sweep leaves their old flags alone.
        ['lesson' => $stale] = arCompleted(1, $tutor);
        $stale->forceFill(['report_late_at' => now()->subDays(100)])->save();
        ['lesson' => $theirs] = arCompleted(1, $other);
        $theirs->forceFill(['report_late_at' => now()->subDay()])->save();
    }
    ['lesson' => $lesson] = arCompleted(80, $tutor);

    $this->artisan('lessons:auto-release-reports')->assertSuccessful();

    expect($lesson->fresh()->report_late_at)->not->toBeNull()
        ->and(TutorStrike::query()->where('type', StrikeType::LateReportX3)->count())->toBe(0);
});

it('dispatches the review event once, only when the review opens', function () {
    Event::fake([TutorLateReportsFlagged::class]);
    $tutor = TutorProfile::factory()->approved()->create();

    foreach ([1, 2, 3] as $n) {
        ['lesson' => $lesson] = arCompleted(80, $tutor);
        $lesson->forceFill(['report_late_at' => now()->subDays($n)])->save();
    }

    expect(app(ReviewLateReports::class)($tutor))->toBeTrue()
        ->and(app(ReviewLateReports::class)($tutor))->toBeFalse();

    Event::assertDispatchedTimes(TutorLateReportsFlagged::class, 1);
});

// ---- every money path leaves the ledger balanced ----------------------------------------------------------------

it('leaves every lesson\'s ledger at zero after each path: report, second submit, auto-release, provider failure, both no-shows', function () {
    Mail::fake();
    $ledger = app(LedgerService::class);
    $check = function (Lesson $lesson) use ($ledger): void {
        expect($ledger->sum($lesson))->toBe(0);
        test()->artisan('ledger:verify --no-interaction')->assertSuccessful();
    };
    $data = ['topics_covered' => 'a', 'went_well' => 'b', 'work_on_next' => 'c', 'homework' => 'd', 'engagement' => 3];

    // Report.
    ['lesson' => $reported, 'tutor' => $tutor] = arCompleted(3);
    app(SubmitProgressReport::class)($tutor->user, $reported, $data);
    $check($reported);

    // Second submit, rejected.
    expect(fn () => app(SubmitProgressReport::class)($tutor->user, $reported->fresh(), $data))->toThrow(ProgressReportException::class);
    $check($reported);

    // Auto-release.
    ['lesson' => $late] = arCompleted(80);
    test()->artisan('lessons:auto-release-reports')->assertSuccessful();
    expect($late->fresh()->status)->toBe(LessonStatus::CompletedReported);
    $check($late);

    // Provider failure (admin).
    $admin = User::factory()->admin()->create();
    ['lesson' => $failed] = arUnattended(5);
    app(MarkProviderFailure::class)($admin, $failed, 'The video service was down');
    expect($failed->fresh()->status)->toBe(LessonStatus::ProviderFailure);
    $check($failed);

    // Both no-shows: nobody joined, the settle sweep refunds.
    ['lesson' => $nobody] = arUnattended(90);
    test()->artisan('lessons:settle-ended')->assertSuccessful();
    expect($nobody->fresh()->status)->toBe(LessonStatus::Refunded);
    $check($nobody);

    // The tutor-absent and student-absent no-shows, for completeness of the money paths this cycle sits beside.
    ['lesson' => $tutorAbsent, 'parent' => $parent] = arUnattended(30, [VideoParticipant::Learner]);
    app(MarkNoShow::class)($parent, $tutorAbsent);
    $check($tutorAbsent);

    expect(LedgerEntry::query()->where('lesson_id', $reported->id)->where('type', LedgerEntryType::ReleaseTutor)->count())->toBe(2)
        ->and(LedgerEntry::query()->where('lesson_id', $late->id)->where('type', LedgerEntryType::ReleaseTutor)->count())->toBe(2)
        ->and(LedgerEntry::query()->whereIn('lesson_id', [$failed->id, $nobody->id, $tutorAbsent->id])->where('type', LedgerEntryType::ReleaseTutor)->count())->toBe(0);
});

/**
 * A paid, held, confirmed lesson that started `$startedMinutesAgo` minutes ago, with a room, and the given sides joined.
 *
 * @param  list<VideoParticipant>  $joined
 * @return array{lesson: Lesson, tutor: TutorProfile, parent: User}
 */
function arUnattended(int $startedMinutesAgo, array $joined = []): array
{
    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create([
        'account_user_id' => $parent->id,
        'curriculum_id' => Curriculum::query()->firstOrCreate(['code' => CurriculumCode::Gcse], ['name' => CurriculumCode::Gcse->value, 'sort' => 0])->id,
    ]);
    $lesson = Lesson::factory()->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'starts_at' => now()->subMinutes($startedMinutesAgo),
        'ends_at' => now()->subMinutes($startedMinutesAgo)->addHour(),
    ]);
    Payment::factory()->create(['lesson_id' => $lesson->id, 'payer_user_id' => $parent->id, 'amount' => $lesson->price]);
    app(LedgerService::class)->hold($lesson);
    $lesson->forceFill(['room_provider' => 'fake', 'room_id' => 'lesson-'.$lesson->id, 'room_created_at' => now()->subHour()])->save();

    foreach ($joined as $who) {
        app(RecordAttendance::class)($lesson->fresh(), $who, now()->subMinutes($startedMinutesAgo - 1));
    }

    return ['lesson' => $lesson->fresh(), 'tutor' => $tutor, 'parent' => $parent];
}
