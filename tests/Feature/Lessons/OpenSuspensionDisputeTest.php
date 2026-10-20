<?php

use App\Actions\Lessons\CancelLesson;
use App\Actions\Lessons\OpenDispute;
use App\Actions\Lessons\OpenSuspensionDispute;
use App\Actions\Lessons\RecordAttendance;
use App\Actions\Lessons\ResolveDispute;
use App\Actions\Lessons\SettleEndedLesson;
use App\Actions\RecordAuditLog;
use App\Enums\CurriculumCode;
use App\Enums\DisputeReason;
use App\Enums\DisputeStatus;
use App\Enums\LedgerAccount;
use App\Enums\LedgerEntryType;
use App\Enums\LessonCancelReason;
use App\Enums\LessonStatus;
use App\Enums\UserStatus;
use App\Enums\VideoParticipant;
use App\Exceptions\DisputeException;
use App\Exceptions\LessonTransitionException;
use App\Filament\Resources\Lessons\Pages\ListLessons;
use App\Mail\Admin\AdminDisputeOpenedMail;
use App\Mail\Lessons\DisputeOpenedMail;
use App\Mail\Lessons\LessonCancelledMail;
use App\Mail\Lessons\LessonSkippedMail;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Dispute;
use App\Models\Learner;
use App\Models\LedgerEntry;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\TutorProfile;
use App\Models\User;
use App\Notifications\Lessons\DisputeOpenedNotification;
use App\Notifications\Lessons\LessonCancelledNotification;
use App\Services\Ledger\LedgerService;
use App\Services\Lessons\LessonStateMachine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

afterEach(fn () => Carbon::setTestNow());

/**
 * A paid, held lesson that started 71 minutes ago (one hour long) with a suspended account, optionally settled by the
 * sweep: `parent_confirmed` (parent suspended, nobody joined) and `parent_in_progress` (parent suspended, only the tutor
 * joined) end `completed_reported`; `tutor_in_progress` (tutor suspended, only the tutor joined) ends `refunded`.
 *
 * @return array{lesson: Lesson, parent: User, tutor: TutorProfile}
 */
function osdLesson(string $row, array $overrides = [], bool $settle = true): array
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
        'starts_at' => now()->subMinutes(71),
        'ends_at' => now()->subMinutes(11),
        ...$overrides,
    ]);
    Payment::factory()->create(['lesson_id' => $lesson->id, 'payer_user_id' => $parent->id, 'amount' => $lesson->price]);
    app(LedgerService::class)->hold($lesson);
    $lesson->forceFill(['room_provider' => 'fake', 'room_id' => 'lesson-'.$lesson->id, 'room_created_at' => now()->subHour()])->save();

    if ($row !== 'parent_confirmed') {
        app(RecordAttendance::class)($lesson->fresh(), VideoParticipant::Tutor, now()->subMinutes(70));
    }

    $suspended = $row === 'tutor_in_progress' ? $tutor->user : $parent;
    app(RecordAuditLog::class)(null, 'lesson.room_closed_suspension', $lesson, null, ['account_user_id' => $suspended->id]);

    if ($settle) {
        expect(app(SettleEndedLesson::class)($lesson->fresh()))->toBe($row === 'tutor_in_progress' ? LessonStatus::Refunded : LessonStatus::CompletedReported);
    }

    return ['lesson' => $lesson->fresh(), 'parent' => $parent, 'tutor' => $tutor];
}

function osdOpen(Lesson $lesson, ?User $admin = null, string $description = 'The family could not join: their account was suspended.'): Dispute
{
    return app(OpenSuspensionDispute::class)($admin ?? User::factory()->admin()->create(), $lesson->fresh(), DisputeReason::Other, $description);
}

// ---- R243 money proof: a settled-after-suspension lesson resolves through ResolveDispute at every dial ------------

