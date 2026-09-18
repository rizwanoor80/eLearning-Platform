<?php

namespace App\Actions\Learner;

use App\Models\Learner;
use App\Models\User;

/**
 * A parent adding a child. Always a minor: the only adult learner is the
 * self-learner made at registration (CreateSelfLearner), so `is_minor` is never
 * read from input.
 */
class CreateLearner
{
    /**
     * @param  array{display_name: string, year_group: string, curriculum_id: int, school?: string|null, notes?: string|null}  $data
     */
    public function __invoke(User $owner, array $data): Learner
    {
        $learner = new Learner($data);
        $learner->forceFill(['account_user_id' => $owner->id, 'is_minor' => true])->save();

        return $learner;
    }
}
