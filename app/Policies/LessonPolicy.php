<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Lessons\LessonParties;

/**
 * Cancel is parent-only in this slice (PLAN.md step 5) — no tutor-facing
 * cancel route exists yet, even though CancelLesson/SkipLesson both accept a
 * tutor actor. Booking is parent-only too: an account owner books for one of
 * their own, not deleted, learners.
 */
class LessonPolicy
{
    public function cancel(User $user, Lesson $lesson): bool
    {
        return $user->role === Role::AccountOwner && $lesson->learner->account_user_id === $user->id;
    }

    /**
     * The lesson's own tutor, or the account holder of its learner (CP6 7c) — never a learner, who has
     * no login, and never another parent or an admin acting as a party.
     */
    public function attend(User $user, Lesson $lesson): bool
    {
        return in_array($user->role, [Role::Tutor, Role::AccountOwner], true)
            && LessonParties::participantFor($lesson, $user) !== null;
    }

    /**
     * Booking a single lesson for one learner (R114). `BookLesson` re-checks ownership; this is the
     * controller's first, generic refusal.
     */
    public function bookFor(User $user, Learner $learner): bool
    {
        return $user->role === Role::AccountOwner
            && ! $learner->trashed()
            && $learner->account_user_id === $user->id;
    }
}
