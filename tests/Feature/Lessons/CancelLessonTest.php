<?php

use App\Actions\Lessons\CancelLesson;
use App\Actions\Tutor\SuspendTutorForStrikes;
use App\Enums\LedgerAccount;
use App\Enums\LessonStatus;
use App\Enums\PaymentStatus;
use App\Enums\StrikeType;
use App\Enums\TutorProfileStatus;
use App\Exceptions\CancellationException;
use App\Exceptions\LessonTransitionException;
use App\Mail\Admin\AdminTutorSuspendedMail;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\LedgerEntry;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\TutorProfile;
use App\Models\TutorStrike;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Support\Facades\Settings;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

afterEach(fn () => Carbon::setTestNow());

/**
 * PRD §4 cancellation rules, CP3 acceptance boxes 4 (25h/23h refund vs
 * tutor-paid, tutor strike), 5 (a settings change never moves an
 * already-booked lesson's frozen window) and 6 (3 strikes in 90 days ->
 * suspended, admin emailed).
 */

/**
 * A `confirmed` lesson, HOLD-ed and paid, ready to cancel. `$hoursBeforeStart`
 * places `starts_at` that many hours from `now()`, so the 24h frozen window
 * can be tested from either side.
 *
 * @return array{lesson: Lesson, tutor: TutorProfile, parent: User}
 */
function clSetup(float $hoursBeforeStart = 25, array $lessonOverrides = []): array
{
    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id]);

    $lesson = Lesson::factory()->create(array_merge([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'starts_at' => now()->addHours($hoursBeforeStart),
        'ends_at' => now()->addHours($hoursBeforeStart)->addHour(),
        'cancel_window_hours' => 24,
    ], $lessonOverrides));

    Payment::factory()->create(['lesson_id' => $lesson->id, 'payer_user_id' => $parent->id, 'amount' => $lesson->price]);
    app(LedgerService::class)->hold($lesson);

    return ['lesson' => $lesson->fresh(), 'tutor' => $tutor, 'parent' => $parent];
}

// ---- parent path ------------------------------------------------------------------------------

it('refunds a parent cancelling at 25h before start (on/after the window)', function () {
    ['lesson' => $lesson, 'parent' => $parent] = clSetup(25);

    $result = app(CancelLesson::class)($parent, $lesson, 'change of plans');

    expect($result->status)->toBe(LessonStatus::Refunded)
        ->and(app(LedgerService::class)->sum($lesson))->toBe(0)
        ->and($lesson->fresh()->cancelled_by_user_id)->toBe($parent->id)
        ->and($lesson->fresh()->cancel_reason)->toBe('change of plans');

    $payment = Payment::query()->where('lesson_id', $lesson->id)->sole();
    expect($payment->status)->toBe(PaymentStatus::Refunded)
        ->and($payment->refunded_amount->toFils())->toBe($lesson->price->toFils());
});

it('pays the tutor in full when the parent cancels at 23h before start (inside the window)', function () {
    ['lesson' => $lesson, 'parent' => $parent] = clSetup(23);

    $result = app(CancelLesson::class)($parent, $lesson);

    expect($result->status)->toBe(LessonStatus::CompletedReported)
        ->and(app(LedgerService::class)->sum($lesson))->toBe(0)
        ->and(app(LedgerService::class)->balance($lesson, LedgerAccount::Tutor))->toBe($lesson->tutor_amount->toFils())
        ->and($lesson->fresh()->escrow_released_at)->not->toBeNull();

    $payment = Payment::query()->where('lesson_id', $lesson->id)->sole();
    expect($payment->status)->toBe(PaymentStatus::Captured);
});

it('refunds when cancelling exactly on the window deadline — the boundary itself is on-time', function () {
    ['lesson' => $lesson, 'parent' => $parent] = clSetup(25);

    // Freeze "now" to the exact instant of the lesson's 24h deadline before calling.
    Carbon::setTestNow($lesson->starts_at->copy()->subHours($lesson->cancel_window_hours));

    $result = app(CancelLesson::class)($parent, $lesson);

    expect($result->status)->toBe(LessonStatus::Refunded)
        ->and(app(LedgerService::class)->sum($lesson))->toBe(0);
});

