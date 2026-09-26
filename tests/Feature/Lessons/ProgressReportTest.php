<?php

use App\Actions\Lessons\AutoReleaseLesson;
use App\Actions\Lessons\SettleEndedLesson;
use App\Actions\Lessons\SubmitProgressReport;
use App\Enums\CurriculumCode;
use App\Enums\LedgerAccount;
use App\Enums\LedgerEntryType;
use App\Enums\LessonStatus;
use App\Enums\SettingGroup;
use App\Events\Lessons\ProgressReportSubmitted;
use App\Exceptions\LedgerException;
use App\Exceptions\LessonTransitionException;
use App\Exceptions\ProgressReportException;
use App\Listeners\Lessons\SendProgressReportMail;
use App\Mail\Lessons\ProgressReportMail;
use App\Mail\Lessons\ReportDueMail;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\LedgerEntry;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\ProgressReport;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Lessons\LessonStateMachine;
use App\Support\Facades\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

afterEach(fn () => Carbon::setTestNow());

/**
 * A paid, held lesson that ended two hours ago and is `completed` (waiting for its report), with the two
 * deadlines frozen on it the way `SettleEndedLesson` freezes them.
 *
 * @return array{lesson: Lesson, tutor: TutorProfile, parent: User}
 */
function prSetup(LessonStatus $status = LessonStatus::Completed, bool $trial = false, bool $hold = true): array
{
    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create([
        'account_user_id' => $parent->id,
        'curriculum_id' => Curriculum::query()->firstOrCreate(['code' => CurriculumCode::Gcse], ['name' => CurriculumCode::Gcse->value, 'sort' => 0])->id,
    ]);

    $factory = Lesson::factory()->withStatus($status);
    $lesson = ($trial ? $factory->trial() : $factory)->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'starts_at' => now()->subHours(3),
        'ends_at' => now()->subHours(2),
        'completed_at' => now()->subHours(2),
        'report_due_at' => now()->addHours(22),
        'auto_release_at' => now()->addHours(70),
    ]);
    Payment::factory()->create(['lesson_id' => $lesson->id, 'payer_user_id' => $parent->id, 'amount' => $lesson->price]);
    if ($hold) {
        app(LedgerService::class)->hold($lesson);
    }

    return ['lesson' => $lesson->fresh(), 'tutor' => $tutor, 'parent' => $parent];
}

/**
 * @return array<string, mixed>
 */
function prData(bool $trial = false): array
{
    return [
        'topics_covered' => 'Fractions and ratios.',
        'went_well' => 'Confident with the worked examples.',
        'work_on_next' => 'Word problems.',
        'homework' => 'Exercise 4b.',
        'engagement' => 4,
        ...($trial ? [
            'trial_suitability' => 'good_fit',
            'trial_recommended_frequency' => 2,
            'trial_focus_areas' => 'Algebra fluency before the mock exam.',
        ] : []),
    ];
}

function prReleaseEntries(Lesson $lesson): int
{
    return LedgerEntry::query()->where('lesson_id', $lesson->id)->where('type', LedgerEntryType::ReleaseTutor)->count();
}

// ---- the report releases the money, once -------------------------------------------------------------

it('stores the report, releases the tutor once and moves the lesson to completed_reported', function () {
    Mail::fake();
    ['lesson' => $lesson, 'tutor' => $tutor] = prSetup();

    actingAs($tutor->user)->post(route('lessons.report.store', $lesson), prData())
        ->assertRedirect(route('lessons.show', $lesson));

    $ledger = app(LedgerService::class);
    $lesson->refresh();
    expect($lesson->status)->toBe(LessonStatus::CompletedReported)
        ->and($lesson->escrow_released_at)->not->toBeNull()
        ->and($lesson->report_late_at)->toBeNull()
        ->and(ProgressReport::query()->where('lesson_id', $lesson->id)->sole()->tutor_profile_id)->toBe($tutor->id)
        ->and(prReleaseEntries($lesson))->toBe(2)
        ->and($ledger->balance($lesson, LedgerAccount::Escrow))->toBe(0)
        ->and($ledger->balance($lesson, LedgerAccount::Tutor))->toBe($lesson->tutor_amount->toFils())
        ->and($ledger->sum($lesson))->toBe(0);

    $this->artisan('ledger:verify --no-interaction')->assertSuccessful();
});