it('resolves both completed_reported suspension rows through ResolveDispute with the ledger at zero', function (string $row, int $refundPct, int $payPct) {
    ['lesson' => $lesson] = osdLesson($row);

    $dispute = osdOpen($lesson);
    app(ResolveDispute::class)(User::factory()->admin()->create(), $dispute, $refundPct, $payPct, 'Money proof.');

    $ledger = app(LedgerService::class);
    $price = $lesson->price->toFils();

    expect($lesson->fresh()->status)->toBe(LessonStatus::Settled)
        ->and($ledger->sum($lesson))->toBe(0)
        ->and($ledger->balance($lesson, LedgerAccount::Escrow))->toBe(0)
        ->and($ledger->balance($lesson, LedgerAccount::Refund))->toBe(intdiv($price * $refundPct, 100))
        ->and($ledger->balance($lesson, LedgerAccount::Tutor))->toBe(intdiv($lesson->tutor_amount->toFils() * $payPct, 100))
        ->and(LedgerEntry::query()->where('lesson_id', $lesson->id)->where('type', LedgerEntryType::ReleaseReversal)->count())->toBe(4)
        ->and(Artisan::call('ledger:verify'))->toBe(0);
})->with(['parent_confirmed', 'parent_in_progress'])->with([
    '0/0' => [0, 0],
    '100/0' => [100, 0],
    '0/100' => [0, 100],
    '50/50' => [50, 50],
    '100/100' => [100, 100],
]);

it('keeps the ledger at zero when an odd price is split 50/50', function (string $row) {
    ['lesson' => $lesson] = osdLesson($row, ['price' => 9999, 'commission_pct' => 25, 'commission_amount' => 2500, 'tutor_amount' => 7499]);

    app(ResolveDispute::class)(User::factory()->admin()->create(), osdOpen($lesson), 50, 50, 'Odd price.');

    expect(app(LedgerService::class)->sum($lesson))->toBe(0)
        ->and(app(LedgerService::class)->balance($lesson, LedgerAccount::Escrow))->toBe(0)
        ->and(Artisan::call('ledger:verify'))->toBe(0);
})->with(['parent_confirmed', 'parent_in_progress']);

// ---- R243 the action ----------------------------------------------------------------------------------------

it('opens a dispute on a settled-after-suspension lesson as the admin, audited without the description', function (string $row) {
    ['lesson' => $lesson] = osdLesson($row);
    $admin = User::factory()->admin()->create();

    $dispute = osdOpen($lesson, $admin, 'Private admin text.');

    expect($lesson->fresh()->status)->toBe(LessonStatus::Disputed)
        ->and($dispute->lesson_id)->toBe($lesson->id)
        ->and($dispute->opened_by_user_id)->toBe($admin->id)
        ->and($dispute->reason)->toBe(DisputeReason::Other)
        ->and($dispute->status)->toBe(DisputeStatus::Open);

    $audit = AuditLog::query()->where('action', 'lesson.dispute_opened_by_admin')->sole();
    expect($audit->actor_user_id)->toBe($admin->id)
        ->and($audit->subject_id)->toBe($lesson->id)
        ->and($audit->after)->toBe(['status' => 'disputed', 'dispute_id' => $dispute->id, 'reason' => 'other'])
        ->and(json_encode($audit->after))->not->toContain('Private admin text');
})->with(['parent_confirmed', 'parent_in_progress']);

it('tells the tutor and the admins, never the parent', function () {
    Mail::fake();
    Notification::fake();
    ['lesson' => $lesson, 'parent' => $parent, 'tutor' => $tutor] = osdLesson('parent_confirmed');
    $admin = User::factory()->admin()->create();

    osdOpen($lesson, $admin);

    Mail::assertQueued(DisputeOpenedMail::class, fn (DisputeOpenedMail $m): bool => $m->hasTo($tutor->user->email));
    Mail::assertNotQueued(DisputeOpenedMail::class, fn (DisputeOpenedMail $m): bool => $m->hasTo($parent->email));
    Mail::assertQueued(AdminDisputeOpenedMail::class, fn (AdminDisputeOpenedMail $m): bool => $m->hasTo($admin->email));
    Notification::assertSentTo($tutor->user, DisputeOpenedNotification::class);
    Notification::assertNotSentTo($parent, DisputeOpenedNotification::class);
});

it('refuses anyone who is not an active admin', function () {
    ['lesson' => $lesson, 'parent' => $parent, 'tutor' => $tutor] = osdLesson('parent_confirmed');

    foreach ([$parent, $tutor->user, User::factory()->admin()->create(['status' => UserStatus::Suspended])] as $user) {
        expect(fn () => osdOpen($lesson, $user))->toThrow(DisputeException::class, 'active admin');
    }

    expect($lesson->fresh()->status)->toBe(LessonStatus::CompletedReported)
        ->and(Dispute::query()->count())->toBe(0);
});

