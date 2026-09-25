<?php

use App\Actions\RecurringSlots\ChargeReservedLesson;
use App\Actions\RecurringSlots\PauseRecurringSlot;
use App\Actions\RecurringSlots\ResumeRecurringSlot;
use App\Enums\ChargeOutcome;
use App\Enums\LessonCancelReason;
use App\Enums\LessonStatus;
use App\Enums\PaymentMethodStatus;
use App\Enums\PaymentStatus;
use App\Enums\RecurringSlotPauseReason;
use App\Enums\RecurringSlotStatus;
use App\Events\Lessons\LessonStatusChanged;
use App\Exceptions\PaymentCaptureException;
use App\Exceptions\RecurringSlotException;
use App\Listeners\Lessons\SendLessonConfirmedMail;
use App\Mail\Lessons\LessonConfirmedMail;
use App\Mail\Payments\WeeklyLessonCancelledForPaymentMail;
use App\Mail\Payments\WeeklyLessonChargedMail;
use App\Mail\Payments\WeeklyLessonChargeFailedMail;
use App\Mail\RecurringSlots\RecurringSlotPausedAdminMail;
use App\Mail\RecurringSlots\RecurringSlotPausedMail;
use App\Models\Learner;
use App\Models\LedgerEntry;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\RecurringSlot;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Lessons\LessonStateMachine;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\PaymentCaptureResult;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\SavedCard;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;

/**
 * CP4 (4e): the weekly-lesson auto-charge (R101) on the fake gateway. "Now" is Monday 2026-09-14
 * 06:00 UTC. A lesson starts Friday 2026-09-18 12:00 UTC, so its first attempt is due Wednesday
 * 2026-09-16 12:00 (T-48h), then T-36h (Thursday 00:00) and T-24h (Thursday 12:00).
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-14 06:00:00', 'UTC'));
    app()->instance(PaymentGateway::class, new FakePaymentGateway);
});

/**
 * A parent with a saved card, their learner, a tutor and an active weekly slot.
 *
 * @return array{parent: User, learner: Learner, tutor: TutorProfile, slot: RecurringSlot, card: PaymentMethod}
 */
function acSetup(bool $declining = false): array
{
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id]);
    $tutor = TutorProfile::factory()->approved()->create();
    $slot = RecurringSlot::factory()->create([
        'learner_id' => $learner->id,
        'tutor_profile_id' => $tutor->id,
        'timezone' => 'UTC',
        'starts_on' => '2026-09-14',
        'generated_until' => '2026-09-13',
    ]);
    $card = $declining
        ? PaymentMethod::factory()->declining()->create(['account_user_id' => $parent->id])
        : PaymentMethod::factory()->create(['account_user_id' => $parent->id]);

    return ['parent' => $parent, 'learner' => $learner, 'tutor' => $tutor, 'slot' => $slot, 'card' => $card];
}

/**
 * A `reserved` weekly lesson whose first charge is due at the standard lead (T-48h).
 */
function acLesson(RecurringSlot $slot, string $startsAt = '2026-09-18 12:00:00', ?string $nextChargeAt = null): Lesson
{
    $start = CarbonImmutable::parse($startsAt, 'UTC');

    return Lesson::factory()->withStatus(LessonStatus::Reserved)->create([
        'tutor_profile_id' => $slot->tutor_profile_id,
        'learner_id' => $slot->learner_id,
        'booked_by_user_id' => $slot->learner->account_user_id,
        'recurring_slot_id' => $slot->id,
        'starts_at' => $start,
        'ends_at' => $start->addHour(),
        'next_charge_at' => $nextChargeAt === null ? $start->subHours(48) : CarbonImmutable::parse($nextChargeAt, 'UTC'),
    ]);
}

function acRun(): string
{
    Artisan::call('recurring:charge');

    return Artisan::output();
}

function acAt(string $when): void
{
    test()->travelTo(CarbonImmutable::parse($when, 'UTC'));
}

