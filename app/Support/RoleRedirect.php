<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;

class RoleRedirect
{
    /**
     * The landing route name for a user's role.
     */
    public static function routeName(User $user): string
    {
        return match ($user->role) {
            Role::Tutor => 'tutor.onboarding',
            Role::Admin => 'filament.admin.pages.dashboard',
            Role::AccountOwner => 'dashboard',
        };
    }
}
