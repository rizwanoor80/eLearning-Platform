<?php

namespace App\Actions\Tutor;

use App\Enums\TutorDocumentStatus;
use App\Enums\TutorProfileStatus;
use App\Exceptions\TutorStatusTransitionException;
use App\Models\DocumentType;
use App\Models\TutorProfile;
use App\Models\User;
use Throwable;

class RequireDocumentTypeFromApprovedTutors
{
    public function __construct(private RequestTutorChanges $requestChanges) {}

    /**
     * R36 (b): when a document type becomes required and active, every
     * APPROVED tutor who has no accepted copy of it moves to
     * `changes_requested` (not bookable, with the changes-requested email
     * naming the document); the wizard's document step then picks it up. Other
     * statuses are untouched: a draft or changes_requested tutor meets the step
     * in the wizard, a suspended tutor is re-checked at reinstatement. Running it
     * again finds nobody left to move, so it is safe to repeat.
     *
     * @return int how many tutors were moved
     */
    public function __invoke(User $admin, DocumentType $type): int
    {
        return $this->run($admin, $type)['moved'];
    }

    /**
     * Same as `__invoke`, but also says how many tutors could NOT be moved: an
     * unexpected failure on one tutor is reported to the exception handler and does
     * not stop the tutors after it, and the caller tells the admin how many were
     * left. Running the action again (a developer, or the admin toggling the
     * type's `required` flag off and on) finds exactly the tutors still left.
     *
     * @return array{moved: int, failed: int}
     */
    public function run(User $admin, DocumentType $type): array
    {
        if (! $type->required || ! $type->active) {
            return ['moved' => 0, 'failed' => 0];
        }

        $moved = 0;
        $failed = 0;

        TutorProfile::query()
            ->where('status', TutorProfileStatus::Approved)
            ->whereDoesntHave('tutorDocuments', fn ($documents) => $documents
                ->where('document_type_id', $type->id)
                ->where('status', TutorDocumentStatus::Accepted))
            ->chunkById(100, function ($profiles) use ($admin, $type, &$moved, &$failed) {
                foreach ($profiles as $profile) {
                    try {
                        ($this->requestChanges)($admin, $profile, sprintf(
                            'A new document is now required: %s. Please upload it so your profile can be reviewed again.',
                            $type->name,
                        ));
                        $moved++;
                    } catch (TutorStatusTransitionException) {
                        // Changed status since the query; not ours to move.
                    } catch (Throwable $e) {
                        report($e);
                        $failed++;
                    }
                }
            });

        return ['moved' => $moved, 'failed' => $failed];
    }
}