it('charges the saved card at T-48h: payment captured, confirmed, HOLD written, counter reset, parent emailed', function () {
    Mail::fake();
    ['parent' => $parent, 'tutor' => $tutor, 'slot' => $slot, 'card' => $card] = acSetup();
    $slot->update(['consecutive_charge_failures' => 1]);
    $lesson = acLesson($slot);

    acAt('2026-09-16 12:00:00');
    acRun();

    $lesson->refresh();
    $payment = $lesson->payment;

    expect($lesson->status)->toBe(LessonStatus::Confirmed)
        ->and($lesson->charge_attempts)->toBe(1)
        ->and($lesson->next_charge_at)->toBeNull()
        ->and($lesson->payment_method_id)->toBe($card->id)
        ->and($payment->status)->toBe(PaymentStatus::Captured)
        ->and($payment->attempt_no)->toBe(1)
        ->and($payment->payer_user_id)->toBe($parent->id)
        ->and($payment->payment_method_id)->toBe($card->id)
        ->and($payment->gateway_ref)->toContain("lesson:{$lesson->id}:attempt:1")
        ->and($payment->amount->toFils())->toBe(10000)
        ->and($slot->fresh()->consecutive_charge_failures)->toBe(0)
        ->and(LedgerEntry::query()->where('lesson_id', $lesson->id)->count())->toBe(2)
        ->and(app(LedgerService::class)->sum($lesson))->toBe(0);

    Mail::assertQueued(WeeklyLessonChargedMail::class, fn ($mail) => $mail->hasTo($parent->email));
    Mail::assertQueued(WeeklyLessonChargedMail::class, 1);
    // The parent gets the charged email, not the generic confirmed one; the tutor still gets the latter.
    Mail::assertNotQueued(LessonConfirmedMail::class, fn ($mail) => $mail->hasTo($parent->email));
    Mail::assertQueued(LessonConfirmedMail::class, fn ($mail) => $mail->hasTo($tutor->user->email));

    expect(Artisan::call('ledger:verify'))->toBe(0);
    expect($tutor)->not->toBeNull();
});

it('does nothing when the lesson is not due yet', function () {
    ['slot' => $slot] = acSetup();
    $lesson = acLesson($slot);

    acAt('2026-09-16 11:59:00');
    acRun();

    expect($lesson->fresh()->status)->toBe(LessonStatus::Reserved)
        ->and(Payment::query()->count())->toBe(0);
});

it('is a no-op the second time it runs: one payment, one hold, no second charge', function () {
    ['slot' => $slot] = acSetup();
    $lesson = acLesson($slot);

    acAt('2026-09-16 12:00:00');
    acRun();
    $second = acRun();

    expect($second)->toContain('Charged 0')
        ->and(Payment::query()->where('lesson_id', $lesson->id)->count())->toBe(1)
        ->and(LedgerEntry::query()->where('lesson_id', $lesson->id)->count())->toBe(2)
        ->and($lesson->fresh()->status)->toBe(LessonStatus::Confirmed);
});

it('retries a declined card at T-36h and T-24h, then cancels the lesson with no money moved', function () {
    Mail::fake();
    ['parent' => $parent, 'tutor' => $tutor, 'slot' => $slot, 'card' => $card] = acSetup(declining: true);
    $lesson = acLesson($slot);

    acAt('2026-09-16 12:00:00');
    acRun();
    $lesson->refresh();

    expect($lesson->status)->toBe(LessonStatus::Reserved)
        ->and($lesson->charge_attempts)->toBe(1)
        ->and($lesson->next_charge_at->toDateTimeString())->toBe('2026-09-17 00:00:00')
        ->and($card->fresh()->last_failed_at)->not->toBeNull();
    Mail::assertQueued(WeeklyLessonChargeFailedMail::class, fn ($mail) => $mail->hasTo($parent->email) && $mail->retryAt->toDateTimeString() === '2026-09-17 00:00:00');

    // Running again before the retry is due changes nothing.
    acRun();
    expect(Payment::query()->where('lesson_id', $lesson->id)->count())->toBe(1);

    acAt('2026-09-17 00:00:00');
    acRun();
    expect($lesson->fresh()->charge_attempts)->toBe(2)
        ->and($lesson->fresh()->next_charge_at->toDateTimeString())->toBe('2026-09-17 12:00:00');

    acAt('2026-09-17 12:00:00');
    acRun();
    $lesson->refresh();

    expect($lesson->status)->toBe(LessonStatus::CancelledPaymentFailed)
        ->and($lesson->charge_attempts)->toBe(3)
        ->and($lesson->next_charge_at)->toBeNull()
        ->and($lesson->cancelled_by_user_id)->toBeNull()
        ->and(Payment::query()->where('lesson_id', $lesson->id)->pluck('status')->all())->toBe([PaymentStatus::Failed, PaymentStatus::Failed, PaymentStatus::Failed])
        ->and(Payment::query()->where('lesson_id', $lesson->id)->orderBy('attempt_no')->pluck('attempt_no')->all())->toBe([1, 2, 3])
        ->and(LedgerEntry::query()->where('lesson_id', $lesson->id)->count())->toBe(0)
        ->and($slot->fresh()->consecutive_charge_failures)->toBe(1)
        ->and($slot->fresh()->status)->toBe(RecurringSlotStatus::Active);

    Mail::assertQueued(WeeklyLessonChargeFailedMail::class, 2);
    Mail::assertQueued(WeeklyLessonCancelledForPaymentMail::class, fn ($mail) => $mail->hasTo($parent->email));
    Mail::assertQueued(WeeklyLessonCancelledForPaymentMail::class, fn ($mail) => $mail->hasTo($tutor->user->email));
    Mail::assertQueued(WeeklyLessonCancelledForPaymentMail::class, 2);
    expect(Artisan::call('ledger:verify'))->toBe(0);
});

