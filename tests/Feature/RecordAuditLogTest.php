<?php

use App\Actions\RecordAuditLog;
use App\Models\AuditLog;
use App\Models\TutorProfile;
use App\Models\User;

it('writes an append-only audit row with the actor, action and subject', function () {
    $admin = User::factory()->admin()->create();
    $profile = TutorProfile::factory()->create();

    $log = (new RecordAuditLog)($admin, 'tutor.approved', $profile, ['status' => 'pending_review'], ['status' => 'approved']);

    expect($log)->toBeInstanceOf(AuditLog::class)
        ->and($log->actor_user_id)->toBe($admin->id)
        ->and($log->action)->toBe('tutor.approved')
        ->and($log->subject_type)->toBe(TutorProfile::class)
        ->and($log->subject_id)->toBe($profile->id)
        ->and($log->before)->toBe(['status' => 'pending_review'])
        ->and($log->after)->toBe(['status' => 'approved'])
        ->and($log->created_at)->not->toBeNull();
});

it('has no updated_at column, since audit rows are append-only', function () {
    expect(AuditLog::UPDATED_AT)->toBeNull();
});
