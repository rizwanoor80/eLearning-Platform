<?php

use App\Actions\Lessons\BookLesson;
use App\Actions\Lessons\CancelLesson;
use App\Actions\Lessons\SkipLesson;
use App\Enums\CurriculumCode;
use App\Enums\LessonStatus;
use App\Enums\LevelTier;
use App\Mail\Lessons\LessonCancelledMail;
use App\Mail\Lessons\LessonConfirmedMail;
use App\Mail\Lessons\LessonSkippedMail;
use App\Models\AvailabilityRule;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PriceBand;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\TutorSubject;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Lessons\LessonStateMachine;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\PaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/**
 * PLAN.md step 5: the CP3 lifecycle emails (confirmed, cancelled, skipped),
 * both parties (PRD "Booking confirmed / cancelled / rescheduled / skipped |
 * Both"), queued through the settings sender. Mail stays on the `log` driver
 * (R43), so these assert queuing only, never delivery.
 */
it('emails the tutor, and not the parent, when a reserved weekly lesson is confirmed (the parent gets the charged email instead)', function () {
    Mail::fake();

    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id]);
    $lesson = Lesson::factory()->withStatus(LessonStatus::Reserved)->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
    ]);

    LessonStateMachine::transition($lesson, LessonStatus::Confirmed);

    Mail::assertNotQueued(LessonConfirmedMail::class, fn ($m) => $m->hasTo($parent->email));
    Mail::assertQueued(LessonConfirmedMail::class, fn ($m) => $m->hasTo($tutor->user->email) && $m->lesson->is($lesson));
    Mail::assertQueued(LessonConfirmedMail::class, 1);
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

it('renders the confirmed email in the recipient\'s own timezone, not UTC', function () {
    $tutor = TutorProfile::factory()->approved()->create();
    $recipient = User::factory()->create(['timezone' => 'America/New_York']);
    $learner = Learner::factory()->create(['account_user_id' => $recipient->id]);
    $lesson = Lesson::factory()->withStatus(LessonStatus::Confirmed)->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'starts_at' => CarbonImmutable::parse('2026-10-01 14:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-01 15:00:00', 'UTC'),
    ]);

    $html = (new LessonConfirmedMail($lesson, $recipient))->render();

    expect($html)->toContain('10:00')->not->toContain('14:00');
});

it('renders the cancelled email with the reason when given, and without one when absent', function () {
    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id]);

    $withReason = Lesson::factory()->withStatus(LessonStatus::CancelledByParent)->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'cancel_reason' => 'family emergency',
    ]);
    $withoutReason = Lesson::factory()->withStatus(LessonStatus::CancelledByParent)->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'cancel_reason' => null,
    ]);

    expect((new LessonCancelledMail($withReason, $parent))->render())->toContain('family emergency');
    expect((new LessonCancelledMail($withoutReason, $parent))->render())->not->toContain('Reason given');
});

it('renders the skipped email without error', function () {
    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id]);
    $lesson = Lesson::factory()->withStatus(LessonStatus::CancelledByTutor)->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
    ]);

    $html = (new LessonSkippedMail($lesson, $parent))->render();

    expect($html)->toContain('skipped free of charge');
});

it('queues LessonConfirmedMail through the real trial booking path, not just a raw transition', function () {
    Mail::fake();
    test()->travelTo(CarbonImmutable::parse('2026-09-14 06:00:00', 'UTC'));
    app()->instance(PaymentGateway::class, new FakePaymentGateway);

    $curriculum = Curriculum::query()->firstOrCreate(
        ['code' => CurriculumCode::Gcse],
        ['name' => CurriculumCode::Gcse->value, 'sort' => 0],
    );
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
        'tutor_profile_id' => $tutor->id,
        'weekday' => 2,
        'start_time' => '09:00:00',
        'end_time' => '12:00:00',
        'timezone' => 'UTC',
    ]);
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id, 'curriculum_id' => $curriculum->id]);

    $trial = app(BookLesson::class)($parent, $learner, $tutor->fresh(), [
        'curriculum_id' => $curriculum->id,
        'subject_id' => $subject->id,
        'starts_at' => CarbonImmutable::parse('2026-09-15 09:00:00', 'UTC'),
    ]);

    expect($trial->status)->toBe(LessonStatus::Confirmed);
    Mail::assertQueued(LessonConfirmedMail::class, 2);
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
