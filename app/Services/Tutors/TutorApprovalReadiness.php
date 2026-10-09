<?php

namespace App\Services\Tutors;

use App\Enums\TutorReviewSection;
use App\Models\TutorProfile;

/**
 * What must hold before a tutor may be (re)approved: a permit that does not block booking, an
 * accepted copy of every required document, at least one subject with a rate inside today's price
 * band (R171, as split by R185: these are the minimum to be *approved*; availability is no longer
 * checked here — it is the extra condition for being listed and booked, in
 * `TutorProfile::scopeBookable()`, so an admin is never stuck on a tutor who has not set a
 * window). Checked here rather than at onboarding. Read at approval and at reinstatement — never cached —
 * so an expiry, a rejected document or an admin band edit since submission counts.
 */
class TutorApprovalReadiness
{
    public function __construct(private TutorRateBands $rates) {}

    /**
     * @return array<int, string> the reasons the tutor is not ready; empty when ready
     */
    public function problems(TutorProfile $profile): array
    {
        return array_values(array_map(fn (array $problem): string => $problem[1], $this->checks($profile)));
    }

    /**
     * The onboarding sections behind the problems (R185), so a reinstatement that fails can send the
     * tutor to exactly those.
     *
     * @return array<int, string> TutorReviewSection values, unique
     */
    public function sections(TutorProfile $profile): array
    {
        return array_values(array_unique(array_map(fn (array $problem): string => $problem[0]->value, $this->checks($profile))));
    }

    /**
     * @return array<int, array{0: TutorReviewSection, 1: string}>
     */
    private function checks(TutorProfile $profile): array
    {
        $problems = [];

        if (! $profile->permitAllowsBooking()) {
            $problems[] = [TutorReviewSection::Permit, 'the work permit has expired'];
        }

        if (! $profile->hasAllRequiredDocumentsAccepted()) {
            $problems[] = [TutorReviewSection::Documents, 'every required document type must have an accepted document'];
        }

        $rateProblem = $this->rates->problemWithRate($profile);

        if ($rateProblem !== null) {
            // With no subject there is no band to price against: send the tutor to Subjects first.
            $problems[] = [$profile->tutorSubjects()->doesntExist() ? TutorReviewSection::Subjects : TutorReviewSection::Rate, $rateProblem];
        }

        return $problems;
    }
}
