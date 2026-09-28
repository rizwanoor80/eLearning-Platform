<?php

use App\Actions\Lessons\OpenDispute;
use App\Enums\CurriculumCode;
use App\Enums\DisputeReason;
use App\Enums\LessonStatus;
use App\Exceptions\DisputeException;
use App\Models\Curriculum;
use App\Models\Dispute;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * A lesson between a fresh parent+learner and an approved tutor, ended `$hoursAgo` hours ago —
 * mirrors `reviewSetup()` (tests/Feature/Reviews/ReviewsTest.php): `withStatus()` only sets the
 * `status` column, so `ends_at` (the 48h window's own clock, R150) is always set explicitly here.
 *
 * @return array{lesson: Lesson, tutor: TutorProfile, parent: User}
 */
function disputeSetup(LessonStatus $status = LessonStatus::Completed, float $hoursAgo = 2.0, array $overrides = []): array
{
    // Minutes, computed once: no slot offset here (unlike some other lesson-factory test helpers
    // in this codebase) — each call gets its own fresh tutor and learner, so nothing needs to keep
    // times apart, and an offset added on top of `now()` would shift `ends_at` away from the exact
    // `$hoursAgo` the window-boundary tests below depend on (this was a real bug here once: see the
    // 02:38/NOTE CYCLE-LOG entries — Carbon's subHours()/addHours() do accept a float correctly).
    $minutesAgo = (int) round($hoursAgo * 60);

    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create([
        'account_user_id' => $parent->id,
        'curriculum_id' => Curriculum::query()->firstOrCreate(['code' => CurriculumCode::Gcse], ['name' => CurriculumCode::Gcse->value, 'sort' => 0])->id,
    ]);

    $lesson = Lesson::factory()->withStatus($status)->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'starts_at' => now()->copy()->subMinutes($minutesAgo + 60),
        'ends_at' => now()->copy()->subMinutes($minutesAgo),
        'completed_at' => $status === LessonStatus::Completed ? now()->copy()->subMinutes($minutesAgo) : null,
        ...$overrides,
    ]);

    return ['lesson' => $lesson->fresh(), 'tutor' => $tutor, 'parent' => $parent];
}

// ---- the eligibility test (R150) ---------------------------------------------------------------

it('lets only a completed or completed_reported lesson, within 48h of ends_at, be disputed', function (LessonStatus $status, float $hoursAgo) {
    ['lesson' => $lesson, 'parent' => $parent] = disputeSetup($status, $hoursAgo);

    expect(OpenDispute::problemFor($parent, $lesson))->not->toBeNull();

    expect(fn () => app(OpenDispute::class)($parent, $lesson, DisputeReason::Quality, 'Something was wrong.'))
        ->toThrow(RuntimeException::class);

    expect(Dispute::query()->count())->toBe(0);
})->with([
    'still in progress' => [LessonStatus::InProgress, 2.0],
    'a late parent cancellation' => [LessonStatus::CancelledByParent, 2.0],
    'a provider failure' => [LessonStatus::ProviderFailure, 2.0],
    'already settled' => [LessonStatus::Settled, 2.0],
    'already disputed' => [LessonStatus::Disputed, 2.0],
    '48 hours and 1 minute after ends_at' => [LessonStatus::Completed, 48 + 1 / 60],
]);

it('lets the eligible lesson be disputed, right up to the 48h edge', function () {
    ['lesson' => $lesson, 'parent' => $parent] = disputeSetup(hoursAgo: 47.9);
    expect(OpenDispute::problemFor($parent, $lesson))->toBeNull();

    $dispute = app(OpenDispute::class)($parent, $lesson, DisputeReason::Quality, 'The tutor left early.');

    expect($dispute->lesson_id)->toBe($lesson->id)
        ->and($dispute->opened_by_user_id)->toBe($parent->id)
        ->and($dispute->reason)->toBe(DisputeReason::Quality)
        ->and($dispute->description)->toBe('The tutor left early.')
        ->and($dispute->status->value)->toBe('open')
        ->and($lesson->fresh()->status)->toBe(LessonStatus::Disputed);
});

