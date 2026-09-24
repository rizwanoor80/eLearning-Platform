<?php

use App\Actions\Lessons\CancelLesson;
use App\Actions\Lessons\SkipLesson;
use App\Enums\LessonStatus;
use App\Mail\Lessons\LessonCancelledMail;
use App\Mail\Lessons\LessonConfirmedMail;
use App\Mail\Lessons\LessonSkippedMail;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Lessons\LessonStateMachine;
use Illuminate\Support\Facades\Mail;

/**
 * PLAN.md step 5: the CP3 lifecycle emails (confirmed, cancelled, skipped),
 * both parties (PRD "Booking confirmed / cancelled / rescheduled / skipped |
 * Both"), queued through the settings sender. Mail stays on the `log` driver
 * (R43), so these assert queuing only, never delivery.
 */
it('emails both parent and tutor when a reserved lesson is confirmed', function () {
    Mail::fake();

    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id]);
    $lesson = Lesson::factory()->withStatus(LessonStatus::Reserved)->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
    ]);

    LessonStateMachine::transition($lesson, LessonStatus::Confirmed);

    Mail::assertQueued(LessonConfirmedMail::class, fn ($m) => $m->hasTo($parent->email) && $m->lesson->is($lesson));
    Mail::assertQueued(LessonConfirmedMail::class, fn ($m) => $m->hasTo($tutor->user->email) && $m->lesson->is($lesson));
    Mail::assertQueued(LessonConfirmedMail::class, 2);
});

it('emails both parent and tutor when a pending-payment lesson is confirmed (trial flow)', function () {
    Mail::fake();

    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id]);
    $lesson = Lesson::factory()->withStatus(LessonStatus::PendingPayment)->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
    ]);

    LessonStateMachine::transition($lesson, LessonStatus::Confirmed);

    Mail::assertQueued(LessonConfirmedMail::class, 2);
});

it('emails both parties when a confirmed (paid) lesson is cancelled, not skipped', function () {
    Mail::fake();

    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id]);
    $lesson = Lesson::factory()->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'starts_at' => now()->addHours(25),
        'ends_at' => now()->addHours(26),
        'cancel_window_hours' => 24,
    ]);
    Payment::factory()->create(['lesson_id' => $lesson->id, 'payer_user_id' => $parent->id, 'amount' => $lesson->price]);
    app(LedgerService::class)->hold($lesson);

    app(CancelLesson::class)($parent, $lesson->fresh(), 'change of plans');

    Mail::assertQueued(LessonCancelledMail::class, fn ($m) => $m->hasTo($parent->email));
    Mail::assertQueued(LessonCancelledMail::class, fn ($m) => $m->hasTo($tutor->user->email));
    Mail::assertQueued(LessonCancelledMail::class, 2);
    Mail::assertNotQueued(LessonSkippedMail::class);
});

it('emails both parties when a reserved (unpaid) lesson is skipped, not cancelled', function () {
    Mail::fake();

    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id]);
    $lesson = Lesson::factory()->withStatus(LessonStatus::Reserved)->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'starts_at' => now()->addHours(25),
        'ends_at' => now()->addHours(26),
    ]);

    app(SkipLesson::class)($parent, $lesson->fresh());

    Mail::assertQueued(LessonSkippedMail::class, fn ($m) => $m->hasTo($parent->email));
    Mail::assertQueued(LessonSkippedMail::class, fn ($m) => $m->hasTo($tutor->user->email));
    Mail::assertQueued(LessonSkippedMail::class, 2);
    Mail::assertNotQueued(LessonCancelledMail::class);
});

it('sends no lifecycle mail for a transition none of the three listeners cover', function () {
    Mail::fake();

    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id]);
    $lesson = Lesson::factory()->withStatus(LessonStatus::Confirmed)->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'starts_at' => now()->subMinute(),
        'ends_at' => now()->addHour(),
    ]);

    LessonStateMachine::transition($lesson, LessonStatus::InProgress);

    Mail::assertNothingQueued();
});
