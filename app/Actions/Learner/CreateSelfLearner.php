<?php

namespace App\Actions\Learner;

use App\Models\Learner;
use App\Models\User;

/**
 * An adult student is their own learner: one `is_minor = false` row whose
 * display name is the account name. Curriculum and year group are filled in
 * later on the learner page.
 */
class CreateSelfLearner
{
    public function __invoke(User $user): Learner
    {
        $learner = new Learner(['display_name' => $user->name]);
        $learner->forceFill(['account_user_id' => $user->id, 'is_minor' => false])->save();

        return $learner;
    }
}
