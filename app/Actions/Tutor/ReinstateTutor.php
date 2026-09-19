<?php

namespace App\Actions\Tutor;

use App\Actions\RecordAuditLog;
use App\Enums\TutorProfileStatus;
use App\Events\Tutor\TutorChangesRequested;
use App\Exceptions\TutorStatusTransitionException;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Tutors\TutorApprovalReadiness;
use App\Services\Tutors\TutorStatusTransitions;

class ReinstateTutor
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    /**
     * R36 (a): the way back from `suspended`. If the permit is valid, every
     * required document is accepted and the rate is inside today's band — the
     * same readiness as approval — the tutor is `approved` again (bookable, no
     * email, as suspension sent none). If not, they go to `changes_requested`
     * with the reasons as the `review_note`, and the changes-requested email
     * tells them. The outcome is audited either way. Returns the new status.
     *
     * @throws TutorStatusTransitionException when the tutor is not suspended.
     */
    public function __invoke(User $admin, TutorProfile $profile): TutorProfileStatus
    {
        $outcome = TutorStatusTransitions::transition(
            $profile,
            TutorProfileStatus::Approved,
            'Only a suspended tutor can be reinstated.',
            function (TutorProfile $profile) use ($admin) {
                if ($profile->status !== TutorProfileStatus::Suspended) {
                    throw new TutorStatusTransitionException('Only a suspended tutor can be reinstated.');
                }

                $problems = app(TutorApprovalReadiness::class)->problems($profile);
                $to = $problems === [] ? TutorProfileStatus::Approved : TutorProfileStatus::ChangesRequested;
                TutorStatusTransitions::assert($profile->status, $to);

                $before = ['status' => $profile->status->value];
                $note = $problems === [] ? null : 'Reinstatement needs: '.implode('; ', $problems).'.';

                // approved_by / approved_at are kept: history, overwritten by the next approval.
                $profile->forceFill(['status' => $to, 'review_note' => $note])->save();

                ($this->recordAuditLog)($admin, 'tutor.reinstated', $profile, $before, [
                    'status' => $to->value,
                    'review_note' => $note,
                ]);

                return $to;
            },
        );

        if ($outcome === TutorProfileStatus::ChangesRequested) {
            TutorChangesRequested::dispatch($profile);
        }

        return $outcome;
    }
}