it('never charges a learner or emails one: every charge email goes to the account holder', function () {
    Mail::fake();
    ['parent' => $parent, 'learner' => $learner, 'slot' => $slot] = acSetup(declining: true);
    $lesson = acLesson($slot);

    acAt('2026-09-16 12:00:00');
    acRun();

    expect($lesson->fresh()->booked_by_user_id)->toBe($parent->id);
    Mail::assertQueued(WeeklyLessonChargeFailedMail::class, fn ($mail) => $mail->recipient->is($parent) && $mail->hasTo($parent->email));
    expect($learner->account_user_id)->toBe($parent->id);
});

it('pauses the slot on the second consecutive failed lesson and tells parent, tutor and admin', function () {
    Mail::fake();
    $admin = User::factory()->admin()->create();
    ['parent' => $parent, 'tutor' => $tutor, 'slot' => $slot] = acSetup(declining: true);
    $first = acLesson($slot, '2026-09-18 12:00:00');
    $second = acLesson($slot, '2026-09-25 12:00:00');
    $third = acLesson($slot, '2026-10-02 12:00:00');

    foreach (['2026-09-16 12:00:00', '2026-09-17 00:00:00', '2026-09-17 12:00:00'] as $when) {
        acAt($when);
        acRun();
    }

    expect($first->fresh()->status)->toBe(LessonStatus::CancelledPaymentFailed)
        ->and($slot->fresh()->consecutive_charge_failures)->toBe(1)
        ->and($slot->fresh()->status)->toBe(RecurringSlotStatus::Active);

    foreach (['2026-09-23 12:00:00', '2026-09-24 00:00:00', '2026-09-24 12:00:00'] as $when) {
        acAt($when);
        acRun();
    }

    $slot->refresh();

    expect($second->fresh()->status)->toBe(LessonStatus::CancelledPaymentFailed)
        ->and($slot->status)->toBe(RecurringSlotStatus::Paused)
        ->and($slot->paused_reason)->toBe(RecurringSlotPauseReason::PaymentFailed)
        ->and($slot->consecutive_charge_failures)->toBe(2)
        ->and($third->fresh()->status)->toBe(LessonStatus::CancelledByParent)
        ->and($third->fresh()->cancel_reason)->toBe(LessonCancelReason::SlotPaused->value);

    Mail::assertQueued(RecurringSlotPausedMail::class, fn ($mail) => $mail->hasTo($parent->email));
    Mail::assertQueued(RecurringSlotPausedMail::class, fn ($mail) => $mail->hasTo($tutor->user->email));
    Mail::assertQueued(RecurringSlotPausedAdminMail::class, fn ($mail) => $mail->hasTo($admin->email) && $mail->releasedLessons === 1);

    // The paused slot's remaining lesson was never charged: nothing is stranded, everything sums to zero.
    expect(Payment::query()->where('lesson_id', $third->id)->count())->toBe(0)
        ->and(Artisan::call('ledger:verify'))->toBe(0);
});