it('lets a completed_reported (already released) lesson be disputed too', function () {
    ['lesson' => $lesson, 'parent' => $parent] = disputeSetup(LessonStatus::CompletedReported, 2.0);
    expect(OpenDispute::problemFor($parent, $lesson))->toBeNull();

    $dispute = app(OpenDispute::class)($parent, $lesson, DisputeReason::NoShow, 'The tutor never joined.');

    expect($dispute->lesson_id)->toBe($lesson->id)
        ->and($lesson->fresh()->status)->toBe(LessonStatus::Disputed);
});

// ---- description bounds -------------------------------------------------------------------------

it('needs a description between 1 and 2000 characters', function () {
    ['lesson' => $lesson, 'parent' => $parent] = disputeSetup();

    expect(fn () => app(OpenDispute::class)($parent, $lesson, DisputeReason::Other, ''))
        ->toThrow(DisputeException::class);
    expect(fn () => app(OpenDispute::class)($parent, $lesson->fresh(), DisputeReason::Other, str_repeat('a', 2001)))
        ->toThrow(DisputeException::class);

    expect(Dispute::query()->count())->toBe(0);
});

// ---- never the learner (invariant #7), never another family or the tutor ------------------------

it('attributes the dispute to the account holder, never the learner, and only they may open it', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'parent' => $parent] = disputeSetup();
    $stranger = User::factory()->create();
    $otherTutor = TutorProfile::factory()->approved()->create();

    foreach ([$tutor->user, $stranger, $otherTutor->user] as $user) {
        actingAs($user)->get(route('lessons.dispute.create', $lesson))->assertForbidden();
        actingAs($user)->post(route('lessons.dispute.store', $lesson), ['reason' => 'quality', 'description' => 'x'])->assertNotFound();
    }

    auth()->logout();
    $this->get(route('lessons.dispute.create', $lesson))->assertRedirect(route('login'));
    $this->post(route('lessons.dispute.store', $lesson), ['reason' => 'quality', 'description' => 'x'])->assertRedirect(route('login'));

    expect(Dispute::query()->count())->toBe(0);
});

// ---- once, even under a race ---------------------------------------------------------------------

// In a single process the second attempt is refused by `LessonStateMachine`'s own edge assert
// (the lesson is already `disputed`, so `completed`/`completed_reported` -> `disputed` no longer
// applies) before `$work`, and so before `problemFor()` or the unique index, ever runs — the
// index only guards a true concurrent race across two connections, which this test cannot drive.
// Named for what it actually proves, not for the index (see the two HTTP tests below for the
// bug this once was: the assert's `LessonTransitionException` reaching the controller uncaught).
it('refuses a second dispute for the same lesson once it is already disputed', function () {
    ['lesson' => $lesson, 'parent' => $parent] = disputeSetup();
    app(OpenDispute::class)($parent, $lesson, DisputeReason::Quality, 'First complaint.');

    expect(fn () => app(OpenDispute::class)($parent, $lesson->fresh(), DisputeReason::Technical, 'Second complaint.'))
        ->toThrow(RuntimeException::class);

    expect(Dispute::query()->where('lesson_id', $lesson->id)->count())->toBe(1)
        ->and(Dispute::query()->where('lesson_id', $lesson->id)->sole()->description)->toBe('First complaint.');
});

// ---- the HTTP layer --------------------------------------------------------------------------------

it('shows the dispute form with the lesson details and reason options', function () {
    ['lesson' => $lesson, 'parent' => $parent] = disputeSetup();

    actingAs($parent)->get(route('lessons.dispute.create', $lesson))->assertOk()
        ->assertInertia(fn ($page) => $page->component('lessons/Dispute')
            ->where('lesson.id', $lesson->id)
            ->has('reasons', count(DisputeReason::cases())));
});