it('refuses a second submit and leaves the ledger exactly as the first left it', function () {
    Mail::fake();
    ['lesson' => $lesson, 'tutor' => $tutor] = prSetup();
    app(SubmitProgressReport::class)($tutor->user, $lesson, prData());
    $entries = LedgerEntry::query()->where('lesson_id', $lesson->id)->count();

    expect(fn () => app(SubmitProgressReport::class)($tutor->user, $lesson->fresh(), prData()))->toThrow(ProgressReportException::class);

    actingAs($tutor->user)->post(route('lessons.report.store', $lesson), prData())
        ->assertRedirect(route('lessons.show', $lesson));

    expect(ProgressReport::query()->where('lesson_id', $lesson->id)->count())->toBe(1)
        ->and(prReleaseEntries($lesson))->toBe(2)
        ->and(LedgerEntry::query()->where('lesson_id', $lesson->id)->count())->toBe($entries)
        ->and(app(LedgerService::class)->sum($lesson))->toBe(0);

    $this->artisan('ledger:verify --no-interaction')->assertSuccessful();
});

it('rolls the report back with the release when the ledger refuses', function () {
    Mail::fake();
    ['lesson' => $lesson, 'tutor' => $tutor] = prSetup(hold: false);

    // No escrow is held, so release() must refuse, and nothing of the report may stay.
    expect(fn () => app(SubmitProgressReport::class)($tutor->user, $lesson, prData()))->toThrow(LedgerException::class);

    expect(ProgressReport::query()->count())->toBe(0)
        ->and($lesson->fresh()->status)->toBe(LessonStatus::Completed)
        ->and($lesson->fresh()->escrow_released_at)->toBeNull()
        ->and(prReleaseEntries($lesson))->toBe(0);
});

// ---- the trial fields -------------------------------------------------------------------------------------

it('rejects a trial report without the three trial fields and accepts it with them', function () {
    Mail::fake();
    ['lesson' => $lesson, 'tutor' => $tutor] = prSetup(trial: true);

    actingAs($tutor->user)->post(route('lessons.report.store', $lesson), prData())
        ->assertSessionHasErrors(['trial_suitability', 'trial_recommended_frequency', 'trial_focus_areas']);

    expect(ProgressReport::query()->count())->toBe(0)
        ->and($lesson->fresh()->status)->toBe(LessonStatus::Completed)
        ->and(prReleaseEntries($lesson))->toBe(0);

    actingAs($tutor->user)->post(route('lessons.report.store', $lesson), prData(trial: true))
        ->assertSessionHasNoErrors();

    $report = ProgressReport::query()->where('lesson_id', $lesson->id)->sole();
    expect($report->trial_suitability->value)->toBe('good_fit')
        ->and($report->trial_recommended_frequency)->toBe(2)
        ->and($lesson->fresh()->status)->toBe(LessonStatus::CompletedReported);
});

it('rejects a regular report that carries trial fields', function () {
    Mail::fake();
    ['lesson' => $lesson, 'tutor' => $tutor] = prSetup();

    actingAs($tutor->user)->post(route('lessons.report.store', $lesson), prData(trial: true))
        ->assertSessionHasErrors(['trial_suitability', 'trial_recommended_frequency', 'trial_focus_areas']);

    expect(ProgressReport::query()->count())->toBe(0)
        ->and($lesson->fresh()->status)->toBe(LessonStatus::Completed);
});

it('checks the trial fields against the lesson, not the request, in the action too', function () {
    Mail::fake();
    ['lesson' => $regular, 'tutor' => $tutorA] = prSetup();
    ['lesson' => $trial, 'tutor' => $tutorB] = prSetup(trial: true);

    expect(fn () => app(SubmitProgressReport::class)($tutorA->user, $regular, prData(trial: true)))->toThrow(ProgressReportException::class)
        ->and(fn () => app(SubmitProgressReport::class)($tutorB->user, $trial, prData()))->toThrow(ProgressReportException::class);

    expect(ProgressReport::query()->count())->toBe(0)
        ->and(prReleaseEntries($regular) + prReleaseEntries($trial))->toBe(0);
});