it('does not email the admin when an admin pauses a slot', function () {
    Mail::fake();
    $admin = User::factory()->admin()->create();
    ['slot' => $slot] = acSetup();

    app(PauseRecurringSlot::class)($admin, $slot, RecurringSlotPauseReason::Admin);

    Mail::assertQueued(RecurringSlotPausedMail::class);
    Mail::assertNotQueued(RecurringSlotPausedAdminMail::class);
});

it('resets the failure counter after a success, so failures must be consecutive to pause', function () {
    ['slot' => $slot, 'parent' => $parent] = acSetup();
    $slot->update(['consecutive_charge_failures' => 1]);
    acLesson($slot);

    acAt('2026-09-16 12:00:00');
    acRun();

    expect($slot->fresh()->consecutive_charge_failures)->toBe(0)
        ->and($slot->fresh()->status)->toBe(RecurringSlotStatus::Active)
        ->and($parent->paymentMethod->last_failed_at)->toBeNull();
});

it('pushes a retry to at least an hour out for a lesson generated late (T-30h)', function () {
    ['slot' => $slot] = acSetup(declining: true);
    // Starts Tuesday 2026-09-15 12:00: 30 hours after "now" (Monday 06:00), already past T-48h.
    $lesson = acLesson($slot, '2026-09-15 12:00:00', '2026-09-14 06:00:00');

    acRun();
    $lesson->refresh();

    // T-36h is in the past, so the retry waits the one-hour minimum, not "immediately".
    expect($lesson->status)->toBe(LessonStatus::Reserved)
        ->and($lesson->charge_attempts)->toBe(1)
        ->and($lesson->next_charge_at->toDateTimeString())->toBe('2026-09-14 07:00:00');

    acAt('2026-09-14 07:00:00');
    acRun();

    // T-24h is 2026-09-14 12:00, still ahead.
    expect($lesson->fresh()->charge_attempts)->toBe(2)
        ->and($lesson->fresh()->next_charge_at->toDateTimeString())->toBe('2026-09-14 12:00:00');
});

it('makes the failure final when no further attempt would land before the lesson starts', function () {
    ['slot' => $slot] = acSetup(declining: true);
    // Starts two hours from now.
    $lesson = acLesson($slot, '2026-09-14 08:00:00', '2026-09-14 06:00:00');

    acRun();
    expect($lesson->fresh()->status)->toBe(LessonStatus::Reserved)
        ->and($lesson->fresh()->next_charge_at->toDateTimeString())->toBe('2026-09-14 07:00:00');

    acAt('2026-09-14 07:00:00');
    acRun();

    expect($lesson->fresh()->status)->toBe(LessonStatus::CancelledPaymentFailed)
        ->and($lesson->fresh()->charge_attempts)->toBe(2);
});

it('cancels a reserved lesson whose start has passed without charging it or counting against the slot', function () {
    Mail::fake();
    ['parent' => $parent, 'tutor' => $tutor, 'slot' => $slot] = acSetup();
    $lesson = acLesson($slot, '2026-09-18 12:00:00');

    acAt('2026-09-18 12:30:00');
    acRun();
    $lesson->refresh();

    expect($lesson->status)->toBe(LessonStatus::CancelledPaymentFailed)
        ->and($lesson->cancel_reason)->toBe(LessonCancelReason::ChargeWindowMissed->value)
        ->and($lesson->next_charge_at)->toBeNull()
        ->and(Payment::query()->count())->toBe(0)
        ->and($slot->fresh()->consecutive_charge_failures)->toBe(0)
        ->and($slot->fresh()->status)->toBe(RecurringSlotStatus::Active)
        ->and(LedgerEntry::query()->count())->toBe(0);

    Mail::assertQueued(WeeklyLessonCancelledForPaymentMail::class, fn ($mail) => $mail->hasTo($parent->email));
    Mail::assertQueued(WeeklyLessonCancelledForPaymentMail::class, fn ($mail) => $mail->hasTo($tutor->user->email));
});