it('pays the tutor when cancelling one second past the window deadline', function () {
    ['lesson' => $lesson, 'parent' => $parent] = clSetup(25);

    Carbon::setTestNow($lesson->starts_at->copy()->subHours($lesson->cancel_window_hours)->addSecond());

    $result = app(CancelLesson::class)($parent, $lesson);

    expect($result->status)->toBe(LessonStatus::CompletedReported);
});

it('is unaffected by a settings change after booking — box 5', function () {
    ['lesson' => $lesson, 'parent' => $parent] = clSetup(23);

    // The lesson's own frozen `cancel_window_hours` is 24; a later settings
    // change to a much smaller window must not turn this into an on-time,
    // refunded cancellation (invariant #11).
    Settings::set('cancel_window_hours', 1);

    $result = app(CancelLesson::class)($parent, $lesson);

    expect($result->status)->toBe(LessonStatus::CompletedReported);
});

// ---- tutor path ---------------------------------------------------------------------------------

it('always refunds the parent when the tutor cancels, with no strike outside the window', function () {
    ['lesson' => $lesson, 'tutor' => $tutor] = clSetup(25);

    $result = app(CancelLesson::class)($tutor->user, $lesson);

    expect($result->status)->toBe(LessonStatus::CancelledByTutor)
        ->and(app(LedgerService::class)->sum($lesson))->toBe(0)
        ->and(TutorStrike::query()->where('tutor_profile_id', $tutor->id)->count())->toBe(0);
});

it('strikes the tutor for cancelling at 23h before start (inside the window)', function () {
    ['lesson' => $lesson, 'tutor' => $tutor] = clSetup(23);

    app(CancelLesson::class)($tutor->user, $lesson);

    $strike = TutorStrike::query()->where('tutor_profile_id', $tutor->id)->sole();
    expect($strike->type)->toBe(StrikeType::LateCancel)
        ->and($strike->lesson_id)->toBe($lesson->id);
});

it('is unaffected by a settings change after booking on the tutor-strike boundary too', function () {
    ['lesson' => $lesson, 'tutor' => $tutor] = clSetup(23);

    Settings::set('cancel_window_hours', 1);

    app(CancelLesson::class)($tutor->user, $lesson);

    expect(TutorStrike::query()->where('tutor_profile_id', $tutor->id)->count())->toBe(1);
});

// ---- guards ---------------------------------------------------------------------------------

it('refuses a caller who is neither the lesson\'s tutor nor its parent', function () {
    ['lesson' => $lesson] = clSetup(25);
    $stranger = User::factory()->create();

    expect(fn () => app(CancelLesson::class)($stranger, $lesson))->toThrow(CancellationException::class);

    expect($lesson->fresh()->status)->toBe(LessonStatus::Confirmed);
});

it('refuses to cancel a lesson that has already started', function () {
    ['lesson' => $lesson, 'parent' => $parent] = clSetup(-1);

    expect(fn () => app(CancelLesson::class)($parent, $lesson))->toThrow(CancellationException::class);

    expect($lesson->fresh()->status)->toBe(LessonStatus::Confirmed);
});

it('refuses to cancel an already-cancelled lesson, writing nothing new', function () {
    ['lesson' => $lesson, 'parent' => $parent] = clSetup(25);

    app(CancelLesson::class)($parent, $lesson);
    $entriesAfterFirstCancel = LedgerEntry::query()->where('lesson_id', $lesson->id)->count();

    expect(fn () => app(CancelLesson::class)($parent, $lesson->fresh()))->toThrow(LessonTransitionException::class);

    expect(LedgerEntry::query()->where('lesson_id', $lesson->id)->count())->toBe($entriesAfterFirstCancel);
});

