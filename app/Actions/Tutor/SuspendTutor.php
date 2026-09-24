<?php

namespace App\Actions\Tutor;

use App\Actions\RecordAuditLog;
use App\Enums\TutorProfileStatus;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Tutors\TutorStatusTransitions;

class SuspendTutor
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    /**
     * Suspends a previously approved tutor — `bookable()` (invariant #5)
     * already excludes any non-`approved` status, so suspending removes the
     * tutor from search/booking with no separate flag to maintain. No email
     * is sent from here (not one of CP1's named triggers); automated callers
     * (e.g. strike-count suspension) dispatch their own. `$admin` is null for
     * a system-originated suspension — `RecordAuditLog` already tolerates a
     * null actor. Suspension is expected to carry a note explaining why,
     * shown to the tutor on next login. ReinstateTutor is the way back.
     */
    public function __invoke(?User $admin, TutorProfile $profile, string $note): void
    {
        TutorStatusTransitions::transition(
            $profile,
            TutorProfileStatus::Suspended,
            'Only an approved tutor can be suspended.',
            function (TutorProfile $profile) use ($admin, $note) {
                $before = ['status' => $profile->status->value];

                $profile->forceFill([
                    'status' => TutorProfileStatus::Suspended,
                    'review_note' => $note,
                ])->save();

                ($this->recordAuditLog)($admin, 'tutor.suspended', $profile, $before, [
                    'status' => TutorProfileStatus::Suspended->value,
                    'review_note' => $note,
                ]);
            },
        );
    }
}
