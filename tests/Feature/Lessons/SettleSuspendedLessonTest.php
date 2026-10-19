<?php

use App\Actions\Lessons\RecordAttendance;
use App\Actions\Lessons\SettleEndedLesson;
use App\Actions\Lessons\SettleSuspendedLesson;
use App\Actions\RecordAuditLog;
use App\Enums\CurriculumCode;
use App\Enums\LedgerAccount;
use App\Enums\LessonCancelReason;
use App\Enums\LessonStatus;
use App\Enums\PaymentStatus;
use App\Enums\VideoParticipant;
use App\Events\Lessons\LessonStatusChanged;
use App\Exceptions\LessonTransitionException;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\TutorProfile;
use App\Models\TutorStrike;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

afterEach(fn () => Carbon::setTestNow());

/**
 * A paid, held, confirmed lesson that started `$startedMinutesAgo` minutes ago (one hour long), then the given sides join.
 *
 * @param  list<VideoParticipant>  $joined
 * @return array{lesson: Lesson, tutor: TutorProfile, parent: User}
 */
function ssSetup(int $startedMinutesAgo, array $joined = []): array
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

/** The row `CloseSuspendedAccountRooms` leaves when it closes a suspended account's room. */
function ssSuspend(Lesson $lesson, User $user): void
{
    app(RecordAuditLog::class)(null, 'lesson.room_closed_suspension', $lesson, null, ['account_user_id' => $user->id]);
}

function ssSettle(Lesson $lesson): ?LessonStatus
{
    return app(SettleEndedLesson::class)($lesson->fresh());
}

/** The tutor is paid in full: escrow empty, the tutor's and the platform's shares booked, nothing refunded. */
function ssExpectPaid(Lesson $lesson): void
{
    $ledger = app(LedgerService::class);

    expect($ledger->sum($lesson))->toBe(0)
        ->and($ledger->balance($lesson, LedgerAccount::Escrow))->toBe(0)
        ->and($ledger->balance($lesson, LedgerAccount::Tutor))->toBe($lesson->tutor_amount->toFils())
        ->and($ledger->balance($lesson, LedgerAccount::Platform))->toBe($lesson->commission_amount->toFils())
        ->and($ledger->balance($lesson, LedgerAccount::Refund))->toBe(0)
        ->and($lesson->fresh()->escrow_released_at)->not->toBeNull();

    expect(Artisan::call('ledger:verify'))->toBe(0);
}

/** The parent is refunded in full: nothing to the tutor or the platform, escrow empty. */
function ssExpectRefunded(Lesson $lesson): void
{
    $ledger = app(LedgerService::class);

    expect($ledger->sum($lesson))->toBe(0)
        ->and($ledger->balance($lesson, LedgerAccount::Escrow))->toBe(0)
        ->and($ledger->balance($lesson, LedgerAccount::Tutor))->toBe(0)
        ->and($ledger->balance($lesson, LedgerAccount::Platform))->toBe(0)
        ->and($ledger->balance($lesson, LedgerAccount::Refund))->toBe($lesson->price->toFils())
        ->and(Payment::query()->where('lesson_id', $lesson->id)->sole()->status)->toBe(PaymentStatus::Refunded)
        ->and(TutorStrike::query()->count())->toBe(0);

    expect(Artisan::call('ledger:verify'))->toBe(0);
}

function ssSettledAudit(Lesson $lesson): AuditLog
{
    return AuditLog::query()->where('action', 'lesson.settled_suspension')->where('subject_id', $lesson->id)->sole();
}

// ---- parent suspended, confirmed: a late parent cancellation --------------------------------------------