it('redirects with a flash message and no form when the lesson cannot be disputed yet', function () {
    ['lesson' => $lesson, 'parent' => $parent] = disputeSetup(LessonStatus::InProgress);

    actingAs($parent)->get(route('lessons.dispute.create', $lesson))
        ->assertRedirect(route('lessons.show', $lesson));
});

it('opens the dispute over HTTP and redirects to the lesson page with a success toast', function () {
    ['lesson' => $lesson, 'parent' => $parent] = disputeSetup();

    actingAs($parent)->post(route('lessons.dispute.store', $lesson), [
        'reason' => 'no_show',
        'description' => 'The tutor never showed up.',
    ])->assertRedirect(route('lessons.show', $lesson));

    $dispute = Dispute::query()->where('lesson_id', $lesson->id)->sole();
    expect($dispute->reason)->toBe(DisputeReason::NoShow)
        ->and($lesson->fresh()->status)->toBe(LessonStatus::Disputed);
});

// ---- a stale form POST hits the state machine's own edge assert, not a 500 (advisor-caught) -----

it('flashes a toast and redirects, not a 500, when a stale POST targets an ineligible lesson', function () {
    ['lesson' => $lesson, 'parent' => $parent] = disputeSetup(LessonStatus::InProgress);

    actingAs($parent)->post(route('lessons.dispute.store', $lesson), ['reason' => 'quality', 'description' => 'x'])
        ->assertRedirect(route('lessons.show', $lesson));

    expect(Dispute::query()->count())->toBe(0);
});

it('flashes a toast and redirects, not a 500, when a stale POST re-submits an already-disputed lesson', function () {
    ['lesson' => $lesson, 'parent' => $parent] = disputeSetup();
    app(OpenDispute::class)($parent, $lesson, DisputeReason::Quality, 'First complaint.');

    actingAs($parent)->post(route('lessons.dispute.store', $lesson), ['reason' => 'technical', 'description' => 'Second complaint.'])
        ->assertRedirect(route('lessons.show', $lesson));

    expect(Dispute::query()->where('lesson_id', $lesson->id)->count())->toBe(1)
        ->and(Dispute::query()->where('lesson_id', $lesson->id)->sole()->description)->toBe('First complaint.');
});

it('validates the reason and description over HTTP', function () {
    ['lesson' => $lesson, 'parent' => $parent] = disputeSetup();

    actingAs($parent)->post(route('lessons.dispute.store', $lesson), ['reason' => 'not-a-real-reason', 'description' => 'x'])
        ->assertSessionHasErrors('reason');
    actingAs($parent)->post(route('lessons.dispute.store', $lesson), ['reason' => 'quality'])
        ->assertSessionHasErrors('description');
    actingAs($parent)->post(route('lessons.dispute.store', $lesson), ['reason' => 'quality', 'description' => str_repeat('a', 2001)])
        ->assertSessionHasErrors('description');

    expect(Dispute::query()->count())->toBe(0);
});

// ---- the entry point on the lesson page ------------------------------------------------------------

it('shows the account holder the dispute prompt once eligible, and nobody else', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'parent' => $parent] = disputeSetup();

    actingAs($parent)->get(route('lessons.show', $lesson))->assertOk()
        ->assertInertia(fn ($page) => $page->where('lesson.can_dispute', true));
    actingAs($tutor->user)->get(route('lessons.show', $lesson))->assertOk()
        ->assertInertia(fn ($page) => $page->where('lesson.can_dispute', false));

    app(OpenDispute::class)($parent, $lesson, DisputeReason::Quality, 'x');

    actingAs($parent)->get(route('lessons.show', $lesson))->assertOk()
        ->assertInertia(fn ($page) => $page->where('lesson.can_dispute', false));
});
