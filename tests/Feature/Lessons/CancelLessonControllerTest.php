<?php

use App\Enums\LessonStatus;
use App\Models\Learner;
use App\Models\LedgerEntry;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

/**
 * HTTP entry point for CancelLesson/SkipLesson (PLAN.md step 5). Action-level
 * refund/strike/ledger behaviour is covered by CancelLessonTest.php; this
 * file covers the controller's own concerns: authorisation (LessonPolicy),
 * status-based dispatch, and turning an Action exception into a flash + redirect
 * instead of a 500.
 */

/**
 * @return array{lesson: Lesson, parent: User, tutor: TutorProfile}
 */
function httpCancelSetup(float $hoursBeforeStart = 25, array $lessonOverrides = []): array
{
    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id, 'curriculum_id' => gcseCurriculumId()]);

    $lesson = Lesson::factory()->create(array_merge([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'starts_at' => now()->addHours($hoursBeforeStart),
        'ends_at' => now()->addHours($hoursBeforeStart)->addHour(),
        'cancel_window_hours' => 24,
    ], $lessonOverrides));

    Payment::factory()->create(['lesson_id' => $lesson->id, 'payer_user_id' => $parent->id, 'amount' => $lesson->price]);
    app(LedgerService::class)->hold($lesson);

    return ['lesson' => $lesson->fresh(), 'parent' => $parent, 'tutor' => $tutor];
}

it('redirects a guest to the login page', function () {
    $lesson = Lesson::factory()->create();

    test()->post(route('lessons.cancel', $lesson))->assertRedirect(route('login'));
});

it('blocks a tutor from this parent-only route with a 403', function () {
    ['lesson' => $lesson, 'tutor' => $tutor] = httpCancelSetup();

    test()->actingAs($tutor->user)->post(route('lessons.cancel', $lesson))->assertForbidden();

    expect($lesson->fresh()->status)->toBe(LessonStatus::Confirmed);
});

it('lets the owning parent skip a reserved lesson', function () {
    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id, 'curriculum_id' => gcseCurriculumId()]);
    $lesson = Lesson::factory()->withStatus(LessonStatus::Reserved)->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
    ]);

    test()->actingAs($parent)->post(route('lessons.cancel', $lesson))
        ->assertRedirect(route('dashboard'))
        ->assertInertiaFlash('toast.type', 'success');

    expect($lesson->fresh()->status)->toBe(LessonStatus::CancelledByParent);
});

it('lets the owning parent cancel a confirmed lesson, refunded on/after the window', function () {
    ['lesson' => $lesson, 'parent' => $parent] = httpCancelSetup(25);

    test()->actingAs($parent)->post(route('lessons.cancel', $lesson))
        ->assertRedirect(route('dashboard'))
        ->assertInertiaFlash('toast.type', 'success');

    expect($lesson->fresh()->status)->toBe(LessonStatus::Refunded)
        ->and(app(LedgerService::class)->sum($lesson))->toBe(0);
});

it('refuses a stranger parent who does not own the lesson\'s learner — 403 via LessonPolicy', function () {
    ['lesson' => $lesson] = httpCancelSetup();
    $stranger = User::factory()->create();

    test()->actingAs($stranger)->post(route('lessons.cancel', $lesson))->assertForbidden();

    expect($lesson->fresh()->status)->toBe(LessonStatus::Confirmed);
});

it('turns an already-started lesson\'s CancellationException into an error toast, not a 500', function () {
    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id, 'curriculum_id' => gcseCurriculumId()]);
    $lesson = Lesson::factory()->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'starts_at' => now()->subMinute(),
        'ends_at' => now()->addMinutes(59),
    ]);

    test()->actingAs($parent)->post(route('lessons.cancel', $lesson))
        ->assertRedirect(route('dashboard'))
        ->assertInertiaFlash('toast.type', 'error');

    expect($lesson->fresh()->status)->toBe(LessonStatus::Confirmed);
});

it('is safe against a double-submitted cancel: the second request is a no-op error toast, not a duplicate refund', function () {
    ['lesson' => $lesson, 'parent' => $parent] = httpCancelSetup(25);

    test()->actingAs($parent)->post(route('lessons.cancel', $lesson))
        ->assertInertiaFlash('toast.type', 'success');

    $entriesAfterFirstCancel = LedgerEntry::query()->where('lesson_id', $lesson->id)->count();

    test()->actingAs($parent)->post(route('lessons.cancel', $lesson))
        ->assertRedirect(route('dashboard'))
        ->assertInertiaFlash('toast.type', 'error');

    expect($lesson->fresh()->status)->toBe(LessonStatus::Refunded)
        ->and(LedgerEntry::query()->where('lesson_id', $lesson->id)->count())->toBe($entriesAfterFirstCancel);
});

it('flashes "can no longer be cancelled" for a lesson already in a terminal status, without calling any Action', function () {
    ['lesson' => $lesson, 'parent' => $parent] = httpCancelSetup(25, ['status' => LessonStatus::CompletedReported]);

    test()->actingAs($parent)->post(route('lessons.cancel', $lesson))
        ->assertRedirect(route('dashboard'))
        ->assertInertiaFlash('toast.type', 'error');

    expect($lesson->fresh()->status)->toBe(LessonStatus::CompletedReported);
});
