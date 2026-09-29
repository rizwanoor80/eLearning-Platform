<?php

use App\Actions\Lessons\ResolveDispute;
use App\Enums\DisputeReason;
use App\Enums\DisputeStatus;
use App\Enums\LedgerAccount;
use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Exceptions\DisputeException;
use App\Exceptions\LessonTransitionException;
use App\Models\AuditLog;
use App\Models\Dispute;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Ledger\LedgerService;

/**
 * A disputed lesson (price 10000, commission_pct 25 -> commission_amount 2500,
 * tutor_amount 7500), escrow already held, with one open dispute on it — mirrors
 * `lgLesson()`/`lgLedger()` (tests/Feature/Ledger/LedgerServiceTest.php).
 *
 * @return array{lesson: Lesson, dispute: Dispute, admin: User}
 */
function resolveSetup(array $lessonOverrides = [], bool $release = false): array
{
    $lesson = Lesson::factory()->withStatus(LessonStatus::Disputed)->create([
        'price' => 10000,
        'commission_pct' => 25,
        'commission_amount' => 2500,
        'tutor_amount' => 7500,
        ...$lessonOverrides,
    ]);

    app(LedgerService::class)->hold($lesson);
    if ($release) {
        app(LedgerService::class)->release($lesson);
    }

    $dispute = Dispute::factory()->for($lesson)->create();
    $admin = User::factory()->admin()->create();

    return ['lesson' => $lesson->fresh(), 'dispute' => $dispute, 'admin' => $admin];
}

// ---- the happy path (R150) --------------------------------------------------------------------

it('settles the ledger, writes the resolution columns, moves the lesson to settled, and audits it', function () {
    ['lesson' => $lesson, 'dispute' => $dispute, 'admin' => $admin] = resolveSetup();

    $resolved = app(ResolveDispute::class)($admin, $dispute, 37, 63, 'Split per the call recording.');

    expect($resolved->status)->toBe(LessonStatus::Settled);

    $fresh = $dispute->fresh();
    expect($fresh->status)->toBe(DisputeStatus::Resolved)
        ->and($fresh->parent_refund_pct)->toBe(37)
        ->and($fresh->tutor_pay_pct)->toBe(63)
        ->and($fresh->refund_amount)->toBe(3700)
        ->and($fresh->tutor_paid_amount)->toBe(4725)
        ->and($fresh->platform_delta)->toBe(1575)
        ->and($fresh->admin_note)->toBe('Split per the call recording.')
        ->and($fresh->resolved_by)->toBe($admin->id)
        ->and($fresh->resolved_at)->not->toBeNull();

    expect(app(LedgerService::class)->sum($lesson))->toBe(0)
        ->and(app(LedgerService::class)->balance($lesson, LedgerAccount::Refund))->toBe(3700)
        ->and(app(LedgerService::class)->balance($lesson, LedgerAccount::Tutor))->toBe(4725)
        ->and(app(LedgerService::class)->balance($lesson, LedgerAccount::Platform))->toBe(1575);

    $audit = AuditLog::query()->where('subject_type', Dispute::class)->where('subject_id', $dispute->id)->sole();
    expect($audit->action)->toBe('dispute.resolved')
        ->and($audit->actor_user_id)->toBe($admin->id)
        ->and($audit->before['status'])->toBe('open')
        ->and($audit->after['status'])->toBe('resolved')
        ->and($audit->after['refund_amount'])->toBe(3700)
        ->and($audit->after)->not->toHaveKey('description'); // never the account holder's free-text dispute description, invariant 9-adjacent
});

it('settles an already-released lesson via the reversal-aware path too', function () {
    ['lesson' => $lesson, 'dispute' => $dispute, 'admin' => $admin] = resolveSetup(release: true);

    app(ResolveDispute::class)($admin, $dispute, 0, 100, 'Tutor no-show confirmed.');

    expect($dispute->fresh()->status)->toBe(DisputeStatus::Resolved)
        ->and(app(LedgerService::class)->sum($lesson))->toBe(0)
        ->and(app(LedgerService::class)->balance($lesson, LedgerAccount::Tutor))->toBe(7500)
        ->and(app(LedgerService::class)->balance($lesson, LedgerAccount::Platform))->toBe(2500);
});

// ---- validation (DisputeException, no state changed) -------------------------------------------

it('requires a non-blank note', function () {
    ['dispute' => $dispute, 'admin' => $admin] = resolveSetup();

    expect(fn () => app(ResolveDispute::class)($admin, $dispute, 100, 0, '   '))
        ->toThrow(DisputeException::class, 'note is required');

    expect($dispute->fresh()->status)->toBe(DisputeStatus::Open);
});