it('renders the missed-window wording only for a charge_window_missed cancellation', function () {
    ['slot' => $slot, 'parent' => $parent] = acSetup();
    $lesson = acLesson($slot);

    $missed = (new WeeklyLessonCancelledForPaymentMail($lesson->forceFill(['cancel_reason' => LessonCancelReason::ChargeWindowMissed->value]), $parent))->render();
    $failed = (new WeeklyLessonCancelledForPaymentMail($lesson->forceFill(['cancel_reason' => null]), $parent))->render();

    expect($missed)->toContain('could not be charged in time')
        ->and($failed)->toContain('payment could not be taken');
});

it('still cancels a missed lesson when no gateway is configured, and charges nothing', function () {
    app()->forgetInstance(PaymentGateway::class);
    app()->offsetUnset(PaymentGateway::class);

    ['slot' => $slot] = acSetup();
    $due = acLesson($slot, '2026-09-25 12:00:00', '2026-09-14 05:00:00');
    $missed = acLesson($slot, '2026-09-14 05:00:00', '2026-09-11 05:00:00');

    $output = acRun();

    expect($output)->toContain('No payment gateway is configured')
        ->and($due->fresh()->status)->toBe(LessonStatus::Reserved)
        ->and($missed->fresh()->status)->toBe(LessonStatus::CancelledPaymentFailed)
        ->and(Payment::query()->count())->toBe(0)
        ->and(Artisan::call('recurring:charge'))->toBe(0);
});

it('records a failed attempt with no card and retries', function () {
    ['slot' => $slot, 'card' => $card] = acSetup();
    $card->delete();
    $lesson = acLesson($slot);

    acAt('2026-09-16 12:00:00');
    acRun();

    $payment = Payment::query()->where('lesson_id', $lesson->id)->sole();

    expect($payment->status)->toBe(PaymentStatus::Failed)
        ->and($payment->failure_reason)->toBe('no_usable_card')
        ->and($payment->payment_method_id)->toBeNull()
        ->and($lesson->fresh()->status)->toBe(LessonStatus::Reserved)
        ->and($lesson->fresh()->charge_attempts)->toBe(1);
});

it('treats an expired or revoked card as unusable', function () {
    ['slot' => $slot, 'card' => $card] = acSetup();
    $card->update(['status' => PaymentMethodStatus::Failed]);
    $lesson = acLesson($slot);

    acAt('2026-09-16 12:00:00');
    acRun();

    expect(Payment::query()->where('lesson_id', $lesson->id)->sole()->failure_reason)->toBe('no_usable_card');
});

it('leaves a lesson alone when its slot is not active', function () {
    ['slot' => $slot] = acSetup();
    $lesson = acLesson($slot);
    $slot->update(['status' => RecurringSlotStatus::Paused, 'paused_reason' => RecurringSlotPauseReason::Admin]);

    acAt('2026-09-16 12:00:00');
    $output = acRun();

    expect($output)->toContain('skipped 1')
        ->and($lesson->fresh()->status)->toBe(LessonStatus::Reserved)
        ->and(Payment::query()->count())->toBe(0);
});

it('leaves a single booking alone: only weekly lessons are charged here', function () {
    $lesson = Lesson::factory()->withStatus(LessonStatus::Reserved)->create(['recurring_slot_id' => null, 'next_charge_at' => now()->subHour()]);

    acRun();

    expect($lesson->fresh()->status)->toBe(LessonStatus::Reserved)->and(Payment::query()->count())->toBe(0);
});

it('picks up a pending attempt a crashed run left behind, on the same attempt number and key', function () {
    ['slot' => $slot, 'parent' => $parent, 'card' => $card] = acSetup();
    $lesson = acLesson($slot);
    Payment::factory()->create([
        'lesson_id' => $lesson->id, 'attempt_no' => 1, 'payer_user_id' => $parent->id, 'payment_method_id' => $card->id,
        'status' => PaymentStatus::Pending, 'gateway_ref' => null,
    ]);

    acAt('2026-09-16 12:00:00');
    acRun();

    $payment = Payment::query()->where('lesson_id', $lesson->id)->sole();

    expect($payment->status)->toBe(PaymentStatus::Captured)
        ->and($payment->attempt_no)->toBe(1)
        ->and($payment->gateway_ref)->toContain("lesson:{$lesson->id}:attempt:1")
        ->and($lesson->fresh()->status)->toBe(LessonStatus::Confirmed);
});

