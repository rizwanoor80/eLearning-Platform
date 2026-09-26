<?php

use App\Actions\Lessons\MarkNoShow;
use App\Actions\Lessons\MarkProviderFailure;
use App\Actions\Lessons\RecordAttendance;
use App\Actions\Lessons\SettleEndedLesson;
use App\Enums\CurriculumCode;
use App\Enums\LedgerAccount;
use App\Enums\LessonStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserStatus;
use App\Enums\VideoParticipant;
use App\Exceptions\AttendanceException;
use App\Exceptions\LessonTransitionException;
use App\Filament\Resources\Lessons\Pages\ListLessons;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\LedgerEntry;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\TutorProfile;
use App\Models\TutorStrike;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Lessons\LessonSettlement;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

afterEach(fn () => Carbon::setTestNow());

/**
 * A paid, held, confirmed lesson that started `$startedMinutesAgo` minutes ago (one hour long).
 *
 * @param  list<VideoParticipant>  $joined
 * @return array{lesson: Lesson, tutor: TutorProfile, parent: User}
 */
function seSetup(int $startedMinutesAgo, array $joined = []): array
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

// ---- both attended: completion -----------------------------------------------------------------------

it('completes a lesson both sides attended once its end has passed, with the report due date', function () {
    ['lesson' => $lesson] = seSetup(61, [VideoParticipant::Tutor, VideoParticipant::Learner]);

    $this->artisan('lessons:settle-ended')->assertSuccessful();

    $lesson->refresh();
    expect($lesson->status)->toBe(LessonStatus::Completed)
        ->and($lesson->completed_at)->not->toBeNull()
        ->and($lesson->report_due_at->toIso8601String())->toBe($lesson->ends_at->copy()->addHours(24)->toIso8601String())
        ->and($lesson->escrow_released_at)->toBeNull() // money waits for the report (7e)
        ->and(app(LedgerService::class)->balance($lesson, LedgerAccount::Escrow))->toBe($lesson->price->toFils())
        ->and(app(LedgerService::class)->sum($lesson))->toBe(0);
});

it('does not complete a lesson before its scheduled end', function () {
    ['lesson' => $lesson] = seSetup(59, [VideoParticipant::Tutor, VideoParticipant::Learner]);

    $this->artisan('lessons:settle-ended')->assertSuccessful();

    expect($lesson->fresh()->status)->toBe(LessonStatus::InProgress);
});

it('leaves a lesson only one side joined in progress, however late', function () {
    ['lesson' => $lesson] = seSetup(300, [VideoParticipant::Tutor]);

    $this->artisan('lessons:settle-ended')->assertSuccessful();

    expect($lesson->fresh()->status)->toBe(LessonStatus::InProgress)
        ->and(app(LedgerService::class)->balance($lesson, LedgerAccount::Escrow))->toBe($lesson->price->toFils());
});

it('completes once: a second run changes nothing', function () {
    ['lesson' => $lesson] = seSetup(70, [VideoParticipant::Tutor, VideoParticipant::Learner]);

    $this->artisan('lessons:settle-ended');
    $completedAt = $lesson->fresh()->completed_at;
    Carbon::setTestNow(now()->addHour());
    $this->artisan('lessons:settle-ended')->assertSuccessful();

    expect($lesson->fresh()->completed_at->toIso8601String())->toBe($completedAt->toIso8601String())
        ->and($lesson->fresh()->status)->toBe(LessonStatus::Completed);
});

// ---- nobody attended: refund -------------------------------------------------------------------------

it('refunds a lesson nobody attended at the scheduled end plus ten minutes, not before, with no strike', function () {
    ['lesson' => $lesson] = seSetup(60 + 9);

    $this->artisan('lessons:settle-ended')->assertSuccessful();
    expect($lesson->fresh()->status)->toBe(LessonStatus::Confirmed);

    Carbon::setTestNow(now()->addMinute());
    $this->artisan('lessons:settle-ended')->assertSuccessful();

    $ledger = app(LedgerService::class);
    expect($lesson->fresh()->status)->toBe(LessonStatus::Refunded)
        ->and($ledger->sum($lesson))->toBe(0)
        ->and($ledger->balance($lesson, LedgerAccount::Refund))->toBe($lesson->price->toFils())
        ->and($ledger->balance($lesson, LedgerAccount::Tutor))->toBe(0)
        ->and(TutorStrike::query()->count())->toBe(0)
        ->and(Payment::query()->where('lesson_id', $lesson->id)->sole()->status)->toBe(PaymentStatus::Refunded);
});

