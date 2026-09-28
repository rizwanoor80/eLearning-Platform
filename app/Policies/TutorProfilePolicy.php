<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\TutorProfile;
use App\Models\User;

/**
 * CP7 8d (R137): filing a safeguarding report from a tutor's public profile. Any signed-in
 * account holder or tutor may report a profile — including a tutor reporting another tutor
 * — but never the profile's own owner reporting themselves.
 */
class TutorProfilePolicy
{
    public function reportAbuse(User $user, TutorProfile $tutorProfile): bool
    {
        return in_array($user->role, [Role::AccountOwner, Role::Tutor], true)
            && $user->id !== $tutorProfile->user_id;
    }
}
