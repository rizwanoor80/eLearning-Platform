<?php

namespace App\Services\Tutors;

use App\Models\TutorProfile;

/**
 * What must hold before a tutor may be (re)approved: a valid permit, an
 * accepted copy of every required document, and a rate inside today's price
 * band (R36 f). Read at approval and at reinstatement — never cached — so an
 * expiry, a rejected document or an admin band edit since submission counts.
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

        if (! $profile->permitIsValid()) {
            $problems[] = 'the work permit has expired or has no expiry date';
        }

        if (! $profile->hasAllRequiredDocumentsAccepted()) {
            $problems[] = 'every required document type must have an accepted document';
        }

        $rateProblem = $this->rates->problemWithRate($profile);

        if ($rateProblem !== null) {
            $problems[] = $rateProblem;
        }

        return $problems;
    }
}
