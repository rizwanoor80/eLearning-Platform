<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Lesson;
use App\Models\User;

/**
 * Cancel is parent-only in this slice (PLAN.md step 5) — no tutor-facing
 * cancel route exists yet, even though CancelLesson/SkipLesson both accept a
 * tutor actor.
 */
class LessonPolicy
{
    public function cancel(User $user, Lesson $lesson): bool
    {
        return $user->role === Role::AccountOwner && $lesson->learner->account_user_id === $user->id;
    }
}