it('pays the tutor in full when the suspended parent\'s confirmed lesson ends unattended', function () {
    ['lesson' => $lesson, 'parent' => $parent] = ssSetup(71);
    ssSuspend($lesson, $parent);

    $this->artisan('lessons:settle-ended')->assertSuccessful();

    $lesson->refresh();
    expect($lesson->status)->toBe(LessonStatus::CompletedReported)
        ->and($lesson->cancelled_at)->not->toBeNull()
        ->and($lesson->cancelled_by_user_id)->toBeNull()
        ->and($lesson->cancel_reason)->toBe(LessonCancelReason::AccountSuspended->value)
        ->and(Payment::query()->where('lesson_id', $lesson->id)->sole()->status)->not->toBe(PaymentStatus::Refunded)
        ->and(TutorStrike::query()->count())->toBe(0)
        ->and(ssSettledAudit($lesson)->after)->toBe(['suspended_user_ids' => [$parent->id], 'row' => 'parent_confirmed', 'status' => 'completed_reported']);
    ssExpectPaid($lesson);
});

it('waits for the end plus the grace before settling a suspension, like the unattended refund', function () {
    ['lesson' => $lesson, 'parent' => $parent] = ssSetup(69);
    ssSuspend($lesson, $parent);

    expect(ssSettle($lesson))->toBeNull()
        ->and($lesson->fresh()->status)->toBe(LessonStatus::Confirmed);

    Carbon::setTestNow(now()->addMinutes(2));
    expect(ssSettle($lesson))->toBe(LessonStatus::CompletedReported);
});

it('settles a suspended parent\'s lesson once: a second run adds no ledger entry', function () {
    ['lesson' => $lesson, 'parent' => $parent] = ssSetup(90);
    ssSuspend($lesson, $parent);

    $this->artisan('lessons:settle-ended');
    $entries = $lesson->ledgerEntries()->count();
    $this->artisan('lessons:settle-ended')->assertSuccessful();

    expect($lesson->ledgerEntries()->count())->toBe($entries)
        ->and(AuditLog::query()->where('action', 'lesson.settled_suspension')->count())->toBe(1);
});

// ---- parent suspended, in progress: a student no-show ---------------------------------------------------

it('pays the tutor when the suspended parent\'s lesson is in progress with only the tutor joined', function () {
    ['lesson' => $lesson, 'parent' => $parent] = ssSetup(75, [VideoParticipant::Tutor]);
    ssSuspend($lesson, $parent);

    $this->artisan('lessons:settle-ended')->assertSuccessful();

    expect($lesson->fresh()->status)->toBe(LessonStatus::CompletedReported)
        ->and($lesson->fresh()->cancelled_at)->toBeNull()
        ->and(ssSettledAudit($lesson)->after['row'])->toBe('parent_in_progress');
    ssExpectPaid($lesson);
});

it('passes through the student no-show state on the way, as MarkNoShow does', function () {
    ['lesson' => $lesson, 'parent' => $parent] = ssSetup(75, [VideoParticipant::Tutor]);
    ssSuspend($lesson, $parent);
    $seen = [];
    Event::listen(LessonStatusChanged::class, function (LessonStatusChanged $event) use (&$seen) {
        $seen[] = $event->to;
    });

    ssSettle($lesson);

    expect($seen)->toBe([LessonStatus::NoShowStudent, LessonStatus::CompletedReported]);
});

// ---- tutor suspended, in progress: refund, no strike ----------------------------------------------------

it('refunds the parent, with no strike, when the suspended tutor\'s lesson is in progress with only the tutor joined', function () {
    ['lesson' => $lesson, 'tutor' => $tutor] = ssSetup(75, [VideoParticipant::Tutor]);
    ssSuspend($lesson, $tutor->user);

    $this->artisan('lessons:settle-ended')->assertSuccessful();

    expect($lesson->fresh()->status)->toBe(LessonStatus::Refunded)
        ->and(ssSettledAudit($lesson)->after)->toBe(['suspended_user_ids' => [$tutor->user_id], 'row' => 'tutor_in_progress', 'status' => 'refunded']);
    ssExpectRefunded($lesson);
});

it('refunds the parent when the suspended tutor never joined and only the learner did', function () {
    ['lesson' => $lesson, 'tutor' => $tutor] = ssSetup(75, [VideoParticipant::Learner]);
    ssSuspend($lesson, $tutor->user);

    expect(ssSettle($lesson))->toBe(LessonStatus::Refunded);
    ssExpectRefunded($lesson);
});