it('requires both dials to be 0-100', function (int $parentPct, int $tutorPct) {
    ['dispute' => $dispute, 'admin' => $admin] = resolveSetup();

    expect(fn () => app(ResolveDispute::class)($admin, $dispute, $parentPct, $tutorPct, 'note'))
        ->toThrow(DisputeException::class, '0-100');

    expect($dispute->fresh()->status)->toBe(DisputeStatus::Open);
})->with([
    'parent pct negative' => [-1, 0],
    'parent pct over 100' => [101, 0],
    'tutor pct negative' => [0, -1],
    'tutor pct over 100' => [0, 101],
]);

it('refuses anyone who is not an active admin', function () {
    ['dispute' => $dispute] = resolveSetup();
    $suspendedAdmin = User::factory()->admin()->create(['status' => UserStatus::Suspended]);
    $nonAdmin = User::factory()->create(['role' => Role::AccountOwner]);

    foreach ([$suspendedAdmin, $nonAdmin] as $user) {
        expect(fn () => app(ResolveDispute::class)($user, $dispute, 100, 0, 'note'))
            ->toThrow(DisputeException::class, 'active admin');
    }

    expect($dispute->fresh()->status)->toBe(DisputeStatus::Open);
});

// ---- once, even under a double submit (advisor-flagged: the OpenDispute bug class again) -------

// The `disputed -> settled` edge assert fires before `$work` runs (LessonStateMachine), so a
// second resolve attempt on an already-settled lesson throws `LessonTransitionException`, not
// `DisputeException` -- exactly the bug class the 03:09 CYCLE-LOG entry caught in
// `DisputeController::store()`. Any caller (the still-unbuilt Filament action included) must
// catch both. This test proves the exception actually surfaces as `LessonTransitionException`
// here, so a caller that only catches `DisputeException` is provably wrong, not just suspect.
it('throws LessonTransitionException, not DisputeException, on a second resolve of an already-settled lesson', function () {
    ['dispute' => $dispute, 'admin' => $admin] = resolveSetup();

    app(ResolveDispute::class)($admin, $dispute, 100, 0, 'First resolution.');

    expect(fn () => app(ResolveDispute::class)($admin, $dispute->fresh(), 0, 100, 'Second resolution.'))
        ->toThrow(LessonTransitionException::class);

    $fresh = $dispute->fresh();
    expect($fresh->parent_refund_pct)->toBe(100)
        ->and($fresh->tutor_pay_pct)->toBe(0)
        ->and($fresh->admin_note)->toBe('First resolution.');
});

it('refuses a dispute that does not belong to the given lesson', function () {
    ['dispute' => $dispute, 'admin' => $admin] = resolveSetup();
    ['dispute' => $otherDispute] = resolveSetup();

    // Force a mismatch: point $dispute at a different lesson's row in memory (the lock re-reads
    // the DB row by id, so this only proves the lesson_id cross-check, not a stale in-memory read).
    $dispute->lesson_id = $otherDispute->lesson_id;

    expect(fn () => app(ResolveDispute::class)($admin, $dispute, 100, 0, 'note'))
        ->toThrow(RuntimeException::class);
});

// ---- prefillFor() (PRD §2.10, both dials nullable per docblock; 02:10 NOTE corrected trial to 100/0) --

it('prefills every R150/PRD §2.10 default: trial -> 100/0, everything else empty', function (LessonType $type, DisputeReason $reason, array $expected) {
    $dispute = Dispute::factory()
        ->for(Lesson::factory()->withStatus(LessonStatus::Disputed)->create(['type' => $type]))
        ->create(['reason' => $reason]);

    expect(ResolveDispute::prefillFor($dispute))->toBe($expected);
})->with([
    // trial -> 100/0 (both dials filled and admin-editable), regardless of the stated reason.
    'trial, quality reason' => [LessonType::Trial, DisputeReason::Quality, [100, 0]],
    'trial, no_show reason' => [LessonType::Trial, DisputeReason::NoShow, [100, 0]],
    // regular lesson, no_show reason: student- vs tutor-no-show cannot be told apart post hoc
    // (02:11 NOTE) -- no reachable default, both dials empty, admin must choose.
    'regular, no_show reason' => [LessonType::Regular, DisputeReason::NoShow, [null, null]],
    // regular lesson, any other reason -> R150's plain "otherwise empty" bucket.
    'regular, quality reason' => [LessonType::Regular, DisputeReason::Quality, [null, null]],
    'regular, technical reason' => [LessonType::Regular, DisputeReason::Technical, [null, null]],
    'regular, other reason' => [LessonType::Regular, DisputeReason::Other, [null, null]],
]);
