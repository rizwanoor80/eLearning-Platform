<?php

namespace App\Services\Tutors;

use App\Models\TutorProfile;
use App\Models\User;

/**
 * R171/R173(a): what a `draft`/`changes_requested` tutor still needs before they can submit for
 * review — name, country, timezone, a CV or LinkedIn, and the tutor agreement. This is the
 * submission minimum, not the approval minimum (subjects, rate, availability — see
 * `TutorApprovalReadiness`, which `ApproveTutor` checks separately once review starts). Drives the
 * tutor dashboard's "what's missing" banner (R173a); `TutorOnboardingController::currentStep()`
 * derives the same three gates in its own step order rather than reusing this list, since it also
 * needs to say which *one* step to show next, not just what remains.
 */
class TutorSubmissionReadiness
{
    /**
     * @return array<int, string>
     */
    public function missing(User $user, TutorProfile $profile): array
    {
        $missing = [];

        if (trim((string) $user->name) === '') {
            $missing[] = 'your name';
        }

        if ($profile->country === null) {
            $missing[] = 'your country';
        }

        if ($user->timezone === null) {
            $missing[] = 'your timezone';
        }

        if (! $profile->hasCvOrLinkedin()) {
            $missing[] = 'a CV or LinkedIn profile';
        }

        if ($profile->agreement_accepted_at === null) {
            $missing[] = 'accepting the tutor agreement';
        }

        return $missing;
    }
}
