<?php

namespace App\Policies;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\TutorDocument;
use App\Models\User;

class TutorDocumentPolicy
{
    /**
     * Determine whether the user can view any models. Admins only — Filament's
     * relation manager on the approval queue authorises through this on every
     * request after the first render (sub-cycle 1c).
     */
    public function viewAny(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    /**
     * Determine whether the user can view the model: the owning tutor, or
     * an ACTIVE admin reviewing it in the approval queue — a disabled admin
     * loses document access along with panel access (R28).
     */
    public function view(User $user, TutorDocument $tutorDocument): bool
    {
        return $tutorDocument->tutorProfile->user_id === $user->id || $this->isActiveAdmin($user);
    }

    private function isActiveAdmin(User $user): bool
    {
        return $user->role === Role::Admin && $user->status === UserStatus::Active;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, TutorDocument $tutorDocument): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, TutorDocument $tutorDocument): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, TutorDocument $tutorDocument): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, TutorDocument $tutorDocument): bool
    {
        return false;
    }
}
