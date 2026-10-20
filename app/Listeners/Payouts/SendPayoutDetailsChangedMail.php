<?php

namespace App\Listeners\Payouts;

use App\Events\Payouts\PayoutDetailsChanged;
use App\Mail\Payouts\PayoutDetailsChangedMail;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class SendPayoutDetailsChangedMail implements ShouldQueue
{
    /**
     * The user is read afresh through the default scope, which leaves trashed rows out: an anonymised tutor
     * (soft-deleted, address tombstoned) gets nothing (same reasoning as `SendDisplayNameRejectedMail`).
     */
    public function handle(PayoutDetailsChanged $event): void
    {
        $user = User::query()->find($event->profile->user_id);

        if ($user === null) {
            return;
        }

        Mail::to($user)->send(new PayoutDetailsChangedMail($event->profile, $event->methodLabel));
    }
}
