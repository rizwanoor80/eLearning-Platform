<?php

namespace App\Actions\Tutor;

use App\Actions\RecordAuditLog;
use App\Enums\TutorDocumentStatus;
use App\Enums\TutorProfileStatus;
use App\Exceptions\TutorStatusTransitionException;
use App\Models\TutorDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReviewTutorDocument
{
    /**
     * Document review is part of the approval decision (R31) and, since R36 (b),
     * of post-approval re-vetting: on an `approved` tutor an accepted document
     * stays accepted, and a REJECTED one moves the tutor to `changes_requested`
     * (not bookable, with an email naming the document) — so a rejected
     * required document can never leave a tutor bookable. A rejected or
     * suspended profile's documents stay closed: reinstatement re-derives
     * readiness itself.
     */
    private const REVIEWABLE = [
        TutorProfileStatus::Draft,
        TutorProfileStatus::PendingReview,
        TutorProfileStatus::ChangesRequested,
        TutorProfileStatus::Approved,
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
            throw new TutorStatusTransitionException('Documents can only be reviewed while the profile is under review or approved.');
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

        if ($status === TutorDocumentStatus::Accepted) {
            $this->deleteReplacedFiles($document);
        }

        if ($status === TutorDocumentStatus::Rejected && $document->tutorProfile->status === TutorProfileStatus::Approved) {
            app(RequestTutorChanges::class)($admin, $document->tutorProfile, sprintf(
                'Your %s was rejected. Please upload a new one so it can be reviewed again.',
                $document->documentType->name,
            ));
        }
    }

    /**
     * R36 (d): once a replacement is ACCEPTED, the files of the older,
     * soft-deleted rows for the same tutor and document type are removed from
     * the private disk (after commit, missing files ignored). The rows stay —
     * they are the record of what was uploaded and reviewed.
     */
    private function deleteReplacedFiles(TutorDocument $accepted): void
    {
        if ($accepted->trashed()) {
            return;
        }

        $paths = TutorDocument::onlyTrashed()
            ->where('tutor_profile_id', $accepted->tutor_profile_id)
            ->where('document_type_id', $accepted->document_type_id)
            ->pluck('disk_path')
            ->filter(fn ($path) => $path !== $accepted->disk_path)
            ->all();

        DB::afterCommit(function () use ($paths) {
            foreach ($paths as $path) {
                Storage::disk('local')->delete($path);
            }
        });
    }
}
