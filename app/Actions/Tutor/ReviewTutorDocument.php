<?php

namespace App\Actions\Tutor;

use App\Actions\RecordAuditLog;
use App\Enums\TutorDocumentStatus;
use App\Enums\TutorProfileStatus;
use App\Exceptions\TutorStatusTransitionException;
use App\Models\TutorDocument;
use App\Models\User;

class ReviewTutorDocument
{
    /**
     * Document review is part of the approval decision and closes with it
     * (R31): once a profile is approved, rejected or suspended, accepting or
     * rejecting one of its documents would change the evidence behind that
     * decision without anything re-deriving the tutor's status (a rejected
     * required document would leave an approved tutor bookable and unable to
     * re-upload). Post-approval re-vetting is not built — a CP1 follow-up.
     */
    private const REVIEWABLE = [
        TutorProfileStatus::Draft,
        TutorProfileStatus::PendingReview,
        TutorProfileStatus::ChangesRequested,
    ];

    public function __construct(private RecordAuditLog $recordAuditLog) {}

    public static function canReview(TutorDocument $document): bool
    {
        return in_array($document->tutorProfile->status, self::REVIEWABLE, true);
    }

    /**
     * @throws TutorStatusTransitionException when the tutor's profile is no longer under review.
     */
    public function __invoke(User $admin, TutorDocument $document, TutorDocumentStatus $status): void
    {
        if (! self::canReview($document)) {
            throw new TutorStatusTransitionException('Documents can only be reviewed while the profile is under review.');
        }

        $before = ['status' => $document->status->value];

        $document->forceFill([
            'status' => $status,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ])->save();

        ($this->recordAuditLog)($admin, 'tutor_document.'.$status->value, $document, $before, [
            'status' => $status->value,
        ]);
    }
}
