<?php

use App\Enums\CurriculumCode;
use App\Enums\LessonStatus;
use App\Enums\LevelTier;
use App\Mail\Lessons\ProgressReportMail;
use App\Models\AvailabilityRule;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PriceBand;
use App\Models\ProgressReport;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\TutorSubject;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * CP6 (7f): the weekly-slot form opened from a trial report starts on the trial's subject, weekday and
 * hour (when still offered) and shows the tutor's recommended frequency; the learner page carries a
 * timeline of the reports on that learner and the focus areas from the last five. "Now" is Monday
 * 2026-09-14 06:00 UTC.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-14 06:00:00', 'UTC'));
});

/**
 * A bookable tutor who teaches one subject and is free on one weekday in the rule's own timezone.
 *
 * @return array{tutor: TutorProfile, curriculum_id: int, subject_id: int}
 */
function tfTutor(string $timezone = 'UTC', int $weekday = 2, string $from = '09:00:00', string $to = '12:00:00'): array
{
    $curriculum = Curriculum::query()->firstOrCreate(['code' => CurriculumCode::Gcse], ['name' => CurriculumCode::Gcse->value, 'sort' => 0]);
    $subject = Subject::factory()->create();
    $tutor = TutorProfile::factory()->approved()->create(['hourly_rate' => 10000]);

    TutorSubject::factory()->create([
        'tutor_profile_id' => $tutor->id,
        'curriculum_id' => $curriculum->id,
        'subject_id' => $subject->id,
        'level_tier' => LevelTier::LowerSecondary,
    ]);
    PriceBand::query()->firstOrCreate(
        ['curriculum_id' => $curriculum->id, 'level_tier' => LevelTier::LowerSecondary, 'effective_from' => '2000-01-01'],
        ['min_rate' => 5000, 'max_rate' => 20000],
    );
    AvailabilityRule::factory()->create([
        'tutor_profile_id' => $tutor->id, 'weekday' => $weekday, 'start_time' => $from, 'end_time' => $to, 'timezone' => $timezone,
    ]);

    return ['tutor' => $tutor->fresh(), 'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id];
}

/**
 * A closed lesson on the setup's tutor and subject, with its report; a trial report carries the trial fields.
 *
 * @param  array{tutor: TutorProfile, curriculum_id: int, subject_id: int}  $setup
 * @param  array<string, mixed>  $report
 */
function tfLesson(array $setup, Learner $learner, string $startsAt, bool $trial = true, array $report = []): Lesson
{
    $start = CarbonImmutable::parse($startsAt, 'UTC');
    $factory = Lesson::factory()->withStatus(LessonStatus::CompletedReported);
    $lesson = ($trial ? $factory->trial() : $factory)->create([
        'tutor_profile_id' => $setup['tutor']->id,
        'learner_id' => $learner->id,
        'curriculum_id' => $setup['curriculum_id'],
        'subject_id' => $setup['subject_id'],
        'starts_at' => $start,
        'ends_at' => $start->addHour(),
    ]);

    ProgressReport::factory()->create([
        'lesson_id' => $lesson->id,
        'submitted_at' => $start->addHours(2),
        ...($trial ? ['trial_suitability' => 'good_fit', 'trial_recommended_frequency' => 2, 'trial_focus_areas' => 'Algebra fluency.'] : []),
        ...$report,
    ]);

    return $lesson;
}

/**
 * @return array{parent: User, learner: Learner}
 */
function tfParent(string $timezone = 'UTC'): array
{
    $parent = User::factory()->create(['timezone' => $timezone]);
    $learner = Learner::factory()->create(['account_user_id' => $parent->id, 'curriculum_id' => gcseCurriculumId()]);

    return ['parent' => $parent, 'learner' => $learner];
}

/**
 * @param  array{tutor: TutorProfile, curriculum_id: int, subject_id: int}  $setup
 */
function tfCreatePage(User $parent, array $setup, Learner $learner): TestResponse
{
    return actingAs($parent)->get(route('weekly-slots.create', ['tutor' => $setup['tutor']->id, 'learner' => $learner->id]));
}

it("starts the weekly-slot form on the trial's subject, weekday and hour, with the recommended frequency", function () {
    $setup = tfTutor();
    ['parent' => $parent, 'learner' => $learner] = tfParent();
    tfLesson($setup, $learner, '2026-09-08 10:00:00'); // a Tuesday

    tfCreatePage($parent, $setup, $learner)->assertInertia(fn (AssertableInertia $page) => $page
        ->component('weekly-slots/Create')
        ->where('prefill.subject', $setup['curriculum_id'].'|'.$setup['subject_id'])
        ->where('prefill.slot', '2|10:00')
        ->where('prefill.frequency', 2));
});

it("reads the weekday and hour in the tutor's timezone, not UTC and not the parent's", function () {
    // Wednesday 01:00-04:00 in Dubai; the trial was Tuesday 22:00 UTC, which is Wednesday 02:00 there.
    $setup = tfTutor('Asia/Dubai', 3, '01:00:00', '04:00:00');
    ['parent' => $parent, 'learner' => $learner] = tfParent('Pacific/Auckland');
    tfLesson($setup, $learner, '2026-09-08 22:00:00');

    tfCreatePage($parent, $setup, $learner)->assertInertia(fn (AssertableInertia $page) => $page->where('prefill.slot', '3|02:00'));
});

it('suggests no time the tutor no longer offers, and no subject the tutor no longer teaches', function () {
    $setup = tfTutor(weekday: 4); // Thursdays only now
    ['parent' => $parent, 'learner' => $learner] = tfParent();
    tfLesson($setup, $learner, '2026-09-08 10:00:00');
    TutorSubject::query()->where('tutor_profile_id', $setup['tutor']->id)->delete();

    tfCreatePage($parent, $setup, $learner)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('prefill.slot', null)
        ->where('prefill.subject', null)
        ->where('prefill.frequency', 2));
});