it('flags money taken for a lesson that could not be confirmed, instead of hiding it', function () {
    Exceptions::fake();
    ['slot' => $slot] = acSetup();
    $lesson = acLesson($slot);

    // The parent skips the lesson between the gateway taking the money and the confirmation.
    app()->instance(PaymentGateway::class, new class($lesson) implements PaymentGateway
    {
        public function __construct(private Lesson $lesson) {}

        public function driver(): string
        {
            return 'fake';
        }

        public function capture(Lesson $lesson, Money $amount, string $idempotencyKey): PaymentCaptureResult
        {
            throw new LogicException('not used');
        }

        public function saveCard(User $account, string $selection): SavedCard
        {
            throw new LogicException('not used');
        }

        public function chargeSavedCard(Lesson $lesson, Money $amount, PaymentMethod $method, string $idempotencyKey): PaymentCaptureResult
        {
            LessonStateMachine::transition($this->lesson->fresh(), LessonStatus::CancelledByParent);

            return new PaymentCaptureResult(gatewayRef: 'raced_'.$idempotencyKey, rawResponse: []);
        }
    });

    acAt('2026-09-16 12:00:00');
    $outcome = app(ChargeReservedLesson::class)($lesson->id, app(PaymentGateway::class));

    $payment = Payment::query()->where('lesson_id', $lesson->id)->sole();

    expect($outcome)->toBe(ChargeOutcome::NeedsReview)
        ->and($payment->status)->toBe(PaymentStatus::Captured)
        ->and($payment->gateway_ref)->toBe("raced_lesson:{$lesson->id}:attempt:1")
        ->and($lesson->fresh()->status)->toBe(LessonStatus::CancelledByParent);
    Exceptions::assertReported(RuntimeException::class);

    // `ledger:verify` shows it once the in-flight grace window has passed.
    test()->travel(10)->minutes();
    expect(Artisan::call('ledger:verify'))->toBe(1);
});

it('leaves a pending attempt visible when the lesson start passes before the run finishes it', function () {
    ['slot' => $slot, 'parent' => $parent] = acSetup();
    $lesson = acLesson($slot);
    Payment::factory()->create(['lesson_id' => $lesson->id, 'attempt_no' => 1, 'payer_user_id' => $parent->id, 'status' => PaymentStatus::Pending, 'gateway_ref' => null]);

    acAt('2026-09-18 12:30:00');
    acRun();

    expect($lesson->fresh()->status)->toBe(LessonStatus::CancelledPaymentFailed)
        ->and(Payment::query()->where('lesson_id', $lesson->id)->sole()->status)->toBe(PaymentStatus::Pending)
        ->and(app(LedgerService::class)->strandedPayments())->toHaveCount(1);
});

it('reports a lesson whose gateway call blows up, leaves it pending for the next run, and still charges the rest', function () {
    Exceptions::fake();
    ['slot' => $slot] = acSetup();
    $broken = acLesson($slot, '2026-09-18 12:00:00');
    $good = acLesson($slot, '2026-09-19 12:00:00', '2026-09-16 12:00:00');

    // A gateway outage (not a decline) on the first lesson only.
    app()->instance(PaymentGateway::class, new class($broken->id) implements PaymentGateway
    {
        public function __construct(private int $brokenId) {}

        public function driver(): string
        {
            return 'fake';
        }

        public function capture(Lesson $lesson, Money $amount, string $idempotencyKey): PaymentCaptureResult
        {
            throw new LogicException('not used');
        }

        public function saveCard(User $account, string $selection): SavedCard
        {
            throw new LogicException('not used');
        }

        public function chargeSavedCard(Lesson $lesson, Money $amount, PaymentMethod $method, string $idempotencyKey): PaymentCaptureResult
        {
            if ($lesson->id === $this->brokenId) {
                throw new RuntimeException('gateway timeout');
            }

            return (new FakePaymentGateway)->chargeSavedCard($lesson, $amount, $method, $idempotencyKey);
        }
    });

    acAt('2026-09-16 12:00:00');

    expect(Artisan::call('recurring:charge'))->toBe(1);

    expect($good->fresh()->status)->toBe(LessonStatus::Confirmed)
        ->and($broken->fresh()->status)->toBe(LessonStatus::Reserved)
        ->and($broken->fresh()->charge_attempts)->toBe(0)
        ->and(Payment::query()->where('lesson_id', $broken->id)->sole()->status)->toBe(PaymentStatus::Pending);
    Exceptions::assertReported(RuntimeException::class);

    // The next run resumes the same attempt with the same key.
    app()->instance(PaymentGateway::class, new FakePaymentGateway);
    acRun();

    expect($broken->fresh()->status)->toBe(LessonStatus::Confirmed)
        ->and(Payment::query()->where('lesson_id', $broken->id)->sole()->gateway_ref)->toContain("lesson:{$broken->id}:attempt:1");
});

