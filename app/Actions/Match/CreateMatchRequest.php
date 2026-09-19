<?php

namespace App\Actions\Match;

use App\Enums\MatchRequestStatus;
use App\Exceptions\MatchRequestException;
use App\Models\Learner;
use App\Models\MatchRequest;
use App\Models\User;

/**
 * A parent asks for tutor suggestions. The learner must be theirs and not
 * deleted; curriculum and year group are copied onto the request as its own
 * snapshot, so editing the learner later never rewrites what was asked for.
 */
class CreateMatchRequest
{
    /**
     * @param  array{curriculum_id: int, subject_id: int, year_group: string, goals: string, preferred_times?: string|null, budget_tier: string}  $data
     */
    public function __invoke(User $owner, Learner $learner, array $data): MatchRequest
    {
        if ($learner->trashed() || $learner->account_user_id !== $owner->id) {
            throw new MatchRequestException('A match request needs one of your own learners.');
        }

        $request = new MatchRequest($data);
        $request->forceFill([
            'account_user_id' => $owner->id,
            'learner_id' => $learner->id,
            'status' => MatchRequestStatus::Open,
        ])->save();

        return $request;
    }
}
