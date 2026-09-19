<?php

namespace App\Actions\Tutor;

use App\Actions\RecordAuditLog;
use App\Enums\TutorDocumentStatus;
use App\Enums\TutorProfileStatus;
use App\Events\Tutor\TutorChangesRequested;
use App\Exceptions\TutorStatusTransitionException;
use App\Models\TutorDocument;
use App\Models\TutorProfile;
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
     * One transaction on the profile row locked for update, so the status this
     * decides on is the current one: an admin cannot reject a required document
     * on a tutor another admin has just approved and leave them bookable, and the
     * document, its audit row and the tutor's move to `changes_requested` commit
     * together or not at all. Events go out after the commit.
     *
     * @throws TutorStatusTransitionException when the tutor's profile is no longer under review.
     */
    public function __invoke(User $admin, TutorDocument $document, TutorDocumentStatus $status): void
    {
        $profile = DB::transaction(function () use ($admin, $document, $status): ?TutorProfile {
            $profile = TutorProfile::query()->whereKey($document->tutor_profile_id)->lockForUpdate()->firstOrFail();
            // The page the admin clicked on may be stale: decide on the row as it is now
            // (refresh first — it would also reload the relation set below).
            $document->refresh();
            $document->setRelation('tutorProfile', $profile);

            if (! self::canReview($document)) {
                throw new TutorStatusTransitionException('Documents can only be reviewed while the profile is under review or approved.');
            }

            // Repeating a decision that is already recorded changes nothing (no audit
            // row, no move) — a stale page cannot re-reject a document.
            if ($document->status === $status) {
                return null;
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

            // Only a document that counts toward approval (an active, required type) can
            // take an approved tutor off the bookable list; an optional or retired type's
            // document is reviewed but leaves the tutor as they are.
            if ($status === TutorDocumentStatus::Rejected
                && $profile->status === TutorProfileStatus::Approved
                && $document->documentType->active
                && $document->documentType->required) {
                app(RequestTutorChanges::class)->apply($admin, $profile, sprintf(
                    'Your %s was rejected. Please upload a new one so it can be reviewed again.',
                    $document->documentType->name,
                ));

                return $profile;
            }

            return null;
        });

        // After commit, so a listener never sees a move that rolls back.
        if ($profile !== null) {
            TutorChangesRequested::dispatch($profile);
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
