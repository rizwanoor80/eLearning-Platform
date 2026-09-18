<?php

namespace App\Actions\Tutor;

use App\Actions\RecordAuditLog;
use App\Enums\TutorProfileStatus;
use App\Events\Tutor\TutorApproved;
use App\Exceptions\TutorApprovalBlockedException;
use App\Exceptions\TutorStatusTransitionException;
use App\Models\TutorProfile;
use App\Models\User;

class ApproveTutor
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    /**
     * @throws TutorApprovalBlockedException when a required document type
     *                                       still lacks an accepted document (CP1 acceptance).
     */
    public function __invoke(User $admin, TutorProfile $profile): void
    {
        // Only a submitted profile can be approved: a draft never finished
        // onboarding (no rate, no agreement) and a changes_requested profile
        // has not been resubmitted — approving either would put a
        // half-finished tutor through bookable() (invariant #5).
        if ($profile->status !== TutorProfileStatus::PendingReview) {
            throw new TutorStatusTransitionException('Only a profile pending review can be approved.');
        }

        if (! $profile->hasAllRequiredDocumentsAccepted()) {
            throw new TutorApprovalBlockedException(
                'Every required document type must have an accepted document before this tutor can be approved.',
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

        TutorApproved::dispatch($profile);
    }
}
