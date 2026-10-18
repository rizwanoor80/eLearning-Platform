<?php

namespace App\Listeners\Tutor;

use App\Events\Tutor\DisplayNameRejected;
use App\Mail\Tutor\DisplayNameRejectedMail;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class SendDisplayNameRejectedMail implements ShouldQueue
{
    /**
     * R225(a): `RejectDisplayName` refuses a closed account, but `AnonymizeUser` can commit between the rejection
     * and this queued job, and the profile's `user()` relation includes trashed rows (and may be cached on an event
     * that was never serialised). The user is read afresh through the default scope, which leaves trashed rows out:
     * an anonymised user (soft-deleted, address tombstoned) gets nothing, and the mailable is never built. The
     * mailable is itself queued and `Mail::to()` fixes the address when this job runs, so an anonymisation committing
     * between this job and the mailable job is not caught here: that mail still goes to the address held at this
     * moment (the sibling listeners behave the same; a re-check inside the mailable job is a later change).
     */
    public function handle(DisplayNameRejected $event): void
    {
        $user = User::query()->find($event->profile->user_id);

        if ($user === null) {
            return;
        }

        Mail::to($user)->send(new DisplayNameRejectedMail($event->profile, $event->name, $event->note));
    }
}