it('refunds an unattended lesson once: a second run adds no ledger entry', function () {
    ['lesson' => $lesson] = seSetup(90);

    $this->artisan('lessons:settle-ended');
    $entries = LedgerEntry::query()->where('lesson_id', $lesson->id)->count();
    $this->artisan('lessons:settle-ended')->assertSuccessful();

    expect(LedgerEntry::query()->where('lesson_id', $lesson->id)->count())->toBe($entries)
        ->and(app(LedgerService::class)->sum($lesson))->toBe(0);
});

it('does not touch a cancelled or an already-settled lesson', function () {
    ['lesson' => $cancelled] = seSetup(200);
    Lesson::query()->whereKey($cancelled->id)->update(['status' => LessonStatus::CancelledByParent]);
    ['lesson' => $done] = seSetup(200, [VideoParticipant::Tutor, VideoParticipant::Learner]);
    Lesson::query()->whereKey($done->id)->update(['status' => LessonStatus::Settled]);

    $this->artisan('lessons:settle-ended')->assertSuccessful();

    expect($cancelled->fresh()->status)->toBe(LessonStatus::CancelledByParent)
        ->and($done->fresh()->status)->toBe(LessonStatus::Settled);
});

// ---- provider failure (admin) --------------------------------------------------------------------------

it('refunds in full, without a strike or tutor pay, when an admin marks a provider failure', function () {
    ['lesson' => $lesson] = seSetup(5);
    $admin = User::factory()->admin()->create();

    $result = app(MarkProviderFailure::class)($admin, $lesson, 'Daily was down for the window');

    $ledger = app(LedgerService::class);
    expect($result->status)->toBe(LessonStatus::ProviderFailure)
        ->and($ledger->sum($lesson))->toBe(0)
        ->and($ledger->balance($lesson, LedgerAccount::Refund))->toBe($lesson->price->toFils())
        ->and($ledger->balance($lesson, LedgerAccount::Tutor))->toBe(0)
        ->and(TutorStrike::query()->count())->toBe(0)
        ->and(Payment::query()->where('lesson_id', $lesson->id)->sole()->status)->toBe(PaymentStatus::Refunded);

    $audit = AuditLog::query()->where('action', 'lesson.provider_failure')->sole();
    expect($audit->actor_user_id)->toBe($admin->id)->and(json_encode($audit->after))->toContain('Daily was down');
});

it('needs an admin and a note, and a lesson that has begun', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'parent' => $parent] = seSetup(5);
    ['lesson' => $future] = seSetup(-30);
    $admin = User::factory()->admin()->create();

    expect(fn () => app(MarkProviderFailure::class)($parent, $lesson, 'x'))->toThrow(AttendanceException::class)
        ->and(fn () => app(MarkProviderFailure::class)($tutor->user, $lesson, 'x'))->toThrow(AttendanceException::class)
        ->and(fn () => app(MarkProviderFailure::class)($admin, $lesson, '   '))->toThrow(AttendanceException::class, 'note')
        ->and(fn () => app(MarkProviderFailure::class)($admin, $future, 'x'))->toThrow(AttendanceException::class, 'not started');

    expect($lesson->fresh()->status)->toBe(LessonStatus::Confirmed)
        ->and($future->fresh()->status)->toBe(LessonStatus::Confirmed)
        ->and(LedgerEntry::query()->where('lesson_id', $lesson->id)->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'lesson.provider_failure')->count())->toBe(0);
});

it('refuses a provider failure for a lesson that is not confirmed and moves no money', function () {
    ['lesson' => $lesson] = seSetup(30, [VideoParticipant::Tutor]); // in progress: no edge to provider_failure
    $admin = User::factory()->admin()->create();

    expect(fn () => app(MarkProviderFailure::class)($admin, $lesson, 'Daily was down'))->toThrow(LessonTransitionException::class);

    expect($lesson->fresh()->status)->toBe(LessonStatus::InProgress)
        ->and(LedgerEntry::query()->where('lesson_id', $lesson->id)->count())->toBe(2);
});

