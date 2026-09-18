<?php

namespace App\Actions\Tutor;

use App\Actions\RecordAuditLog;
use App\Enums\TutorProfileStatus;
use App\Events\Tutor\TutorApproved;
use App\Exceptions\TutorApprovalBlockedException;
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
