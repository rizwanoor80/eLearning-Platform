<?php

namespace App\Listeners\Account;

use App\Events\Account\AccountDeletionRequested;
use App\Mail\Account\AccountDeletionRequestedMail;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class SendAccountDeletionRequestedMail implements ShouldQueue
{
    /**
     * R273: the acknowledgement goes to the requester's own address only. The user is read afresh through the
     * default scope, which leaves trashed rows out: an admin erasing the account between the request and this job
     * leaves a tombstone address, and nothing is sent to it (same guard as `SendDisplayNameRejectedMail`). `sendNow()`, not `send()`: this listener is already
     * queued, and a second queue hop would fix the address before an erasure that lands in between.
     */
    public function handle(AccountDeletionRequested $event): void
    {
        $user = User::query()->find($event->request->user_id);

        if ($user === null) {
            return;
        }

        Mail::to($user)->sendNow(new AccountDeletionRequestedMail($event->request));
    }
}
