<?php

use App\Actions\RecordAuditLog;
use App\Actions\Tutor\ApproveTutor;
use App\Actions\Tutor\CompleteTutorOnboarding;
use App\Actions\Tutor\ReinstateTutor;
use App\Actions\Tutor\RejectTutor;
use App\Actions\Tutor\RequestTutorChanges;
use App\Actions\Tutor\SuspendTutor;
use App\Enums\TutorProfileStatus;
use App\Events\Tutor\TutorApproved;
use App\Exceptions\TutorApprovalBlockedException;
use App\Exceptions\TutorStatusTransitionException;
use App\Models\AuditLog;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Tutors\TutorStatusTransitions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\assertDatabaseHas;

/**
 * R36: the allowed tutor-status edges, written out independently of the class
 * under test. Every (from, to) pair is either here or forbidden.
 *
 * @return array<string, array<int, string>>
 */
function trExpectedEdges(): array
{
    return [
        'draft' => ['pending_review'],
        'pending_review' => ['approved', 'changes_requested', 'rejected'],
        'changes_requested' => ['pending_review', 'changes_requested', 'rejected'],
        'approved' => ['suspended', 'changes_requested'],
        'suspended' => ['approved', 'changes_requested'],
        'rejected' => [],
    ];
}

/**
 * Every ordered pair of the six statuses — 36 — as a dataset.
 *
 * @return array<string, array{0: TutorProfileStatus, 1: TutorProfileStatus, 2: bool}>
 */
function trAllPairs(): array
{
    $pairs = [];

    foreach (TutorProfileStatus::cases() as $from) {
        foreach (TutorProfileStatus::cases() as $to) {
            $pairs["{$from->value} -> {$to->value}"] = [$from, $to, in_array($to->value, trExpectedEdges()[$from->value], true)];
        }
    }

    return $pairs;
}

it('has exactly the documented edges, for every one of the 36 pairs', function (TutorProfileStatus $from, TutorProfileStatus $to, bool $allowed) {
    expect(TutorStatusTransitions::allows($from, $to))->toBe($allowed);

    if ($allowed) {
        TutorStatusTransitions::assert($from, $to);
    } else {
        expect(fn () => TutorStatusTransitions::assert($from, $to))->toThrow(TutorStatusTransitionException::class);
    }
})->with(trAllPairs());

it('knows every status', function () {
    expect(array_keys(TutorStatusTransitions::edges()))->toEqualCanonicalizing(array_column(TutorProfileStatus::cases(), 'value'))
        ->and(array_keys(trExpectedEdges()))->toEqualCanonicalizing(array_column(TutorProfileStatus::cases(), 'value'));
});

/**
 * Drives the REAL action that targets `$to`, from a profile in `$from` that is
 * ready in every other respect (valid permit, no required documents, a rate
 * inside its band), and returns the profile.
 */
function trAttempt(TutorProfileStatus $from, TutorProfileStatus $to): TutorProfile
{
    $admin = User::factory()->admin()->create();
    $profile = TutorProfile::factory()->approvable()->create([
        'status' => $from,
        'permit_expires_at' => now()->addYear()->toDateString(),
    ]);

    $audit = app(RecordAuditLog::class);

    match ($to) {
        TutorProfileStatus::PendingReview => (new CompleteTutorOnboarding($audit))($profile),
        TutorProfileStatus::Approved => $from === TutorProfileStatus::Suspended
            ? (new ReinstateTutor($audit))($admin, $profile)
            : (new ApproveTutor($audit))($admin, $profile),
        TutorProfileStatus::ChangesRequested => $from === TutorProfileStatus::Suspended
            // Suspended -> changes_requested is reinstatement of a tutor who is not ready.
            ? (function () use ($audit, $admin, $profile) {
                $profile->forceFill(['permit_expires_at' => now()->subDay()->toDateString()])->save();
                (new ReinstateTutor($audit))($admin, $profile);
            })()
            : (new RequestTutorChanges($audit))($admin, $profile, 'note'),
        TutorProfileStatus::Rejected => (new RejectTutor($audit))($admin, $profile, 'note'),
        TutorProfileStatus::Suspended => (new SuspendTutor($audit))($admin, $profile, 'note'),
        // Nothing moves a tutor back to draft; only the table can be asked.
        TutorProfileStatus::Draft => TutorStatusTransitions::assert($from, $to),
    };

    return $profile;
}

it('moves a tutor along every allowed edge through its real action, and refuses every other pair', function (TutorProfileStatus $from, TutorProfileStatus $to, bool $allowed) {
    Event::fake();

    if ($allowed) {
        $profile = trAttempt($from, $to);

        expect($profile->fresh()->status)->toBe($to);

        return;
    }

    $failure = null;

    try {
        trAttempt($from, $to);
    } catch (TutorStatusTransitionException $e) {
        $failure = $e;
    }

    expect($failure)->not->toBeNull();
    // Nothing was written: the row is still where it started.
    assertDatabaseHas('tutor_profiles', ['status' => $from->value]);
})->with(trAllPairs());

it('asserts the edge on the locked row, not on a stale in-memory copy', function () {
    Event::fake();
    $admin = User::factory()->admin()->create();
    $profile = TutorProfile::factory()->approvable()->create(['status' => TutorProfileStatus::PendingReview]);

    // Another admin rejected the tutor after this admin's page loaded.
    $stale = TutorProfile::query()->findOrFail($profile->id);
    TutorProfile::query()->whereKey($profile->id)->update(['status' => TutorProfileStatus::Rejected]);

    expect(fn () => (new ApproveTutor(app(RecordAuditLog::class)))($admin, $stale))
        ->toThrow(TutorStatusTransitionException::class);
    expect($profile->fresh()->status)->toBe(TutorProfileStatus::Rejected);
    Event::assertNotDispatched(TutorApproved::class);
});

it('dispatches the event after the transaction has committed (R30 / after-commit proof)', function () {
    $admin = User::factory()->admin()->create();
    $profile = TutorProfile::factory()->approvable()->create(['status' => TutorProfileStatus::PendingReview]);

    $baseline = DB::transactionLevel();
    $levelSeenByListener = null;
    Event::listen(TutorApproved::class, function () use (&$levelSeenByListener) {
        $levelSeenByListener = DB::transactionLevel();
    });

    (new ApproveTutor(app(RecordAuditLog::class)))($admin, $profile);

    // Under RefreshDatabase the test itself runs in one transaction (the baseline);
    // the action's own transaction must be closed by the time the listener runs.
    expect($levelSeenByListener)->toBe($baseline);
});

it('writes nothing when the approval is blocked inside the transaction', function () {
    Event::fake();
    $admin = User::factory()->admin()->create();
    $profile = TutorProfile::factory()->create(['status' => TutorProfileStatus::PendingReview]); // no subject, no band

    expect(fn () => (new ApproveTutor(app(RecordAuditLog::class)))($admin, $profile))
        ->toThrow(TutorApprovalBlockedException::class);

    expect($profile->fresh()->status)->toBe(TutorProfileStatus::PendingReview)
        ->and(AuditLog::query()->where('action', 'tutor.approved')->exists())->toBeFalse();
    Event::assertNotDispatched(TutorApproved::class);
});
