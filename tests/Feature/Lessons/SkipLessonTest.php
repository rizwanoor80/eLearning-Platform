<?php

use App\Actions\Lessons\SkipLesson;
use App\Actions\Tutor\SuspendTutorForStrikes;
use App\Enums\LedgerAccount;
use App\Enums\LessonStatus;
use App\Enums\StrikeType;
use App\Exceptions\CancellationException;
use App\Exceptions\LessonTransitionException;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\TutorProfile;
use App\Models\TutorStrike;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use Illuminate\Contracts\Debug\ExceptionHandler;

/**
 * PRD §4: skipping a `reserved` (unpaid, weekly-slot) lesson is free either
 * way — no ledger movement, since a `reserved` lesson has never been HOLD-ed —
 * and strikes the tutor only when the tutor skips inside the lesson's own
 * frozen `cancel_window_hours`.
 *
 * @return array{lesson: Lesson, tutor: TutorProfile, parent: User}
 */
function skSetup(float $hoursBeforeStart = 25): array
{
    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id]);

    $lesson = Lesson::factory()->withStatus(LessonStatus::Reserved)->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'starts_at' => now()->addHours($hoursBeforeStart),
        'ends_at' => now()->addHours($hoursBeforeStart)->addHour(),
        'cancel_window_hours' => 24,
    ]);

    return ['lesson' => $lesson->fresh(), 'tutor' => $tutor, 'parent' => $parent];
}

/**
 * A `confirmed` lesson, HOLD-ed and paid — the counter-case `SkipLesson` must
 * reject, since `sum() !== 0` cannot catch it: a HOLD leaves the ledger
 * balanced (gateway/escrow legs sum to zero), so without an explicit status
 * guard, skipping a paid lesson would cancel it while stranding the price in
 * escrow forever.
 *
 * @return array{lesson: Lesson, tutor: TutorProfile, parent: User}
 */
function skConfirmedSetup(float $hoursBeforeStart = 25): array
{
    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id]);

    $lesson = Lesson::factory()->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'starts_at' => now()->addHours($hoursBeforeStart),
        'ends_at' => now()->addHours($hoursBeforeStart)->addHour(),
        'cancel_window_hours' => 24,
    ]);

    Payment::factory()->create(['lesson_id' => $lesson->id, 'payer_user_id' => $parent->id, 'amount' => $lesson->price]);
    app(LedgerService::class)->hold($lesson);

    return ['lesson' => $lesson->fresh(), 'tutor' => $tutor, 'parent' => $parent];
}

it('refuses to skip a confirmed (paid) lesson, leaving its escrow untouched', function () {
    ['lesson' => $lesson, 'parent' => $parent] = skConfirmedSetup(25);
    $escrowBefore = app(LedgerService::class)->balance($lesson, LedgerAccount::Escrow);

    expect(fn () => app(SkipLesson::class)($parent, $lesson))->toThrow(CancellationException::class);

    expect($lesson->fresh()->status)->toBe(LessonStatus::Confirmed)
        ->and(app(LedgerService::class)->balance($lesson, LedgerAccount::Escrow))->toBe($escrowBefore);
});

it('is free with no strike when the parent skips a reserved lesson', function () {
    ['lesson' => $lesson, 'parent' => $parent] = skSetup(25);

    $result = app(SkipLesson::class)($parent, $lesson, 'schedule conflict');

    expect($result->status)->toBe(LessonStatus::CancelledByParent)
        ->and(app(LedgerService::class)->sum($lesson))->toBe(0)
        ->and(TutorStrike::query()->count())->toBe(0)
        ->and($lesson->fresh()->cancel_reason)->toBe('schedule conflict');
});

it('is free with no strike when the tutor skips outside the window', function () {
    ['lesson' => $lesson, 'tutor' => $tutor] = skSetup(25);

    $result = app(SkipLesson::class)($tutor->user, $lesson);

    expect($result->status)->toBe(LessonStatus::CancelledByTutor)
        ->and(app(LedgerService::class)->sum($lesson))->toBe(0)
        ->and(TutorStrike::query()->count())->toBe(0);
});

it('strikes the tutor for skipping inside the window, with no ledger activity', function () {
    ['lesson' => $lesson, 'tutor' => $tutor] = skSetup(23);

    $result = app(SkipLesson::class)($tutor->user, $lesson);

    expect($result->status)->toBe(LessonStatus::CancelledByTutor)
        ->and(app(LedgerService::class)->sum($lesson))->toBe(0);

    $strike = TutorStrike::query()->where('tutor_profile_id', $tutor->id)->sole();
    expect($strike->type)->toBe(StrikeType::LateCancel)
        ->and($strike->lesson_id)->toBe($lesson->id);
});

it('refuses a caller who is neither the lesson\'s tutor nor its parent', function () {
    ['lesson' => $lesson] = skSetup(25);
    $stranger = User::factory()->create();

    expect(fn () => app(SkipLesson::class)($stranger, $lesson))->toThrow(CancellationException::class);

    expect($lesson->fresh()->status)->toBe(LessonStatus::Reserved);
});

it('refuses to skip a lesson that has already started', function () {
    ['lesson' => $lesson, 'parent' => $parent] = skSetup(-1);

    expect(fn () => app(SkipLesson::class)($parent, $lesson))->toThrow(CancellationException::class);

    expect($lesson->fresh()->status)->toBe(LessonStatus::Reserved);
});

it('refuses to skip an already-moved-on lesson', function () {
    ['lesson' => $lesson, 'parent' => $parent] = skSetup(25);

    app(SkipLesson::class)($parent, $lesson);

    expect(fn () => app(SkipLesson::class)($parent, $lesson->fresh()))->toThrow(LessonTransitionException::class);
});

it('does not let a SuspendTutorForStrikes failure surface as if the skip itself failed', function () {
    ['lesson' => $lesson, 'tutor' => $tutor] = skSetup(23);

    $suspend = Mockery::mock(SuspendTutorForStrikes::class);
    $suspend->shouldReceive('__invoke')->once()->andThrow(new RuntimeException('boom'));

    $reported = [];
    app(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$reported) {
        $reported[] = $e->getMessage();

        return false;
    });

    $result = (new SkipLesson($suspend))($tutor->user, $lesson);

    expect($result->status)->toBe(LessonStatus::CancelledByTutor)
        ->and($reported)->toContain('boom');

    $strike = TutorStrike::query()->where('tutor_profile_id', $tutor->id)->sole();
    expect($strike->type)->toBe(StrikeType::LateCancel);
});

it('refuses a typed reason that equals a machine cancel-reason value', function () {
    ['lesson' => $lesson, 'parent' => $parent] = skSetup(25);

    expect(fn () => app(SkipLesson::class)($parent, $lesson, 'slot_paused'))->toThrow(CancellationException::class, 'reserved by the system');

    expect($lesson->fresh()->status)->toBe(LessonStatus::Reserved)
        ->and($lesson->fresh()->cancel_reason)->toBeNull();
});
