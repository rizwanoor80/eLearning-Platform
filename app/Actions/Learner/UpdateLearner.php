<?php

namespace App\Actions\Learner;

use App\Models\Learner;

/**
 * The self-learner's name is the account name (kept in step by
 * SyncSelfLearnerName), so it is not editable here; `is_minor` never is.
 */
class UpdateLearner
{
    /**
     * @param  array{display_name?: string, year_group_id?: int|null, curriculum_id?: int|null, school?: string|null, notes?: string|null}  $data
     */
    public function __invoke(Learner $learner, array $data): Learner
    {
        if ($learner->isSelf()) {
            unset($data['display_name']);
        }

        $learner->fill($data);

        // Choosing a year group settles a legacy row: the original free text
        // (kept only for the R33 report) is no longer needed.
        if ($learner->year_group_id !== null) {
            $learner->year_group_legacy = null;
        }

        $learner->save();

        return $learner;
    }
}
