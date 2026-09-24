<?php

use App\Actions\Admin\AnonymizeUser;
use App\Enums\LessonStatus;
use App\Enums\TutorProfileStatus;
use App\Exceptions\UserDeletionException;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\MatchRequest;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('refuses when the actor is not an admin', function () {
    $actor = User::factory()->create();
    $target = User::factory()->create();

    expect(fn () => app(AnonymizeUser::class)($actor, $target))
        ->toThrow(UserDeletionException::class, 'Only an admin can delete a user account.');

    expect($target->fresh()->trashed())->toBeFalse();
});

it('refuses to delete an admin account here', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->admin()->create();

    expect(fn () => app(AnonymizeUser::class)($admin, $target))
        ->toThrow(UserDeletionException::class);

    expect($target->fresh()->trashed())->toBeFalse();
});

it('refuses to delete a parent with a still-open lesson, naming it', function () {
    $admin = User::factory()->admin()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id]);
    $lesson = Lesson::factory()->create(['learner_id' => $learner->id, 'status' => LessonStatus::Confirmed]);

    expect(fn () => app(AnonymizeUser::class)($admin, $parent))
        ->toThrow(UserDeletionException::class, "#{$lesson->id}");

    expect($parent->fresh()->trashed())->toBeFalse();
});

it('refuses to delete a tutor with a still-open lesson, naming it', function () {
    $admin = User::factory()->admin()->create();
    $profile = TutorProfile::factory()->approved()->create();
    $lesson = Lesson::factory()->create(['tutor_profile_id' => $profile->id, 'status' => LessonStatus::Confirmed]);

    expect(fn () => app(AnonymizeUser::class)($admin, $profile->user))
        ->toThrow(UserDeletionException::class, "#{$lesson->id}");

    expect($profile->user->fresh()->trashed())->toBeFalse();
});

it('tombstones a parent, revokes sessions and passkeys, and audits without leaking the old PII', function () {
    $admin = User::factory()->admin()->create();
    $parent = User::factory()->withTwoFactor()->create(['phone' => '+971500000000']);
    $originalEmail = $parent->email;
    Learner::factory()->create(['account_user_id' => $parent->id]);

    DB::table('sessions')->insert(['id' => 'sess-1', 'user_id' => $parent->id, 'payload' => 'x', 'last_activity' => time()]);
    DB::table('passkeys')->insert([
        'user_id' => $parent->id, 'name' => 'Test key', 'credential_id' => 'cred-1',
        'credential' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
    ]);

    app(AnonymizeUser::class)($admin, $parent);

    $parent->refresh();

    expect($parent->trashed())->toBeTrue()
        ->and($parent->name)->toBe('Deleted user')
        ->and($parent->email)->toBe("deleted+{$parent->id}@tombstone.invalid")
        ->and($parent->phone)->toBeNull()
        ->and($parent->remember_token)->toBeNull()
        ->and($parent->two_factor_secret)->toBeNull()
        ->and($parent->two_factor_recovery_codes)->toBeNull()
        ->and($parent->two_factor_confirmed_at)->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $parent->id)->exists())->toBeFalse()
        ->and(DB::table('passkeys')->where('user_id', $parent->id)->exists())->toBeFalse();

    $log = AuditLog::query()->where('action', 'user.anonymized')->where('subject_id', $parent->id)->firstOrFail();

    expect($log->actor_user_id)->toBe($admin->id)
        ->and(json_encode($log->before))->not->toContain($originalEmail)
        ->and(json_encode($log->after))->not->toContain($originalEmail);
});

it('leaves match requests and learners intact, since anonymisation is not a cascade', function () {
    $admin = User::factory()->admin()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id]);
    MatchRequest::factory()->create(['account_user_id' => $parent->id, 'learner_id' => $learner->id]);

    app(AnonymizeUser::class)($admin, $parent);

    expect(MatchRequest::query()->count())->toBe(1)
        ->and(Learner::query()->count())->toBe(1);
});

it('propagates the tombstoned name to the account owner’s own learner, but not to a minor learner', function () {
    $admin = User::factory()->admin()->create();
    $parent = User::factory()->create(['name' => 'Original Name']);
    $self = Learner::factory()->selfLearner()->create(['account_user_id' => $parent->id, 'display_name' => 'Original Name']);
    $minor = Learner::factory()->create(['account_user_id' => $parent->id, 'display_name' => 'Minor Name']);

    app(AnonymizeUser::class)($admin, $parent);

    expect($self->fresh()->display_name)->toBe('Deleted user')
        ->and($minor->fresh()->display_name)->toBe('Minor Name');
});

it('suspends an approved tutor profile so bookable() excludes it, and leaves a non-approved one alone', function () {
    $admin = User::factory()->admin()->create();
    $profile = TutorProfile::factory()->approved()->create();

    app(AnonymizeUser::class)($admin, $profile->user);

    expect($profile->fresh()->status)->toBe(TutorProfileStatus::Suspended)
        ->and(TutorProfile::query()->bookable()->whereKey($profile->id)->exists())->toBeFalse();
});

it('does not attempt a tutor-status transition when the profile was never approved', function () {
    $admin = User::factory()->admin()->create();
    $profile = TutorProfile::factory()->create(['status' => TutorProfileStatus::Draft]);

    app(AnonymizeUser::class)($admin, $profile->user);

    expect($profile->fresh()->status)->toBe(TutorProfileStatus::Draft);
});
