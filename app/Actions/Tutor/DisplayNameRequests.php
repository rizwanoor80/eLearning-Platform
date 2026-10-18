<?php

namespace App\Actions\Tutor;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * The guard `ApproveDisplayName` and `RejectDisplayName` share: the pending request on a locked profile row,
 * and only if it is still the one the admin was shown.
 */
class DisplayNameRequests
{
    /** Only an active admin reviews a display name; enforced here because no policy covers the request columns. */
    public static function assertAdmin(User $user): void
    {
        if ($user->role !== Role::Admin || $user->status !== UserStatus::Active) {
            throw new AuthorizationException('Only an active admin can review a display name.');
        }
    }

    /**
     * R217: a closed (anonymised) account takes no display name. `AnonymizeUser` marks it by soft-deleting the user
     * row, the same column `TutorProfile::bookable()` reads, and its later unconditional UPDATE nulls the three
     * display-name columns. Called on a profile row this transaction has locked: whichever of the two commits
     * first, a request is either refused here or nulled there, and an anonymised tutor never keeps a name.
     */
    public static function assertAccountOpen(TutorProfile $locked, string $message): void
    {
        if (User::onlyTrashed()->whereKey($locked->user_id)->exists()) {
            throw ValidationException::withMessages(['display_name' => $message]);
        }
    }

    public static function current(TutorProfile $locked, string $expectedName): string
    {
        $requested = $locked->display_name_requested;

        if ($requested === null || $requested === '') {
            throw ValidationException::withMessages(['display_name' => 'There is no pending display-name request for this tutor.']);
        }

        if ($requested !== $expectedName) {
            throw ValidationException::withMessages(['display_name' => 'The tutor changed the request after you opened it. Review the new name.']);
        }

        return $requested;
    }
}
