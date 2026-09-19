<?php

namespace App\Actions\Tutor;

use App\Actions\RecordAuditLog;
use App\Enums\TutorProfileStatus;
use App\Events\Tutor\TutorChangesRequested;
use App\Exceptions\TutorStatusTransitionException;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Tutors\TutorStatusTransitions;

class RequestTutorChanges
{
    /**
     * `approved` is here for R36 (b): an admin can pull an approved tutor back
     * to `changes_requested` (not bookable, with an email naming what is
     * needed). `suspended` is not: a suspended tutor comes back only through
     * ReinstateTutor, even though the edge exists in the transitions table.
     */
    public const FROM = [
        TutorProfileStatus::PendingReview,
        TutorProfileStatus::ChangesRequested,
        TutorProfileStatus::Approved,
    ];

    public function __construct(private RecordAuditLog $recordAuditLog) {}

    public function __invoke(User $admin, TutorProfile $profile, string $note): void
    {
        $this->apply($admin, $profile, $note);

        TutorChangesRequested::dispatch($profile);
    }

    /**
     * The transition without the event, for a caller that owns a wider transaction
     * and dispatches after its own commit (ReviewTutorDocument).
     */
    public function apply(User $admin, TutorProfile $profile, string $note): void
    {
        TutorStatusTransitions::transition(
            $profile,
            TutorProfileStatus::ChangesRequested,
            'Changes can only be requested on a submitted or approved profile.',
            function (TutorProfile $profile) use ($admin, $note) {
                if (! in_array($profile->status, self::FROM, true)) {
                    throw new TutorStatusTransitionException('Changes can only be requested on a submitted or approved profile.');
                }

                $before = ['status' => $profile->status->value];

                $profile->forceFill([
                    'status' => TutorProfileStatus::ChangesRequested,
                    'review_note' => $note,
                ])->save();

                ($this->recordAuditLog)($admin, 'tutor.changes_requested', $profile, $before, [
                    'status' => TutorProfileStatus::ChangesRequested->value,
                    'review_note' => $note,
                ]);
            },
        );
    }
}