it('needs every one of the five fields, and an engagement between one and five', function () {
    Mail::fake();
    ['lesson' => $lesson, 'tutor' => $tutor] = prSetup();

    actingAs($tutor->user)->post(route('lessons.report.store', $lesson), [...prData(), 'topics_covered' => '', 'engagement' => 6])
        ->assertSessionHasErrors(['topics_covered', 'engagement']);

    expect(ProgressReport::query()->count())->toBe(0);
});

// ---- who may, and when ------------------------------------------------------------------------------

it('lets only the lesson\'s own tutor open or submit the report', function () {
    ['lesson' => $lesson, 'parent' => $parent] = prSetup();
    $otherTutor = TutorProfile::factory()->approved()->create();
    $admin = User::factory()->admin()->create();

    foreach ([$parent, $otherTutor->user, $admin] as $user) {
        actingAs($user)->get(route('lessons.report.create', $lesson))->assertForbidden();
        actingAs($user)->post(route('lessons.report.store', $lesson), prData())->assertForbidden();
    }

    auth()->logout();
    $this->get(route('lessons.report.create', $lesson))->assertRedirect(route('login'));
    $this->post(route('lessons.report.store', $lesson), prData())->assertRedirect(route('login'));

    expect(ProgressReport::query()->count())->toBe(0)->and(prReleaseEntries($lesson))->toBe(0);
});

it('opens the report form for the tutor, with the trial fields only on a trial', function () {
    ['lesson' => $regular, 'tutor' => $tutorA] = prSetup();
    ['lesson' => $trial, 'tutor' => $tutorB] = prSetup(trial: true);

    actingAs($tutorA->user)->get(route('lessons.report.create', $regular))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('lessons/Report')->where('lesson.is_trial', false)->where('lesson.released_already', false));
    actingAs($tutorB->user)->get(route('lessons.report.create', $trial))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('lessons/Report')->where('lesson.is_trial', true));
});

it('takes no report on a disputed lesson, and none on a lesson that is not waiting for one', function () {
    Mail::fake();
    ['lesson' => $disputed, 'tutor' => $tutor] = prSetup(LessonStatus::Disputed);
    ['lesson' => $noShow, 'tutor' => $noShowTutor] = prSetup(LessonStatus::CompletedReported); // a no-show pay: no late flag
    ['lesson' => $confirmed, 'tutor' => $confirmedTutor] = prSetup(LessonStatus::Confirmed);

    expect(fn () => app(SubmitProgressReport::class)($tutor->user, $disputed, prData()))->toThrow(ProgressReportException::class)
        ->and(fn () => app(SubmitProgressReport::class)($noShowTutor->user, $noShow, prData()))->toThrow(ProgressReportException::class)
        ->and(fn () => app(SubmitProgressReport::class)($confirmedTutor->user, $confirmed, prData()))->toThrow(ProgressReportException::class);

    actingAs($tutor->user)->get(route('lessons.report.create', $disputed))->assertRedirect(route('lessons.show', $disputed));

    expect(ProgressReport::query()->count())->toBe(0)
        ->and(prReleaseEntries($disputed) + prReleaseEntries($noShow) + prReleaseEntries($confirmed))->toBe(0);
});

it('shows the tutor the write-the-report prompt on the lesson page, and nobody else', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'parent' => $parent] = prSetup();

    actingAs($tutor->user)->get(route('lessons.show', $lesson))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('lesson.can_report', true));
    actingAs($parent)->get(route('lessons.show', $lesson))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('lesson.can_report', false));

    Mail::fake();
    app(SubmitProgressReport::class)($tutor->user, $lesson, prData());

    actingAs($tutor->user)->get(route('lessons.show', $lesson))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('lesson.can_report', false));
});

