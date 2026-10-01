<?php

namespace App\Services\Tutors;

use App\Models\TutorProfile;

/**
 * What must hold before a tutor may be (re)approved: a permit that does not block booking, an
 * accepted copy of every required document, at least one subject with a rate inside today's price
 * band, and at least one availability window (R171 — these three, plus the already-enforced
 * document check, are "the minimum to be approved and searchable", checked here rather than at
 * onboarding). Read at approval and at reinstatement — never cached — so an expiry, a rejected
 * document or an admin band edit since submission counts.
 */
class TutorApprovalReadiness
{
    public function __construct(private TutorRateBands $rates) {}

    /**
     * @return array<int, string> the reasons the tutor is not ready; empty when ready
     */
    public function problems(TutorProfile $profile): array
    {
        $problems = [];

        if (! $profile->permitAllowsBooking()) {
            $problems[] = 'the work permit has expired';
        }

        if (! $profile->hasAllRequiredDocumentsAccepted()) {
            $problems[] = 'every required document type must have an accepted document';
        }

        $rateProblem = $this->rates->problemWithRate($profile);

        if ($rateProblem !== null) {
            $problems[] = $rateProblem;
        }

        if ($profile->availabilityRules()->doesntExist()) {
            $problems[] = 'no weekly availability window is set';
        }

        return $problems;
    }
}
