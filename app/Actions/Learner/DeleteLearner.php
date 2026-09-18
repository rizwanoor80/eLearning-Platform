<?php

namespace App\Actions\Learner;

use App\Exceptions\LearnerDeletionException;
use App\Models\Learner;

/**
 * Soft delete. The adult student's own learner can never be removed. Guards for
 * a learner with live lessons or an active weekly slot arrive with CP3/CP4,
 * when those tables gain `learner_id` — until then there is nothing to guard.
 */
class DeleteLearner
{
    public function __invoke(Learner $learner): void
    {
        if ($learner->isSelf()) {
            throw new LearnerDeletionException('An adult student cannot remove their own learner profile.');
        }

        $learner->delete();
    }
}
