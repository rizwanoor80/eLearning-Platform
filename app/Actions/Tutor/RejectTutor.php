<?php

namespace App\Actions\Tutor;

use App\Actions\RecordAuditLog;
use App\Enums\TutorProfileStatus;
use App\Events\Tutor\TutorRejected;
use App\Models\TutorProfile;
use App\Models\User;

class RejectTutor
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    public function __invoke(User $admin, TutorProfile $profile, string $note): void
    {
        $before = ['status' => $profile->status->value];

        $profile->forceFill([
            'status' => TutorProfileStatus::Rejected,
            'review_note' => $note,
        ])->save();

        ($this->recordAuditLog)($admin, 'tutor.rejected', $profile, $before, [
            'status' => TutorProfileStatus::Rejected->value,
            'review_note' => $note,
        ]);

        TutorRejected::dispatch($profile);
    }
}
