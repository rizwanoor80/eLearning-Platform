<?php

namespace App\Actions\Tutor;

use App\Actions\RecordAuditLog;
use App\Enums\TutorProfileStatus;
use App\Events\Tutor\TutorApproved;
use App\Exceptions\TutorApprovalBlockedException;
use App\Exceptions\TutorStatusTransitionException;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Tutors\TutorApprovalReadiness;
use App\Services\Tutors\TutorStatusTransitions;

class ApproveTutor
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    /**
     * @throws TutorStatusTransitionException when the profile is not pending review.
     * @throws TutorApprovalBlockedException when the permit has expired, a required document type
     *                                       still lacks an accepted document, or the rate is outside
     *                                       today's price band (CP1 acceptance, R36 f).
     */
    public function __invoke(User $admin, TutorProfile $profile): void
    {
        // Only a submitted profile can be approved: a draft never finished
        // onboarding (no rate, no agreement) and a changes_requested profile
        // has not been resubmitted — approving either would put a
        // half-finished tutor through bookable() (invariant #5). The edge is
        // asserted on the locked row, and the readiness re-checked there:
        // what was true at submission may not be true now.
        TutorStatusTransitions::transition(
            $profile,
            TutorProfileStatus::Approved,
            'Only a profile pending review can be approved.',
            function (TutorProfile $profile) use ($admin) {
                // Suspended -> approved is an allowed edge, but only ReinstateTutor takes it.
                if ($profile->status !== TutorProfileStatus::PendingReview) {
                    throw new TutorStatusTransitionException('Only a profile pending review can be approved.');
                }

                $problems = app(TutorApprovalReadiness::class)->problems($profile);

                if ($problems !== []) {
                    throw new TutorApprovalBlockedException(
                        'This tutor cannot be approved yet: '.implode('; ', $problems).'.',
                    );
                }

                $before = ['status' => $profile->status->value];

                $profile->forceFill([
                    'status' => TutorProfileStatus::Approved,
                    'approved_by' => $admin->id,
                    'approved_at' => now(),
                ])->save();

                ($this->recordAuditLog)($admin, 'tutor.approved', $profile, $before, [
                    'status' => TutorProfileStatus::Approved->value,
                    'approved_by' => $admin->id,
                ]);
            },
        );

        // After commit: a listener must never see a transition that rolls back.
        TutorApproved::dispatch($profile);
    }
}
