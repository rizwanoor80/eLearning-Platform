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
     * @param  array{display_name?: string, year_group?: string|null, curriculum_id?: int|null, school?: string|null, notes?: string|null}  $data
     */
    public function __invoke(Learner $learner, array $data): Learner
    {
        if ($learner->isSelf()) {
            unset($data['display_name']);
        }

        $learner->fill($data)->save();

        return $learner;
    }
}