it('pre-fills nothing without a completed trial with this tutor', function () {
    $setup = tfTutor();
    $other = tfTutor();
    ['parent' => $parent, 'learner' => $learner] = tfParent();
    tfLesson($other, $learner, '2026-09-08 10:00:00'); // a trial, but with someone else

    tfCreatePage($parent, $setup, $learner)->assertInertia(fn (AssertableInertia $page) => $page->where('prefill', null));
});

it("never shows another parent's trial through a link naming their learner", function () {
    $setup = tfTutor();
    ['learner' => $theirs] = tfParent();
    ['parent' => $mine, 'learner' => $mineLearner] = tfParent();
    tfLesson($setup, $theirs, '2026-09-08 10:00:00');

    $response = tfCreatePage($mine, $setup, $theirs);

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('selected_learner', $mineLearner->id)
        ->where('prefill', null)
        ->has('learners', 1));
    expect($response->getContent())->not->toContain('Algebra fluency');
});

it('puts a link in the trial email that opens the pre-filled form for the recipient', function () {
    $setup = tfTutor();
    ['parent' => $parent, 'learner' => $learner] = tfParent();
    $trial = tfLesson($setup, $learner, '2026-09-08 10:00:00');

    $link = route('weekly-slots.create', ['tutor' => $trial->tutor_profile_id, 'learner' => $trial->learner_id]);
    $html = (new ProgressReportMail($trial->progressReport, $parent))->render();

    expect($html)->toContain(e($link));
    actingAs($parent)->get($link)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('prefill.slot', '2|10:00'));
});

it("lists a learner's reports newest first on their page, in the parent's timezone", function () {
    $setup = tfTutor();
    ['parent' => $parent, 'learner' => $learner] = tfParent('Asia/Dubai');
    tfLesson($setup, $learner, '2026-09-01 10:00:00', report: ['topics_covered' => 'Older topics']);
    tfLesson($setup, $learner, '2026-09-08 23:30:00', trial: false, report: ['topics_covered' => 'Newer topics']); // 03:30 on the 9th in Dubai

    actingAs($parent)->get(route('learners.show', $learner))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('learners/Show')
        ->has('reports', 2)
        ->where('reports.0.topics_covered', 'Newer topics')
        ->where('reports.0.date', 'Wed, 9 Sep 2026')
        ->where('reports.0.is_trial', false)
        ->where('reports.1.topics_covered', 'Older topics')
        ->where('reports.1.is_trial', true)
        ->where('reports.1.trial_recommended_frequency', 2));
});