it('registers the command to run hourly', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toContain('recurring:charge');
});

// ── payments uniqueness (R101) ───────────────────────────────────────────────────────────────

it('lets a lesson have failed attempts and one live payment, and refuses a second live one at the database', function () {
    $lesson = Lesson::factory()->withStatus(LessonStatus::Reserved)->create();
    Payment::factory()->create(['lesson_id' => $lesson->id, 'attempt_no' => 1, 'status' => PaymentStatus::Failed]);
    Payment::factory()->create(['lesson_id' => $lesson->id, 'attempt_no' => 2, 'status' => PaymentStatus::Failed]);
    Payment::factory()->create(['lesson_id' => $lesson->id, 'attempt_no' => 3, 'status' => PaymentStatus::Captured]);

    expect(fn () => Payment::factory()->create(['lesson_id' => $lesson->id, 'attempt_no' => 4, 'status' => PaymentStatus::Captured]))
        ->toThrow(QueryException::class)
        ->and(fn () => Payment::factory()->create(['lesson_id' => $lesson->id, 'attempt_no' => 5, 'status' => PaymentStatus::Pending]))
        ->toThrow(QueryException::class);
});

it('refuses the same attempt number twice for one lesson', function () {
    $lesson = Lesson::factory()->withStatus(LessonStatus::Reserved)->create();
    Payment::factory()->create(['lesson_id' => $lesson->id, 'attempt_no' => 1, 'status' => PaymentStatus::Failed]);

    expect(fn () => Payment::factory()->create(['lesson_id' => $lesson->id, 'attempt_no' => 1, 'status' => PaymentStatus::Failed]))
        ->toThrow(QueryException::class);
});

it('reads the live payment as the lesson payment after a failed attempt and a capture', function () {
    $lesson = Lesson::factory()->create();
    Payment::factory()->create(['lesson_id' => $lesson->id, 'attempt_no' => 1, 'status' => PaymentStatus::Failed]);
    $captured = Payment::factory()->create(['lesson_id' => $lesson->id, 'attempt_no' => 2, 'status' => PaymentStatus::Captured]);

    expect($lesson->fresh()->payment->id)->toBe($captured->id)
        ->and($lesson->fresh()->payment->status)->toBe(PaymentStatus::Captured);
});

// ── the fake driver ──────────────────────────────────────────────────────────────────────────

it('declines a card whose token is the declining test card and charges any other', function () {
    $lesson = Lesson::factory()->create();
    $gateway = new FakePaymentGateway;

    $ok = $gateway->chargeSavedCard($lesson, Money::fils(10000), PaymentMethod::factory()->make(), 'k1');

    expect($ok->gatewayRef)->toContain('k1')
        ->and(fn () => $gateway->chargeSavedCard($lesson, Money::fils(10000), PaymentMethod::factory()->declining()->make(), 'k2'))
        ->toThrow(PaymentCaptureException::class, 'card_declined');
});

it('saves a chosen test card through the fake driver as a token, brand, last four and expiry only', function () {
    $account = User::factory()->create();
    $card = (new FakePaymentGateway)->saveCard($account, 'declines');

    expect($card->last4)->toBe('0002')
        ->and($card->gatewayToken)->toStartWith('fake_pm_declines_')
        ->and($card->expYear)->toBeGreaterThan((int) now()->format('Y'));
});

// ── confirmed email ──────────────────────────────────────────────────────────────────────────

