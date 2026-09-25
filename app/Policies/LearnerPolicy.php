<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Learner;
use App\Models\User;

/**
 * Learners belong to exactly one account owner; nobody else, admins included,
 * manages them through the parent area.
 */
class LearnerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === Role::AccountOwner;
    }

    public function create(User $user): bool
    {
        return $user->role === Role::AccountOwner;
    }

    public function view(User $user, Learner $learner): bool
    {
        return $this->owns($user, $learner);
    }

    public function update(User $user, Learner $learner): bool
    {
        return $this->owns($user, $learner);
    }

    public function delete(User $user, Learner $learner): bool
    {
        return $this->owns($user, $learner);
    }

    private function owns(User $user, Learner $learner): bool
    {
        return $user->role === Role::AccountOwner && $learner->account_user_id === $user->id;
    }
}