it('refuses a completed_reported lesson no suspension settled', function () {
    ['lesson' => $lesson] = osdLesson('parent_confirmed', settle: false);
    $lesson = LessonStateMachine::transition($lesson, LessonStatus::CancelledByParent);
    LessonStateMachine::transition($lesson, LessonStatus::CompletedReported);

    expect(fn () => osdOpen($lesson))->toThrow(DisputeException::class, 'not settled after a suspension');
    expect(Dispute::query()->count())->toBe(0);
});

it('refuses the tutor-suspension refund: the parent already has everything back', function () {
    ['lesson' => $lesson] = osdLesson('tutor_in_progress');

    expect($lesson->status)->toBe(LessonStatus::Refunded)
        ->and(OpenSuspensionDispute::problemFor(User::factory()->admin()->create(), $lesson))->toBe('This lesson cannot be disputed.');
    expect(fn () => osdOpen($lesson))->toThrow(LessonTransitionException::class);
    expect(Dispute::query()->count())->toBe(0);
});

it('allows exactly seven days from the end and refuses a second more', function () {
    Carbon::setTestNow(now()->startOfSecond());
    ['lesson' => $lesson] = osdLesson('parent_confirmed');
    $lesson->forceFill(['starts_at' => now()->subDays(7)->subHour(), 'ends_at' => now()->subDays(7)])->save();
    $admin = User::factory()->admin()->create();

    expect(OpenSuspensionDispute::problemFor($admin, $lesson->fresh()))->toBeNull();

    $lesson->forceFill(['starts_at' => now()->subDays(7)->subSecond()->subHour(), 'ends_at' => now()->subDays(7)->subSecond()])->save();

    expect(OpenSuspensionDispute::problemFor($admin, $lesson->fresh()))->toContain('7-day window');
    expect(fn () => osdOpen($lesson, $admin))->toThrow(DisputeException::class, '7-day window');
    expect($lesson->fresh()->status)->toBe(LessonStatus::CompletedReported);
});

it('opens one dispute per lesson, whoever gets there first', function () {
    ['lesson' => $lesson, 'parent' => $parent] = osdLesson('parent_confirmed');
    $lesson->forceFill(['starts_at' => now()->subHours(2)->subHour(), 'ends_at' => now()->subHours(2)])->save();

    osdOpen($lesson);

    expect(fn () => osdOpen($lesson))->toThrow(LessonTransitionException::class);
    expect(fn () => app(OpenDispute::class)($parent, $lesson->fresh(), DisputeReason::Quality, 'Later.'))->toThrow(LessonTransitionException::class);
    expect(Dispute::query()->count())->toBe(1);

    ['lesson' => $other, 'parent' => $otherParent] = osdLesson('parent_confirmed');
    $other->forceFill(['starts_at' => now()->subHours(2)->subHour(), 'ends_at' => now()->subHours(2)])->save();
    app(OpenDispute::class)($otherParent, $other->fresh(), DisputeReason::Quality, 'First.');

    expect(fn () => osdOpen($other))->toThrow(LessonTransitionException::class);
    expect(Dispute::query()->count())->toBe(2);
});

it('needs a description of one to 2000 characters', function (string $description) {
    ['lesson' => $lesson] = osdLesson('parent_confirmed');

    expect(fn () => osdOpen($lesson, description: $description))->toThrow(DisputeException::class, 'description');
    expect($lesson->fresh()->status)->toBe(LessonStatus::CompletedReported);
})->with(['empty' => '', 'too long' => str_repeat('x', 2001)]);

it('opens from the admin lessons list, only where the action is allowed', function () {
    ['lesson' => $eligible] = osdLesson('parent_confirmed');
    ['lesson' => $refunded] = osdLesson('tutor_in_progress');
    ['lesson' => $ordinary] = osdLesson('parent_confirmed', settle: false);
    $ordinary = LessonStateMachine::transition($ordinary, LessonStatus::CancelledByParent);
    $ordinary = LessonStateMachine::transition($ordinary, LessonStatus::CompletedReported);
    actingAs(User::factory()->admin()->create());

    Livewire::test(ListLessons::class)
        ->assertTableActionVisible('openSuspensionDispute', $eligible)
        ->assertTableActionHidden('openSuspensionDispute', $refunded)
        ->assertTableActionHidden('openSuspensionDispute', $ordinary)
        ->callTableAction('openSuspensionDispute', $eligible, ['reason' => 'technical', 'description' => 'Account suspended mid-lesson.'])
        ->assertHasNoTableActionErrors()
        ->assertTableActionHidden('openSuspensionDispute', $eligible);

    expect($eligible->fresh()->status)->toBe(LessonStatus::Disputed)
        ->and(Dispute::query()->where('lesson_id', $eligible->id)->sole()->reason)->toBe(DisputeReason::Technical);
});