it('sends the parent the generic confirmed email for a paid booking but not for a weekly charge', function () {
    Mail::fake();
    $lesson = Lesson::factory()->create();

    (new SendLessonConfirmedMail)->handle(new LessonStatusChanged($lesson, LessonStatus::Reserved, LessonStatus::Confirmed));
    Mail::assertQueued(LessonConfirmedMail::class, 1);
    Mail::assertNotQueued(LessonConfirmedMail::class, fn ($mail) => $mail->hasTo($lesson->learner->account->email));
    Mail::fake();

    (new SendLessonConfirmedMail)->handle(new LessonStatusChanged($lesson, LessonStatus::PendingPayment, LessonStatus::Confirmed));
    Mail::assertQueued(LessonConfirmedMail::class, 2);
});

// ── resume (R101: replacing the card offers the parent a one-click resume) ───────────────────

/**
 * A slot the system paused for failed charges, with the parent's card flagged as having declined.
 *
 * @return array{parent: User, slot: RecurringSlot, learner: Learner, card: PaymentMethod}
 */
function acPausedByPayment(): array
{
    $setup = acSetup(declining: true);
    $setup['card']->update(['last_failed_at' => now()]);
    $setup['slot']->update(['status' => RecurringSlotStatus::Paused, 'paused_reason' => RecurringSlotPauseReason::PaymentFailed, 'consecutive_charge_failures' => 2]);

    return $setup;
}

it('refuses the parent a resume with the card that already declined', function () {
    ['parent' => $parent, 'slot' => $slot] = acPausedByPayment();

    test()->actingAs($parent)->post(route('weekly-slots.resume', $slot))->assertRedirect();

    expect($slot->fresh()->status)->toBe(RecurringSlotStatus::Paused);
    expect(fn () => app(ResumeRecurringSlot::class)($parent, $slot))->toThrow(RecurringSlotException::class, 'Replace your saved card');
});

it('lets the parent resume a payment-paused slot after replacing the card, and starts the counter again', function () {
    ['parent' => $parent, 'slot' => $slot, 'learner' => $learner] = acPausedByPayment();

    test()->actingAs($parent)->post(route('payment-methods.store'), ['card' => 'succeeds', 'learner' => $learner->id])
        ->assertRedirect(route('learners.show', $learner));

    test()->actingAs($parent)->post(route('weekly-slots.resume', $slot))->assertRedirect(route('learners.show', $learner));

    $slot->refresh();

    expect($slot->status)->toBe(RecurringSlotStatus::Active)
        ->and($slot->paused_reason)->toBeNull()
        ->and($slot->consecutive_charge_failures)->toBe(0)
        ->and($parent->fresh()->paymentMethod->last_failed_at)->toBeNull();
});

it('shows the parent a resume control only on a slot the system paused for failed charges', function () {
    ['parent' => $parent, 'slot' => $slot, 'learner' => $learner] = acPausedByPayment();

    test()->actingAs($parent)->get(route('learners.show', $learner))
        ->assertInertia(fn ($page) => $page->where('slots.0.can_resume', true));

    $slot->update(['paused_reason' => RecurringSlotPauseReason::Admin]);

    test()->actingAs($parent)->get(route('learners.show', $learner))
        ->assertInertia(fn ($page) => $page->where('slots.0.can_resume', false));
});

it('does not let a parent resume a slot an admin paused, or another family\'s slot', function () {
    ['parent' => $parent, 'slot' => $slot] = acPausedByPayment();
    $slot->update(['paused_reason' => RecurringSlotPauseReason::Admin]);
    $stranger = User::factory()->create();

    test()->actingAs($parent)->post(route('weekly-slots.resume', $slot))->assertForbidden();

    $slot->update(['paused_reason' => RecurringSlotPauseReason::PaymentFailed]);
    test()->actingAs($stranger)->post(route('weekly-slots.resume', $slot))->assertForbidden();

    expect($slot->fresh()->status)->toBe(RecurringSlotStatus::Paused);
});

it('still lets an admin resume any paused slot without the card check', function () {
    ['slot' => $slot] = acPausedByPayment();
    $admin = User::factory()->admin()->create();

    app(ResumeRecurringSlot::class)($admin, $slot);

    expect($slot->fresh()->status)->toBe(RecurringSlotStatus::Active);
});
