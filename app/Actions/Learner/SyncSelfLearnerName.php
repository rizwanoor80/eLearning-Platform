<?php

namespace App\Actions\Learner;

use App\Models\Learner;
use App\Models\User;

/**
 * The self-learner's display name is the account name (DATA_MODEL), so it is
 * re-derived whenever the user renames themselves.
 */
class SyncSelfLearnerName
{
    public function __invoke(User $user): void
    {
        Learner::query()
            ->where('account_user_id', $user->id)
            ->where('is_minor', false)
            ->update(['display_name' => $user->name]);
    }
}
