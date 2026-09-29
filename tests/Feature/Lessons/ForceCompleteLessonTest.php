<?php

use App\Actions\Lessons\ForceCompleteLesson;
use App\Enums\CurriculumCode;
use App\Enums\LedgerAccount;
use App\Enums\LessonStatus;
use App\Enums\UserStatus;
use App\Exceptions\AttendanceException;
use App\Filament\Resources\Lessons\Pages\ListLessons;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Support\Facades\Settings;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

afterEach(fn () => Carbon::setTestNow());

/**
 * A paid, held lesson in `$status`, ending `$endedMinutesAgo` minutes ago.
 *
 * @return array{lesson: Lesson, tutor: TutorProfile, parent: User}
 */
function fxSetup(LessonStatus $status, int $endedMinutesAgo): array
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
        'starts_at' => now()->subMinutes($endedMinutesAgo)->subHour(),
        'ends_at' => now()->subMinutes($endedMinutesAgo),
    ]);
    Payment::factory()->create(['lesson_id' => $lesson->id, 'payer_user_id' => $parent->id, 'amount' => $lesson->price]);
    app(LedgerService::class)->hold($lesson);

    return ['lesson' => $lesson->fresh(), 'tutor' => $tutor, 'parent' => $parent];
}

it('force-completes an in-progress lesson past its end, anchoring the report deadlines on now', function () {
    ['lesson' => $lesson] = fxSetup(LessonStatus::InProgress, 10);
    $admin = User::factory()->admin()->create();

    $before = now();
    $result = app(ForceCompleteLesson::class)($admin, $lesson, 'Only the tutor joined; closing this out by hand');

    expect($result->status)->toBe(LessonStatus::Completed)
        ->and($result->completed_at)->not->toBeNull()
        ->and($result->report_due_at->diffInSeconds($before->copy()->addHours((int) Settings::get('report_due_hours'))))->toBeLessThan(5)
        ->and($result->auto_release_at->diffInSeconds($before->copy()->addHours((int) Settings::get('auto_release_hours'))))->toBeLessThan(5)
        // money is untouched: still fully in escrow, waiting for the normal release path
        ->and(app(LedgerService::class)->balance($result, LedgerAccount::Escrow))->toBe($lesson->price->toFils())
        ->and(app(LedgerService::class)->sum($result))->toBe(0);

    $audit = AuditLog::query()->where('action', 'lesson.force_complete')->sole();
    expect($audit->actor_user_id)->toBe($admin->id)
        ->and($audit->after['note'])->toBe('Only the tutor joined; closing this out by hand');
});

it('force-completes a confirmed lesson (nobody ever joined) by chaining through in_progress', function () {
    ['lesson' => $lesson] = fxSetup(LessonStatus::Confirmed, 90);
    $admin = User::factory()->admin()->create();

    $result = app(ForceCompleteLesson::class)($admin, $lesson, 'Nobody joined; escalated by the parent, closing by hand');

    expect($result->status)->toBe(LessonStatus::Completed)
        ->and($result->tutor_joined_at)->toBeNull()
        ->and($result->learner_joined_at)->toBeNull()
        ->and(app(LedgerService::class)->balance($result, LedgerAccount::Escrow))->toBe($lesson->price->toFils());
});

it('needs an active admin, a note, and ends_at already past', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'parent' => $parent] = fxSetup(LessonStatus::InProgress, 10);
    ['lesson' => $running] = fxSetup(LessonStatus::InProgress, -30); // ends 30 min from now
    $admin = User::factory()->admin()->create();
    $suspendedAdmin = User::factory()->admin()->create(['status' => UserStatus::Suspended]);

    expect(fn () => app(ForceCompleteLesson::class)($parent, $lesson, 'x'))->toThrow(AttendanceException::class)
        ->and(fn () => app(ForceCompleteLesson::class)($tutor->user, $lesson, 'x'))->toThrow(AttendanceException::class)
        ->and(fn () => app(ForceCompleteLesson::class)($suspendedAdmin, $lesson, 'x'))->toThrow(AttendanceException::class)
        ->and(fn () => app(ForceCompleteLesson::class)($admin, $lesson, '   '))->toThrow(AttendanceException::class, 'note')
        ->and(fn () => app(ForceCompleteLesson::class)($admin, $running, 'x'))->toThrow(AttendanceException::class, 'not ended');

    expect($lesson->fresh()->status)->toBe(LessonStatus::InProgress)
        ->and($running->fresh()->status)->toBe(LessonStatus::InProgress)
        ->and(AuditLog::query()->where('action', 'lesson.force_complete')->count())->toBe(0);
});

it('refuses a lesson that is not in_progress or confirmed and moves no money', function () {
    ['lesson' => $lesson] = fxSetup(LessonStatus::Reserved, 10);
    $admin = User::factory()->admin()->create();

    expect(fn () => app(ForceCompleteLesson::class)($admin, $lesson, 'x'))->toThrow(AttendanceException::class);
    expect($lesson->fresh()->status)->toBe(LessonStatus::Reserved);
});

it('force-completes a lesson from the admin lesson list', function () {
    ['lesson' => $lesson] = fxSetup(LessonStatus::InProgress, 10);
    actingAs(User::factory()->admin()->create());

    Livewire::test(ListLessons::class)
        ->assertTableActionVisible('forceComplete', $lesson)
        ->callTableAction('forceComplete', $lesson, ['note' => 'Stuck in progress, closing by hand'])
        ->assertHasNoTableActionErrors();

    expect($lesson->fresh()->status)->toBe(LessonStatus::Completed);
});

it('hides force-complete for a lesson still running or in the wrong status', function () {
    ['lesson' => $running] = fxSetup(LessonStatus::InProgress, -30);
    ['lesson' => $reserved] = fxSetup(LessonStatus::Reserved, 10);
    actingAs(User::factory()->admin()->create());

    Livewire::test(ListLessons::class)
        ->assertTableActionHidden('forceComplete', $running)
        ->assertTableActionHidden('forceComplete', $reserved);
});

it('does not let a parent, a tutor or a suspended admin force-complete from the list', function () {
    ['lesson' => $lesson, 'parent' => $parent, 'tutor' => $tutor] = fxSetup(LessonStatus::InProgress, 10);

    foreach ([$parent, $tutor->user, User::factory()->admin()->create(['status' => UserStatus::Suspended])] as $outsider) {
        try {
            Livewire::actingAs($outsider)->test(ListLessons::class)->callTableAction('forceComplete', $lesson, ['note' => 'x']);
        } catch (Throwable) {
            // refused by any route is fine; the state below is the proof
        }

        expect($lesson->fresh()->status)->toBe(LessonStatus::InProgress);
    }
});