// ---- R242 no after-the-fact cancellation mail or bell ---------------------------------------------------------

it('sends no cancelled mail or bell for the cancellation a suspension settlement writes', function () {
    Mail::fake();
    Notification::fake();
    ['lesson' => $lesson, 'parent' => $parent, 'tutor' => $tutor] = osdLesson('parent_confirmed');

    expect($lesson->status)->toBe(LessonStatus::CompletedReported);
    Mail::assertNotQueued(LessonCancelledMail::class);
    Mail::assertNotSent(LessonCancelledMail::class);
    Notification::assertNotSentTo($parent, LessonCancelledNotification::class);
    Notification::assertNotSentTo($tutor->user, LessonCancelledNotification::class);
});

it('still announces an ordinary late cancellation to both parties', function () {
    Mail::fake();
    Notification::fake();
    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id]);
    $lesson = Lesson::factory()->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'starts_at' => now()->addHours(2),
        'ends_at' => now()->addHours(3),
        'cancel_window_hours' => 24,
    ]);
    Payment::factory()->create(['lesson_id' => $lesson->id, 'payer_user_id' => $parent->id, 'amount' => $lesson->price]);
    app(LedgerService::class)->hold($lesson);

    app(CancelLesson::class)($parent, $lesson->fresh(), 'change of plans');

    expect($lesson->fresh()->status)->toBe(LessonStatus::CompletedReported);
    Mail::assertQueued(LessonCancelledMail::class, 2);
    Notification::assertSentTo($parent, LessonCancelledNotification::class);
    Notification::assertSentTo($tutor->user, LessonCancelledNotification::class);
});

it('does not read account_suspended alone as a settled suspension', function () {
    Mail::fake();
    Notification::fake();
    $lesson = Lesson::factory()->withStatus(LessonStatus::Confirmed)->create();

    LessonStateMachine::transition($lesson, LessonStatus::CancelledByParent, fn (Lesson $locked) => $locked->forceFill(['cancel_reason' => LessonCancelReason::AccountSuspended->value]));

    Mail::assertQueued(LessonCancelledMail::class, 2);
    Notification::assertSentTo($lesson->learner->account, LessonCancelledNotification::class);
    Notification::assertSentTo($lesson->tutorProfile->user, LessonCancelledNotification::class);
});

it('keeps the free release of a reserved lesson on account_suspended exactly as it was: silent', function () {
    Mail::fake();
    Notification::fake();
    $lesson = Lesson::factory()->withStatus(LessonStatus::Reserved)->create();

    LessonStateMachine::transition($lesson, LessonStatus::CancelledByParent, fn (Lesson $locked) => $locked->forceFill(['cancel_reason' => LessonCancelReason::AccountSuspended->value]));

    Mail::assertNotQueued(LessonCancelledMail::class);
    Mail::assertNotQueued(LessonSkippedMail::class);
    Notification::assertNothingSent();
});

// ---- R244 the sweep's summary line ------------------------------------------------------------------------------

it('pins the sweep summary: completed, refunded and settled after a suspension are counted apart', function () {
    osdLesson('parent_confirmed', settle: false);
    osdLesson('parent_in_progress', settle: false);
    osdLesson('tutor_in_progress', settle: false);

    $both = osdLesson('parent_confirmed', settle: false)['lesson'];
    app(RecordAttendance::class)($both->fresh(), VideoParticipant::Tutor, now()->subMinutes(70));
    app(RecordAttendance::class)($both->fresh(), VideoParticipant::Learner, now()->subMinutes(70));

    $this->artisan('lessons:settle-ended')
        ->expectsOutput('Completed 1, refunded 1, settled after a suspension 2.')
        ->assertSuccessful();

    $this->artisan('lessons:settle-ended')
        ->expectsOutput('Completed 0, refunded 0, settled after a suspension 0.')
        ->assertSuccessful();
});