it('takes "recent focus areas" from the last five reports and shows at most twenty on the timeline', function () {
    $setup = tfTutor();
    ['parent' => $parent, 'learner' => $learner] = tfParent();

    foreach (range(1, 22) as $n) {
        tfLesson($setup, $learner, CarbonImmutable::parse('2026-08-01 10:00:00', 'UTC')->addDays($n)->toDateTimeString(), trial: $n === 1, report: ['work_on_next' => "Focus {$n}"]);
    }

    actingAs($parent)->get(route('learners.show', $learner))->assertInertia(fn (AssertableInertia $page) => $page
        ->has('reports', 20)
        ->where('reports_capped', true)
        ->has('recent_focus', 5)
        ->where('recent_focus.0.work_on_next', 'Focus 22')
        ->where('recent_focus.4.work_on_next', 'Focus 18'));
});

it("shows only the reports on this learner, never a sibling's or another family's", function () {
    $setup = tfTutor();
    ['parent' => $parent, 'learner' => $learner] = tfParent();
    $sibling = Learner::factory()->create(['account_user_id' => $parent->id, 'curriculum_id' => gcseCurriculumId()]);
    ['learner' => $stranger] = tfParent();
    tfLesson($setup, $learner, '2026-09-01 10:00:00', report: ['work_on_next' => 'Mine']);
    tfLesson($setup, $sibling, '2026-09-02 10:00:00', report: ['work_on_next' => 'Sibling']);
    tfLesson($setup, $stranger, '2026-09-03 10:00:00', report: ['work_on_next' => 'Stranger']);

    $response = actingAs($parent)->get(route('learners.show', $learner));

    $response->assertInertia(fn (AssertableInertia $page) => $page->has('reports', 1)->has('recent_focus', 1)->where('reports.0.work_on_next', 'Mine'));
    expect($response->getContent())->not->toContain('Sibling')->not->toContain('Stranger');
});

it("refuses another parent the learner page and its reports, and keeps the tutor's contact details out of them", function () {
    $setup = tfTutor();
    ['parent' => $parent, 'learner' => $learner] = tfParent();
    ['parent' => $stranger] = tfParent();
    tfLesson($setup, $learner, '2026-09-01 10:00:00');

    actingAs($stranger)->get(route('learners.show', $learner))->assertForbidden();

    $body = actingAs($parent)->get(route('learners.show', $learner))->getContent();
    expect($body)->not->toContain($setup['tutor']->user->email);
});

it('orders the timeline by lesson date, so a late report on an older lesson does not jump above a newer one', function () {
    $setup = tfTutor();
    ['parent' => $parent, 'learner' => $learner] = tfParent();
    tfLesson($setup, $learner, '2026-09-08 10:00:00', trial: false, report: ['work_on_next' => 'Newer lesson', 'submitted_at' => '2026-09-08 12:00:00']);
    tfLesson($setup, $learner, '2026-09-01 10:00:00', trial: false, report: ['work_on_next' => 'Older lesson, filed late', 'submitted_at' => '2026-09-13 12:00:00']);

    actingAs($parent)->get(route('learners.show', $learner))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('reports.0.work_on_next', 'Newer lesson')
        ->where('reports.1.work_on_next', 'Older lesson, filed late')
        ->where('recent_focus.0.work_on_next', 'Newer lesson'));
});

it("reads a British Summer Time trial hour in the tutor's own clock", function () {
    // Tuesday 10:00 in London in September is 09:00 UTC.
    $setup = tfTutor('Europe/London');
    ['parent' => $parent, 'learner' => $learner] = tfParent();
    tfLesson($setup, $learner, '2026-09-08 09:00:00');

    tfCreatePage($parent, $setup, $learner)->assertInertia(fn (AssertableInertia $page) => $page->where('prefill.slot', '2|10:00'));
});
