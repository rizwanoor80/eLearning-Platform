<?php

use App\Actions\Lessons\ForceCancelLesson;
use App\Enums\CurriculumCode;
use App\Enums\LedgerAccount;
use App\Enums\LessonCancelReason;
use App\Enums\LessonStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserStatus;
use App\Exceptions\AttendanceException;
use App\Filament\Resources\Lessons\Pages\ListLessons;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\LedgerEntry;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\RecurringSlot;
use App\Models\TutorProfile;
use App\Models\TutorStrike;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

afterEach(fn () => Carbon::setTestNow());

/**
 * @return array{lesson: Lesson, tutor: TutorProfile, parent: User}
 */
function fcSetup(LessonStatus $status, array $lessonAttributes = []): array
{
    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create([
        'account_user_id' => $parent->id,
        'curriculum_id' => Curriculum::query()->firstOrCreate(['code' => CurriculumCode::Gcse], ['name' => CurriculumCode::Gcse->value, 'sort' => 0])->id,
    ]);

    $lesson = Lesson::factory()->withStatus($status)->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
        ...$lessonAttributes,
    ]);

    if ($status === LessonStatus::Confirmed) {
        Payment::factory()->create(['lesson_id' => $lesson->id, 'payer_user_id' => $parent->id, 'amount' => $lesson->price]);
        app(LedgerService::class)->hold($lesson);
    }

    return ['lesson' => $lesson->fresh(), 'tutor' => $tutor, 'parent' => $parent];
}

// ---- reserved: free ------------------------------------------------------------------------------------

it('force-cancels a reserved lesson free, with no ledger entries and no strike', function () {
    ['lesson' => $lesson] = fcSetup(LessonStatus::Reserved);
    $admin = User::factory()->admin()->create();

    $result = app(ForceCancelLesson::class)($admin, $lesson, 'Duplicate booking, cancelling the second one');

    expect($result->status)->toBe(LessonStatus::CancelledByTutor)
        ->and($result->cancel_reason)->toBe(LessonCancelReason::Admin->value)
        ->and($result->cancelled_by_user_id)->toBe($admin->id)
        ->and(LedgerEntry::query()->where('lesson_id', $lesson->id)->count())->toBe(0)
        ->and(TutorStrike::query()->count())->toBe(0);

    $audit = AuditLog::query()->where('action', 'lesson.force_cancel')->sole();
    expect($audit->actor_user_id)->toBe($admin->id)
        ->and($audit->after['note'])->toBe('Duplicate booking, cancelling the second one');
});

it('refuses to force-cancel a reserved lesson with a payment attempt in flight or already captured', function () {
    ['lesson' => $pending] = fcSetup(LessonStatus::Reserved);
    Payment::factory()->create(['lesson_id' => $pending->id, 'status' => PaymentStatus::Pending]);

    ['lesson' => $captured] = fcSetup(LessonStatus::Reserved);
    Payment::factory()->create(['lesson_id' => $captured->id, 'status' => PaymentStatus::Captured]);

    $admin = User::factory()->admin()->create();

    expect(fn () => app(ForceCancelLesson::class)($admin, $pending, 'x'))->toThrow(AttendanceException::class)
        ->and(fn () => app(ForceCancelLesson::class)($admin, $captured, 'x'))->toThrow(AttendanceException::class);

    expect($pending->fresh()->status)->toBe(LessonStatus::Reserved)
        ->and($captured->fresh()->status)->toBe(LessonStatus::Reserved);
});

// ---- confirmed: refunded --------------------------------------------------------------------------------

it('force-cancels a confirmed lesson with a full refund, no strike, no tutor pay', function () {
    ['lesson' => $lesson] = fcSetup(LessonStatus::Confirmed);
    $admin = User::factory()->admin()->create();

    $result = app(ForceCancelLesson::class)($admin, $lesson, 'Wrong tutor was booked by mistake');

    $ledger = app(LedgerService::class);
    expect($result->status)->toBe(LessonStatus::CancelledByTutor)
        ->and($result->cancel_reason)->toBe(LessonCancelReason::Admin->value)
        ->and($ledger->sum($lesson))->toBe(0)
        ->and($ledger->balance($lesson, LedgerAccount::Refund))->toBe($lesson->price->toFils())
        ->and($ledger->balance($lesson, LedgerAccount::Tutor))->toBe(0)
        ->and(TutorStrike::query()->count())->toBe(0)
        ->and(Payment::query()->where('lesson_id', $lesson->id)->sole()->status)->toBe(PaymentStatus::Refunded);

    $audit = AuditLog::query()->where('action', 'lesson.force_cancel')->sole();
    expect(json_encode($audit->after))->toContain('Wrong tutor was booked by mistake');
});

// ---- guards ---------------------------------------------------------------------------------------------

