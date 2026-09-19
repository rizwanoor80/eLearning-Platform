<?php

namespace App\Actions\Tutor;

use App\Actions\RecordAuditLog;
use App\Enums\TutorProfileStatus;
use App\Events\Tutor\TutorSubmittedForReview;
use App\Models\TutorProfile;
use App\Services\Tutors\TutorStatusTransitions;

class CompleteTutorOnboarding
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    /**
     * Submits a draft (or `changes_requested`) profile for admin review.
     * Callers must have already confirmed the wizard's derived step is
     * `complete` — this action does not re-derive it, so it can be
     * unit-tested in isolation. The admin's previous `review_note` is cleared
     * (R36 c: it answered the last submission, not this one) and
     * `submitted_at` is stamped, which is what the approval queue sorts on.
     */
    public function __invoke(TutorProfile $profile): void
    {
        TutorStatusTransitions::transition(
            $profile,
            TutorProfileStatus::PendingReview,
            'Only a draft or changes-requested profile can be submitted.',
            function (TutorProfile $profile) {
                $before = ['status' => $profile->status->value, 'review_note' => $profile->review_note];

                $profile->forceFill([
                    'status' => TutorProfileStatus::PendingReview,
                    'review_note' => null,
                    'submitted_at' => now(),
                ])->save();

                ($this->recordAuditLog)($profile->user, 'tutor.submitted', $profile, $before, [
                    'status' => TutorProfileStatus::PendingReview->value,
                    'review_note' => null,
                ]);
            },
        );

        TutorSubmittedForReview::dispatch($profile);
    }
}
