<?php

use App\Actions\RecordAuditLog;
use App\Actions\Tutor\CompleteTutorOnboarding;
use App\Actions\Tutor\SuspendTutorForStrikes;
use App\Enums\TutorDocumentStatus;
use App\Enums\TutorProfileStatus;
use App\Models\DocumentType;
use App\Models\TutorDocument;
use App\Models\TutorProfile;
use App\Models\TutorStrike;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * Cycle 19d item 1 (R225(b)): a tutor-actor transaction locks the tutor's `users` row (`FOR SHARE`) BEFORE it writes or
 * locks the `tutor_profiles` row, the order `AnonymizeUser` uses, so the audit insert's foreign key cannot deadlock
 * against an anonymisation. Proved by statement order on one connection (the two-connection proof is in
 * `DisplayNameLockOrderTest`).
 */

/**
 * @param  Closure(): void  $run
 * @return list<string>
 */
function tolStatements(Closure $run): array
{
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = strtolower($query->sql);
    });

    $run();

    return $statements;
}

/** @param list<string> $statements */
function tolFirst(array $statements, callable $match): ?int
{
    foreach ($statements as $i => $sql) {
        if ($match($sql)) {
            return $i;
        }
    }

    return null;
}

function tolOwnerShareLock(string $sql): bool
{
    return str_contains($sql, 'from "users"') && str_contains($sql, 'for share');
}

function tolProfileWrite(string $sql): bool
{
    return (str_contains($sql, 'from "tutor_profiles"') && str_contains($sql, 'for update'))
        || str_starts_with($sql, 'update "tutor_profiles"');
}

it('locks the tutor users row before the profile row when a tutor submits for review', function () {
    Event::fake();
    $profile = TutorProfile::factory()->create(['status' => TutorProfileStatus::Draft]);

    $statements = tolStatements(fn () => (new CompleteTutorOnboarding(app(RecordAuditLog::class)))($profile));

    $owner = tolFirst($statements, tolOwnerShareLock(...));
    $write = tolFirst($statements, tolProfileWrite(...));
    expect($owner)->not->toBeNull()->and($write)->not->toBeNull()->and($owner)->toBeLessThan($write);
    expect($profile->fresh()->status)->toBe(TutorProfileStatus::PendingReview);
});

it('locks the tutor users row before the profile write when a permit change resets the scan', function () {
    $permitType = DocumentType::factory()->create(['code' => DocumentType::PERMIT_CODE, 'required' => true, 'active' => true]);
    $tutor = User::factory()->tutor()->create();
    $profile = TutorProfile::factory()->create([
        'user_id' => $tutor->id,
        'status' => TutorProfileStatus::Draft,
        'permit_number' => 'PMT-1',
        'permit_expires_at' => '2030-01-15',
    ]);
    $scan = TutorDocument::factory()->for($profile, 'tutorProfile')->for($permitType, 'documentType')->accepted()
        ->create(['reviewed_by' => User::factory()->admin()->create()->id]);

    $statements = tolStatements(fn () => test()->actingAs($tutor)
        ->post(route('tutor.onboarding.permit'), ['permit_number' => 'PMT-2', 'permit_expires_at' => '2031-06-30'])
        ->assertSessionHasNoErrors());

    $owner = tolFirst($statements, tolOwnerShareLock(...));
    $write = tolFirst($statements, tolProfileWrite(...));
    expect($owner)->not->toBeNull()->and($write)->not->toBeNull()->and($owner)->toBeLessThan($write);
    expect($scan->fresh()->status)->toBe(TutorDocumentStatus::Pending);
});

it('adds no users lock to a suspension for strikes, which already holds the profile lock', function () {
    Event::fake();
    $profile = TutorProfile::factory()->approved()->create();
    TutorStrike::factory()->count(3)->create(['tutor_profile_id' => $profile->id, 'created_at' => now()->subDays(5)]);

    $statements = tolStatements(fn () => app(SuspendTutorForStrikes::class)($profile));

    expect($profile->fresh()->status)->toBe(TutorProfileStatus::Suspended)
        ->and(tolFirst($statements, tolOwnerShareLock(...)))->toBeNull();
});