it('lists a tutor\'s completed lessons under reports due on the dashboard, and only theirs', function () {
    ['lesson' => $lesson, 'tutor' => $tutor] = prSetup();
    prSetup(); // another tutor's

    actingAs($tutor->user)->get(route('tutor.dashboard'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('reportsDue', 1)->where('reportsDue.0.id', $lesson->id));

    Mail::fake();
    app(SubmitProgressReport::class)($tutor->user, $lesson, prData());

    actingAs($tutor->user)->get(route('tutor.dashboard'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('reportsDue', 0));
});

// ---- exactly once against the 72 h sweep ---------------------------------------------------------------

it('releases once when a submit and the sweep land on the same lesson, whichever comes first', function () {
    Mail::fake();
    ['lesson' => $first, 'tutor' => $tutorA] = prSetup();
    ['lesson' => $second, 'tutor' => $tutorB] = prSetup();

    // Report first, then the sweep finds nothing due.
    app(SubmitProgressReport::class)($tutorA->user, $first, prData());
    Carbon::setTestNow(now()->addDays(4));
    $this->artisan('lessons:auto-release-reports')->assertSuccessful();

    expect(prReleaseEntries($first))->toBe(2)->and($first->fresh()->report_late_at)->toBeNull();

    // Sweep first, then the late report is stored and nothing moves again.
    $this->artisan('lessons:auto-release-reports')->assertSuccessful();
    expect(prReleaseEntries($second))->toBe(2)->and($second->fresh()->report_late_at)->not->toBeNull();
    $entries = LedgerEntry::query()->where('lesson_id', $second->id)->count();

    app(SubmitProgressReport::class)($tutorB->user, $second->fresh(), prData());

    expect(ProgressReport::query()->where('lesson_id', $second->id)->count())->toBe(1)
        ->and($second->fresh()->status)->toBe(LessonStatus::CompletedReported)
        ->and(LedgerEntry::query()->where('lesson_id', $second->id)->count())->toBe($entries)
        ->and(app(LedgerService::class)->sum($second))->toBe(0);

    // A second late report is refused like any second report.
    expect(fn () => app(SubmitProgressReport::class)($tutorB->user, $second->fresh(), prData()))->toThrow(ProgressReportException::class);

    $this->artisan('ledger:verify --no-interaction')->assertSuccessful();
});

it('refuses a submit whose lesson moved to disputed after the form was opened', function () {
    Mail::fake();
    ['lesson' => $lesson, 'tutor' => $tutor] = prSetup();
    $stale = Lesson::query()->findOrFail($lesson->id); // what the controller holds

    LessonStateMachine::transition($lesson, LessonStatus::Disputed);

    expect(fn () => app(SubmitProgressReport::class)($tutor->user, $stale, prData()))->toThrow(ProgressReportException::class);
    expect(prReleaseEntries($lesson))->toBe(0)->and(ProgressReport::query()->count())->toBe(0);
});

it('cannot release a lesson a report already moved, even when asked directly', function () {
    Mail::fake();
    ['lesson' => $lesson, 'tutor' => $tutor] = prSetup();
    app(SubmitProgressReport::class)($tutor->user, $lesson, prData());

    expect(fn () => app(AutoReleaseLesson::class)($lesson->fresh()))->toThrow(LessonTransitionException::class);
    expect(prReleaseEntries($lesson))->toBe(2);
});

// ---- the deadlines are frozen on the lesson ----------------------------------------------------------------

it('freezes the report deadline and the auto-release deadline when a lesson completes', function () {
    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id]);
    $lesson = Lesson::factory()->withStatus(LessonStatus::InProgress)->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'starts_at' => now()->subHours(2),
        'ends_at' => now()->subHour(),
        'tutor_joined_at' => now()->subHours(2),
        'learner_joined_at' => now()->subHours(2),
    ]);
    $endsAt = $lesson->ends_at->copy();

    Mail::fake();
    app(SettleEndedLesson::class)($lesson);
    Settings::set('auto_release_hours', 6, SettingGroup::Platform);
    Settings::set('report_due_hours', 1, SettingGroup::Platform);

    $lesson->refresh();
    expect($lesson->auto_release_at->toIso8601String())->toBe($endsAt->copy()->addHours(72)->toIso8601String())
        ->and($lesson->report_due_at->toIso8601String())->toBe($endsAt->copy()->addHours(24)->toIso8601String());

    Settings::set('auto_release_hours', 72, SettingGroup::Platform);
    Settings::set('report_due_hours', 24, SettingGroup::Platform);
});

// ---- the emails -----------------------------------------------------------------------------------------------

