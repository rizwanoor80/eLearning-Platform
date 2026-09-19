<?php

namespace App\Policies;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\MatchRequest;
use App\Models\User;

/**
 * Parents see and create only their own requests. An active admin may view
 * any request (the Filament match queue authorises through this policy);
 * the parent routes are separately closed to admins by `access-parent-area`.
 */
class MatchRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === Role::AccountOwner || $this->isActiveAdmin($user);
    }

    public function create(User $user): bool
    {
        return $user->role === Role::AccountOwner;
    }

    public function view(User $user, MatchRequest $request): bool
    {
        return ($user->role === Role::AccountOwner && $request->account_user_id === $user->id) || $this->isActiveAdmin($user);
    }

    private function isActiveAdmin(User $user): bool
    {
        return $user->role === Role::Admin && $user->status === UserStatus::Active;
    }
}
