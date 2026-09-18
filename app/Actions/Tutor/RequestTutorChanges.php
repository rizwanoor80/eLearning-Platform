<?php

namespace App\Actions\Tutor;

use App\Actions\RecordAuditLog;
use App\Enums\TutorProfileStatus;
use App\Events\Tutor\TutorChangesRequested;
use App\Models\TutorProfile;
use App\Models\User;

class RequestTutorChanges
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    public function __invoke(User $admin, TutorProfile $profile, string $note): void
    {
        $before = ['status' => $profile->status->value];

        $profile->forceFill([
            'status' => TutorProfileStatus::ChangesRequested,
            'review_note' => $note,
        ])->save();

        ($this->recordAuditLog)($admin, 'tutor.changes_requested', $profile, $before, [
            'status' => TutorProfileStatus::ChangesRequested->value,
            'review_note' => $note,
        ]);

        TutorChangesRequested::dispatch($profile);
    }
}
