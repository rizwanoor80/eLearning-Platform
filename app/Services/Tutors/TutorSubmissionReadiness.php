<?php

namespace App\Services\Tutors;

use App\Models\TutorProfile;
use App\Models\User;

/**
 * R171/R173(a): what a `draft`/`changes_requested` tutor still needs before they can submit for
 * review — name, country, timezone, a CV or LinkedIn, and the tutor agreement. This is the
 * submission minimum, not the approval minimum (subjects, rate and the permit/document checks — availability is neither, R185 — see
 * `TutorApprovalReadiness`, which `ApproveTutor` checks separately once review starts). Drives the
 * tutor dashboard's "what's missing" banner (R173a); `TutorOnboardingController::currentStep()`
 * derives the same three gates in its own step order rather than reusing this list, since it also
 * needs to say which *one* step to show next, not just what remains.
 */
class TutorSubmissionReadiness
{
    /**
     * Each submission requirement and whether it holds — the one place the checks live, so the
     * missing-list below and the onboarding checklist's ticks (R186) can never disagree.
     *
     * @return array{name: bool, country: bool, cv_or_linkedin: bool, agreement: bool}
     */
    public function met(User $user, TutorProfile $profile): array
    {
        return [
            'name' => trim((string) $user->name) !== '',
            'country' => $profile->country !== null,
            // `users.timezone` is never null (non-nullable column, defaults to 'Asia/Dubai'), so it
            // cannot be "missing" — no check here, unlike the other submission-minimum fields.
            'cv_or_linkedin' => $profile->hasCvOrLinkedin(),
            'agreement' => $profile->agreement_accepted_at !== null,
        ];
    }

    /**
     * @return list<string>
     */
    public function missing(User $user, TutorProfile $profile): array
    {
        $met = $this->met($user, $profile);
        $labels = [
            'name' => 'your name',
            'country' => 'your country',
            'cv_or_linkedin' => 'a CV or LinkedIn profile',
            'agreement' => 'accepting the tutor agreement',
        ];

        $missing = [];

        foreach ($labels as $key => $label) {
            if (! $met[$key]) {
                $missing[] = $label;
            }
        }

        return $missing;
    }
}