it('emails the report to the learner\'s account holder once, and never to the learner', function () {
    Mail::fake();
    ['lesson' => $lesson, 'tutor' => $tutor, 'parent' => $parent] = prSetup();

    $report = app(SubmitProgressReport::class)($tutor->user, $lesson, prData());

    Mail::assertQueued(ProgressReportMail::class, 1);
    Mail::assertQueued(ProgressReportMail::class, fn (ProgressReportMail $m) => $m->hasTo($parent->email) && $m->recipient->is($parent) && $m->report->is($report));
    expect($report->fresh()->emailed_at)->not->toBeNull();

    // A duplicate delivery of the same event sends nothing more.
    ProgressReportSubmitted::dispatch($report);
    ProgressReportSubmitted::dispatch($report);
    Mail::assertQueued(ProgressReportMail::class, 1);
});

it('gives the email claim back when the mail cannot be handed over, so the retry still sends it', function () {
    ['lesson' => $lesson, 'tutor' => $tutor] = prSetup();
    Mail::fake();
    $report = app(SubmitProgressReport::class)($tutor->user, $lesson, prData());
    $report->forceFill(['emailed_at' => null])->save();

    Mail::swap(new class
    {
        public function to(): never
        {
            throw new RuntimeException('queue unreachable');
        }
    });
    expect(fn () => app(SendProgressReportMail::class)->handle(new ProgressReportSubmitted($report)))->toThrow(RuntimeException::class);
    expect($report->fresh()->emailed_at)->toBeNull();

    app()->forgetInstance('mail.manager');
    Mail::clearResolvedInstances();
    Mail::fake();
    app(SendProgressReportMail::class)->handle(new ProgressReportSubmitted($report));
    Mail::assertQueued(ProgressReportMail::class, 1);
    expect($report->fresh()->emailed_at)->not->toBeNull();
});

it('sends no email when the submit fails', function () {
    Mail::fake();
    ['lesson' => $lesson, 'parent' => $parent] = prSetup();

    expect(fn () => app(SubmitProgressReport::class)($parent, $lesson, prData()))->toThrow(ProgressReportException::class);

    Mail::assertNothingQueued();
});

it('puts the trial fields and a working weekly-slot link in the trial email, and none in a regular one', function () {
    Mail::fake();
    ['lesson' => $trial, 'tutor' => $tutorA, 'parent' => $parentA] = prSetup(trial: true);
    ['lesson' => $regular, 'tutor' => $tutorB] = prSetup();

    $trialReport = app(SubmitProgressReport::class)($tutorA->user, $trial, prData(trial: true));
    $regularReport = app(SubmitProgressReport::class)($tutorB->user, $regular, prData());

    $link = route('weekly-slots.create', ['tutor' => $trial->tutor_profile_id, 'learner' => $trial->learner_id]);
    $trialHtml = (new ProgressReportMail($trialReport->fresh(), $parentA))->render();
    $regularHtml = (new ProgressReportMail($regularReport->fresh(), $parentA))->render();

    expect($trialHtml)->toContain('A good fit')->toContain('Algebra fluency')->toContain(e($link))
        ->and($regularHtml)->not->toContain('weekly-slots/create')->not->toContain('Recommended frequency');

    actingAs($parentA)->get($link)->assertOk();
});

it('escapes the tutor\'s words in the parent email', function () {
    Mail::fake();
    ['lesson' => $lesson, 'tutor' => $tutor, 'parent' => $parent] = prSetup();

    $report = app(SubmitProgressReport::class)($tutor->user, $lesson, [...prData(), 'went_well' => '<script>alert(1)</script>']);
    $html = (new ProgressReportMail($report->fresh(), $parent))->render();

    expect($html)->not->toContain('<script>alert(1)</script>')->toContain('&lt;script&gt;');
});

it('prompts the tutor for the report when a lesson completes, once', function () {
    Mail::fake();
    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id]);
    $lesson = Lesson::factory()->withStatus(LessonStatus::InProgress)->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'starts_at' => now()->subHours(2),
        'ends_at' => now()->subHour(),
        'tutor_joined_at' => now()->subHours(2),
        'learner_joined_at' => now()->subHours(2),
    ]);

    app(SettleEndedLesson::class)($lesson);
    app(SettleEndedLesson::class)($lesson->fresh()); // a second run: the edge refuses, nothing more is sent

    Mail::assertQueued(ReportDueMail::class, 1);
    Mail::assertQueued(ReportDueMail::class, fn (ReportDueMail $m) => $m->hasTo($tutor->user->email) && ! $m->hasTo($parent->email));
});