it('lets the tutor win when both accounts are suspended, and records both', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'parent' => $parent] = ssSetup(75, [VideoParticipant::Tutor]);
    ssSuspend($lesson, $parent);
    ssSuspend($lesson, $tutor->user);

    expect(ssSettle($lesson))->toBe(LessonStatus::Refunded)
        ->and(ssSettledAudit($lesson)->after['suspended_user_ids'])->toBe(collect([$parent->id, $tutor->user_id])->sort()->values()->all())
        ->and(ssSettledAudit($lesson)->after['row'])->toBe('tutor_in_progress');
    ssExpectRefunded($lesson);
});

// ---- rows that stay as they were ------------------------------------------------------------------------

it('leaves a confirmed lesson of a suspended tutor to the unattended refund', function () {
    ['lesson' => $lesson, 'tutor' => $tutor] = ssSetup(90);
    ssSuspend($lesson, $tutor->user);

    expect(app(SettleSuspendedLesson::class)($lesson))->toBeNull();
    $this->artisan('lessons:settle-ended')->assertSuccessful();

    expect($lesson->fresh()->status)->toBe(LessonStatus::Refunded)
        ->and(AuditLog::query()->where('action', 'lesson.settled_suspension')->count())->toBe(0);
    ssExpectRefunded($lesson);
});

it('completes a lesson both sides joined even though a room was closed by a suspension', function () {
    ['lesson' => $lesson, 'tutor' => $tutor] = ssSetup(75, [VideoParticipant::Tutor, VideoParticipant::Learner]);
    ssSuspend($lesson, $tutor->user);

    expect(ssSettle($lesson))->toBe(LessonStatus::Completed)
        ->and(AuditLog::query()->where('action', 'lesson.settled_suspension')->count())->toBe(0)
        ->and(app(LedgerService::class)->balance($lesson, LedgerAccount::Escrow))->toBe($lesson->price->toFils());
});

it('settles a lesson with no audit row exactly as before', function () {
    ['lesson' => $unattended] = ssSetup(90);
    ['lesson' => $oneSided] = ssSetup(300, [VideoParticipant::Tutor]);

    $this->artisan('lessons:settle-ended')->assertSuccessful();

    expect($unattended->fresh()->status)->toBe(LessonStatus::Refunded)
        ->and($oneSided->fresh()->status)->toBe(LessonStatus::InProgress)
        ->and(AuditLog::query()->where('action', 'lesson.settled_suspension')->count())->toBe(0);
    ssExpectRefunded($unattended);
});

it('ignores a closed-room audit row for a user who is neither the tutor nor the lesson\'s account owner', function () {
    // In progress with only the tutor joined: were the stranger's row taken as the parent's, the tutor would be paid.
    ['lesson' => $lesson] = ssSetup(75, [VideoParticipant::Tutor]);
    ssSuspend($lesson, User::factory()->create());

    expect(app(SettleSuspendedLesson::class)($lesson))->toBeNull()
        ->and(ssSettle($lesson))->toBeNull()
        ->and($lesson->fresh()->status)->toBe(LessonStatus::InProgress)
        ->and(AuditLog::query()->where('action', 'lesson.settled_suspension')->count())->toBe(0)
        ->and(app(LedgerService::class)->balance($lesson, LedgerAccount::Escrow))->toBe($lesson->price->toFils());

    ['lesson' => $unattended] = ssSetup(90);
    ssSuspend($unattended, User::factory()->create());

    expect(ssSettle($unattended))->toBe(LessonStatus::Refunded);
    ssExpectRefunded($unattended);
});

it('pays the tutor when the suspended parent\'s lesson is in progress with only the learner joined', function () {
    ['lesson' => $lesson, 'parent' => $parent] = ssSetup(75, [VideoParticipant::Learner]);
    ssSuspend($lesson, $parent);

    expect(ssSettle($lesson))->toBe(LessonStatus::CompletedReported)
        ->and(ssSettledAudit($lesson)->after['row'])->toBe('parent_in_progress');
    ssExpectPaid($lesson);
});