it('refuses to cancel a reserved (unpaid) lesson', function () {
    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id]);
    $lesson = Lesson::factory()->withStatus(LessonStatus::Reserved)->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'starts_at' => now()->addHours(25),
        'ends_at' => now()->addHours(26),
        'cancel_window_hours' => 24,
    ]);

    expect(fn () => app(CancelLesson::class)($parent, $lesson))->toThrow(CancellationException::class);

    expect($lesson->fresh()->status)->toBe(LessonStatus::Reserved)
        ->and(LedgerEntry::query()->where('lesson_id', $lesson->id)->count())->toBe(0);
});

it('does not let a SuspendTutorForStrikes failure surface as if the cancellation itself failed', function () {
    ['lesson' => $lesson, 'tutor' => $tutor] = clSetup(23);

    $suspend = Mockery::mock(SuspendTutorForStrikes::class);
    $suspend->shouldReceive('__invoke')->once()->andThrow(new RuntimeException('boom'));

    $reported = [];
    app(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$reported) {
        $reported[] = $e->getMessage();

        return false;
    });

    $result = (new CancelLesson($suspend))($tutor->user, $lesson);

    expect($result->status)->toBe(LessonStatus::CancelledByTutor)
        ->and($reported)->toContain('boom')
        ->and(app(LedgerService::class)->sum($lesson))->toBe(0);

    $strike = TutorStrike::query()->where('tutor_profile_id', $tutor->id)->sole();
    expect($strike->type)->toBe(StrikeType::LateCancel);
});

// ---- strike-driven suspension (box 6) -------------------------------------------------------

it('suspends a tutor and emails an admin after 3 strikes within 90 days', function () {
    Mail::fake();
    Settings::set('support_address', 'support@example.test');

    $tutor = TutorProfile::factory()->approved()->create();

    // Two strikes already on the books, well inside 90 days.
    TutorStrike::factory()->count(2)->create([
        'tutor_profile_id' => $tutor->id,
        'created_at' => now()->subDays(10),
    ]);

    ['lesson' => $lesson, 'tutor' => $sameTutor] = clSetup(23, ['tutor_profile_id' => $tutor->id]);

    app(CancelLesson::class)($tutor->user, $lesson);

    expect($tutor->fresh()->status)->toBe(TutorProfileStatus::Suspended);

    $audit = AuditLog::query()->where('subject_type', TutorProfile::class)->where('subject_id', $tutor->id)
        ->where('action', 'tutor.suspended')->sole();
    expect($audit->actor_user_id)->toBeNull();

    Mail::assertQueued(AdminTutorSuspendedMail::class, fn ($m) => $m->hasTo('support@example.test') && $m->profile->is($tutor) && $m->strikeCount === 3);
});

it('does not suspend when only 2 of 3 strikes fall inside the 90-day window', function () {
    Mail::fake();

    $tutor = TutorProfile::factory()->approved()->create();

    TutorStrike::factory()->create(['tutor_profile_id' => $tutor->id, 'created_at' => now()->subDays(10)]);
    TutorStrike::factory()->create(['tutor_profile_id' => $tutor->id, 'created_at' => now()->subDays(91)]);

    ['lesson' => $lesson] = clSetup(23, ['tutor_profile_id' => $tutor->id]);

    app(CancelLesson::class)($tutor->user, $lesson);

    expect($tutor->fresh()->status)->toBe(TutorProfileStatus::Approved);
    Mail::assertNotQueued(AdminTutorSuspendedMail::class);
});

it('no-ops a strike on an already-suspended tutor: no throw, no second mail', function () {
    Mail::fake();

    $tutor = TutorProfile::factory()->approved()->create();
    TutorStrike::factory()->count(3)->create(['tutor_profile_id' => $tutor->id, 'created_at' => now()->subDays(5)]);
    $tutor->forceFill(['status' => TutorProfileStatus::Suspended])->save();

    ['lesson' => $lesson] = clSetup(23, ['tutor_profile_id' => $tutor->id]);

    app(CancelLesson::class)($tutor->user, $lesson);

    expect($tutor->fresh()->status)->toBe(TutorProfileStatus::Suspended);
    Mail::assertNotQueued(AdminTutorSuspendedMail::class);
});
