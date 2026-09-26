<?php

namespace App\Services\Lessons;

use App\Enums\VideoParticipant;
use App\Models\Lesson;
use App\Models\User;

/**
 * Which side of a lesson a user is on. The learner side is the account holder, never the learner
 * (invariant 7: a minor has no login and nothing is sent or shown to one directly).
 */
final class LessonParties
{
    public static function participantFor(Lesson $lesson, User $user): ?VideoParticipant
    {
        if ($lesson->tutorProfile->user_id === $user->id) {
            return VideoParticipant::Tutor;
        }

        if ($lesson->learner->account_user_id === $user->id) {
            return VideoParticipant::Learner;
        }

        return null;
    }
}
