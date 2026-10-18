<?php

use App\Actions\Tutor\ApproveDisplayName;
use App\Actions\Tutor\RequestDisplayName;
use App\Filament\Resources\DisplayNameRequests\Pages\ListDisplayNameRequests;
use App\Models\AuditLog;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * Cycle 18a (R217, cycle 15 §6 finding B): a display-name request cannot land on an anonymised tutor. The marker
 * `AnonymizeUser` writes is `users.deleted_at` (the row is soft-deleted), the same column `bookable()` reads. The
 * deletions below are plain soft deletes, not `AnonymizeUser`, so a request that was in flight survives them.
 */
function dnxTutor(): TutorProfile
{
    return TutorProfile::factory()->approved()->create([
        'user_id' => User::factory()->tutor()->create(['name' => 'Amira Haddad']),
    ]);
}

it('refuses a request from a tutor whose account has been anonymised', function () {
    $profile = dnxTutor();
    $profile->user->delete();

    expect(fn () => app(RequestDisplayName::class)($profile->user, $profile, 'Ms Amira'))
        ->toThrow(ValidationException::class, 'closed');

    $fresh = $profile->fresh();

    expect($fresh->display_name_requested)->toBeNull()
        ->and($fresh->display_name_requested_at)->toBeNull()
        ->and(AuditLog::query()->where('action', 'tutor.display_name_requested')->exists())->toBeFalse();
});

it('keeps a tutor whose account was anonymised out of the admin queue', function () {
    $live = dnxTutor();
    app(RequestDisplayName::class)($live->user, $live, 'Ms Amira');

    $gone = dnxTutor();
    app(RequestDisplayName::class)($gone->user, $gone, 'Ms Layla');
    $gone->user->delete();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(ListDisplayNameRequests::class)
        ->assertCanSeeTableRecords([$live->fresh()])
        ->assertCanNotSeeTableRecords([$gone->fresh()]);
});

it('refuses to approve a request whose tutor was anonymised after asking', function () {
    $profile = dnxTutor();
    app(RequestDisplayName::class)($profile->user, $profile, 'Ms Amira');
    $profile->user->delete();
    AuditLog::query()->delete();

    expect(fn () => app(ApproveDisplayName::class)(User::factory()->admin()->create(), $profile->fresh(), 'Ms Amira'))
        ->toThrow(ValidationException::class, 'closed');

    $fresh = $profile->fresh();

    expect($fresh->display_name)->toBeNull()
        ->and($fresh->display_name_requested)->toBe('Ms Amira')
        ->and(AuditLog::query()->count())->toBe(0);
});
