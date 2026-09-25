<?php

namespace App\Policies;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Learner;
use App\Models\RecurringSlot;
use App\Models\User;

/**
 * A weekly slot belongs to its learner's account and its tutor. The parent and the tutor see
 * and end their own slot; only an active admin lists every slot, creates one with the trial
 * override, and pauses or resumes. Nobody edits or deletes a slot row: a slot only ever ends.
 * The slot actions authorise through this policy, so the rules live in one place.
 */
class RecurringSlotPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function view(User $user, RecurringSlot $slot): bool
    {
        return $this->isActiveAdmin($user) || $this->isParentOf($user, $slot) || $this->isTutorOf($user, $slot);
    }

    /**
     * The admin "Create weekly slot" entry point (no learner yet: the form picks one).
     */
    public function create(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    /**
     * Setting up a slot for one learner: the learner's parent, or an active admin (R96).
     */
    public function createFor(User $user, Learner $learner): bool
    {
        return $this->isActiveAdmin($user)
            || ($user->role === Role::AccountOwner && $learner->account_user_id === $user->id);
    }

    public function end(User $user, RecurringSlot $slot): bool
    {
        return $this->view($user, $slot);
    }

    public function pause(User $user, RecurringSlot $slot): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function resume(User $user, RecurringSlot $slot): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function update(User $user, RecurringSlot $slot): bool
    {
        return false;
    }

    public function delete(User $user, RecurringSlot $slot): bool
    {
        return false;
    }

    private function isActiveAdmin(User $user): bool
    {
        return $user->role === Role::Admin && $user->status === UserStatus::Active;
    }

    private function isParentOf(User $user, RecurringSlot $slot): bool
    {
        return $user->role === Role::AccountOwner
            && $slot->learner()->withTrashed()->value('account_user_id') === $user->id;
    }

    private function isTutorOf(User $user, RecurringSlot $slot): bool
    {
        return $user->role === Role::Tutor
            && $slot->tutorProfile()->value('user_id') === $user->id;
    }
}