it('leaves a confirmed lesson to the unattended refund when both accounts are suspended', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'parent' => $parent] = ssSetup(90);
    ssSuspend($lesson, $parent);
    ssSuspend($lesson, $tutor->user);

    expect(ssSettle($lesson))->toBe(LessonStatus::Refunded)
        ->and(AuditLog::query()->where('action', 'lesson.settled_suspension')->count())->toBe(0);
    ssExpectRefunded($lesson);
});

it('does not settle a suspension for a lesson that is not confirmed or in progress', function () {
    ['lesson' => $lesson, 'parent' => $parent] = ssSetup(90);
    ssSuspend($lesson, $parent);
    Lesson::query()->whereKey($lesson->id)->update(['status' => LessonStatus::Settled]);

    expect(app(SettleSuspendedLesson::class)($lesson->fresh()))->toBeNull();
});

it('does not take a suspension row for an in-progress lesson before the end plus the grace', function () {
    ['lesson' => $lesson, 'parent' => $parent] = ssSetup(65, [VideoParticipant::Tutor]);
    ssSuspend($lesson, $parent);

    expect(ssSettle($lesson))->toBeNull()
        ->and($lesson->fresh()->status)->toBe(LessonStatus::InProgress);
});

// ---- the late-join race ---------------------------------------------------------------------------------

it('completes instead of paying a no-show when the learner joined after the sweep read the lesson', function () {
    ['lesson' => $lesson, 'parent' => $parent] = ssSetup(75, [VideoParticipant::Tutor]);
    ssSuspend($lesson, $parent);

    $stale = $lesson->fresh(); // the sweep's copy: only the tutor joined
    Lesson::query()->whereKey($lesson->id)->update(['learner_joined_at' => now()->subMinutes(74)]);

    expect(fn () => app(SettleEndedLesson::class)($stale))->toThrow(LessonTransitionException::class)
        ->and($lesson->fresh()->status)->toBe(LessonStatus::InProgress)
        ->and($lesson->ledgerEntries()->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'lesson.settled_suspension')->count())->toBe(0);

    $this->artisan('lessons:settle-ended')->assertSuccessful();

    expect($lesson->fresh()->status)->toBe(LessonStatus::Completed);
});

it('does not refund a suspended tutor\'s lesson when the learner joined after the sweep read it', function () {
    ['lesson' => $lesson, 'tutor' => $tutor] = ssSetup(75, [VideoParticipant::Tutor]);
    ssSuspend($lesson, $tutor->user);

    $stale = $lesson->fresh();
    Lesson::query()->whereKey($lesson->id)->update(['learner_joined_at' => now()->subMinutes(74)]);

    expect(fn () => app(SettleEndedLesson::class)($stale))->toThrow(LessonTransitionException::class)
        ->and($lesson->fresh()->status)->toBe(LessonStatus::InProgress)
        ->and($lesson->ledgerEntries()->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'lesson.settled_suspension')->count())->toBe(0);

    $this->artisan('lessons:settle-ended')->assertSuccessful();

    expect($lesson->fresh()->status)->toBe(LessonStatus::Completed);
});

it('does not cancel a suspended parent\'s confirmed lesson when a join moved it to in progress after the sweep read it', function () {
    ['lesson' => $lesson, 'parent' => $parent] = ssSetup(90);
    ssSuspend($lesson, $parent);

    $stale = $lesson->fresh(); // confirmed
    app(RecordAttendance::class)($lesson->fresh(), VideoParticipant::Tutor, now()->subMinutes(80));
    expect($lesson->fresh()->status)->toBe(LessonStatus::InProgress);

    expect(fn () => app(SettleEndedLesson::class)($stale))->toThrow(LessonTransitionException::class)
        ->and($lesson->fresh()->status)->toBe(LessonStatus::InProgress)
        ->and($lesson->fresh()->cancelled_at)->toBeNull()
        ->and(AuditLog::query()->where('action', 'lesson.settled_suspension')->count())->toBe(0);
});
