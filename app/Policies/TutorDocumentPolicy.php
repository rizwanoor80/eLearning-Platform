<?php

namespace App\Policies;

use App\Models\TutorDocument;
use App\Models\User;

class TutorDocumentPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can view the model. Only the owning tutor
     * — admin review access is a separate policy check added in sub-cycle 1c.
     */
    public function view(User $user, TutorDocument $tutorDocument): bool
    {
        return $tutorDocument->tutorProfile->user_id === $user->id;
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