it('marks a provider failure from the admin lesson list', function () {
    ['lesson' => $lesson] = seSetup(5);
    actingAs(User::factory()->admin()->create());

    Livewire::test(ListLessons::class)
        ->assertTableActionVisible('providerFailure', $lesson)
        ->callTableAction('providerFailure', $lesson, ['note' => 'Room would not open'])
        ->assertHasNoTableActionErrors();

    expect($lesson->fresh()->status)->toBe(LessonStatus::ProviderFailure);
});

it('hides the provider-failure action for a lesson that has not started or is not confirmed', function () {
    ['lesson' => $future] = seSetup(-30);
    ['lesson' => $running] = seSetup(30, [VideoParticipant::Tutor]);
    actingAs(User::factory()->admin()->create());

    Livewire::test(ListLessons::class)
        ->assertTableActionHidden('providerFailure', $future)
        ->assertTableActionHidden('providerFailure', $running);
});

it('keeps the admin lesson list from anyone but an active admin', function () {
    ['lesson' => $lesson, 'parent' => $parent] = seSetup(5);

    actingAs($parent)->get('/admin/lessons')->assertForbidden();
    actingAs(User::factory()->admin()->create(['status' => UserStatus::Suspended]))->get('/admin/lessons')->assertForbidden();
});

it('does not let a parent, a tutor or a suspended admin mark a provider failure from the list', function () {
    ['lesson' => $lesson, 'parent' => $parent, 'tutor' => $tutor] = seSetup(5);

    foreach ([$parent, $tutor->user, User::factory()->admin()->create(['status' => UserStatus::Suspended])] as $outsider) {
        try {
            Livewire::actingAs($outsider)->test(ListLessons::class)->callTableAction('providerFailure', $lesson, ['note' => 'x']);
        } catch (Throwable) {
            // refused by any route is fine; the state below is the proof
        }

        expect($lesson->fresh()->status)->toBe(LessonStatus::Confirmed);
    }

    expect(app(LedgerService::class)->balance($lesson, LedgerAccount::Refund))->toBe(0);
});

// ---- the two-hop outcomes commit as one ----------------------------------------------------------------

it('leaves an unattended lesson confirmed, with no ledger rows written, when its refund fails', function () {
    ['lesson' => $lesson] = seSetup(90);
    $entries = LedgerEntry::query()->where('lesson_id', $lesson->id)->count();

    app()->instance(LessonSettlement::class, new class(app(LedgerService::class)) extends LessonSettlement
    {
        public function refundParent(Lesson $locked, ?User $by): void
        {
            throw new RuntimeException('gateway ledger down');
        }
    });

    expect(fn () => app(SettleEndedLesson::class)($lesson))->toThrow(RuntimeException::class);

    expect($lesson->fresh()->status)->toBe(LessonStatus::Confirmed)
        ->and(LedgerEntry::query()->where('lesson_id', $lesson->id)->count())->toBe($entries);

    app()->forgetInstance(LessonSettlement::class);
    app(SettleEndedLesson::class)($lesson->fresh());

    expect($lesson->fresh()->status)->toBe(LessonStatus::Refunded);
});

it('still refunds a lesson whose only join came after the scheduled end, and refuses the no-show mark', function () {
    ['lesson' => $lesson, 'tutor' => $tutor] = seSetup(50);

    Carbon::setTestNow($lesson->ends_at->copy()->addMinutes(5));
    expect(app(RecordAttendance::class)($lesson->fresh(), VideoParticipant::Tutor, now()))->toBeFalse();
    expect(fn () => app(MarkNoShow::class)($tutor->user, $lesson->fresh()))->toThrow(AttendanceException::class);

    Carbon::setTestNow($lesson->ends_at->copy()->addMinutes(10));
    $this->artisan('lessons:settle-ended')->assertSuccessful();

    expect($lesson->fresh()->status)->toBe(LessonStatus::Refunded)
        ->and(app(LedgerService::class)->balance($lesson, LedgerAccount::Tutor))->toBe(0)
        ->and(TutorStrike::query()->count())->toBe(0);
});
