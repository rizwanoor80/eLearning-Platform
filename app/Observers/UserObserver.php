<?php

namespace App\Observers;

use App\Actions\Learner\SyncSelfLearnerName;
use App\Models\User;

/**
 * An adult student's learner row carries the account name (DATA_MODEL), so it
 * follows the account whenever the name changes — from the profile page or any
 * other model save. (A bulk `User::query()->update()` bypasses model events by
 * design; nothing in the app renames users that way.)
 */
class UserObserver
{
    public function updated(User $user): void
    {
        if ($user->wasChanged('name')) {
            app(SyncSelfLearnerName::class)($user);
        }
    }
}