it('needs an active admin, a note, a future start, and a reserved-or-confirmed status', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'parent' => $parent] = fcSetup(LessonStatus::Confirmed);
    ['lesson' => $past] = fcSetup(LessonStatus::Confirmed, ['starts_at' => now()->subHour(), 'ends_at' => now()->addMinutes(30)]);
    $admin = User::factory()->admin()->create();
    $suspendedAdmin = User::factory()->admin()->create(['status' => UserStatus::Suspended]);

    expect(fn () => app(ForceCancelLesson::class)($parent, $lesson, 'x'))->toThrow(AttendanceException::class)
        ->and(fn () => app(ForceCancelLesson::class)($tutor->user, $lesson, 'x'))->toThrow(AttendanceException::class)
        ->and(fn () => app(ForceCancelLesson::class)($suspendedAdmin, $lesson, 'x'))->toThrow(AttendanceException::class)
        ->and(fn () => app(ForceCancelLesson::class)($admin, $lesson, '   '))->toThrow(AttendanceException::class, 'note')
        ->and(fn () => app(ForceCancelLesson::class)($admin, $past, 'x'))->toThrow(AttendanceException::class, 'already started');

    expect($lesson->fresh()->status)->toBe(LessonStatus::Confirmed)
        ->and($past->fresh()->status)->toBe(LessonStatus::Confirmed)
        ->and(AuditLog::query()->where('action', 'lesson.force_cancel')->count())->toBe(0);
});

it('refuses a lesson that is not reserved or confirmed and moves no money', function () {
    ['lesson' => $lesson] = fcSetup(LessonStatus::InProgress, ['starts_at' => now()->subMinutes(30), 'ends_at' => now()->addMinutes(30)]);
    $admin = User::factory()->admin()->create();

    expect(fn () => app(ForceCancelLesson::class)($admin, $lesson, 'x'))->toThrow(AttendanceException::class);
    expect($lesson->fresh()->status)->toBe(LessonStatus::InProgress);
});

// ---- recurring key retention -----------------------------------------------------------------------------

it('keeps a weekly lesson\'s recurring key after a force-cancel, so it is never auto-regenerated', function () {
    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create([
        'account_user_id' => $parent->id,
        'curriculum_id' => Curriculum::query()->firstOrCreate(['code' => CurriculumCode::Gcse], ['name' => CurriculumCode::Gcse->value, 'sort' => 0])->id,
    ]);
    $slot = RecurringSlot::factory()->create(['tutor_profile_id' => $tutor->id, 'learner_id' => $learner->id]);
    $lesson = Lesson::factory()->withStatus(LessonStatus::Reserved)->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'recurring_slot_id' => $slot->id,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
    ]);
    $admin = User::factory()->admin()->create();

    app(ForceCancelLesson::class)($admin, $lesson, 'wrong booking');

    expect($lesson->fresh()->recurring_slot_id)->toBe($slot->id)
        ->and($lesson->fresh()->starts_at->toIso8601String())->toBe($lesson->starts_at->toIso8601String());
});

// ---- from the admin lesson list --------------------------------------------------------------------------

it('force-cancels a lesson from the admin lesson list', function () {
    ['lesson' => $lesson] = fcSetup(LessonStatus::Confirmed);
    actingAs(User::factory()->admin()->create());

    Livewire::test(ListLessons::class)
        ->assertTableActionVisible('forceCancel', $lesson)
        ->callTableAction('forceCancel', $lesson, ['note' => 'Wrong booking'])
        ->assertHasNoTableActionErrors();

    expect($lesson->fresh()->status)->toBe(LessonStatus::CancelledByTutor);
});

it('hides force-cancel once a lesson has started or is not reserved/confirmed', function () {
    ['lesson' => $past] = fcSetup(LessonStatus::Confirmed, ['starts_at' => now()->subHour(), 'ends_at' => now()->addMinutes(30)]);
    ['lesson' => $running] = fcSetup(LessonStatus::InProgress, ['starts_at' => now()->subMinutes(30), 'ends_at' => now()->addMinutes(30)]);
    actingAs(User::factory()->admin()->create());

    Livewire::test(ListLessons::class)
        ->assertTableActionHidden('forceCancel', $past)
        ->assertTableActionHidden('forceCancel', $running);
});

it('does not let a parent, a tutor or a suspended admin force-cancel from the list', function () {
    ['lesson' => $lesson, 'parent' => $parent, 'tutor' => $tutor] = fcSetup(LessonStatus::Confirmed);

    foreach ([$parent, $tutor->user, User::factory()->admin()->create(['status' => UserStatus::Suspended])] as $outsider) {
        try {
            Livewire::actingAs($outsider)->test(ListLessons::class)->callTableAction('forceCancel', $lesson, ['note' => 'x']);
        } catch (Throwable) {
            // refused by any route is fine; the state below is the proof
        }

        expect($lesson->fresh()->status)->toBe(LessonStatus::Confirmed);
    }

    expect(app(LedgerService::class)->balance($lesson, LedgerAccount::Refund))->toBe(0);
});
