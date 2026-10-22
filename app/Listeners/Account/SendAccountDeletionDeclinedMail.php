<?php

namespace App\Listeners\Account;

use App\Events\Account\AccountDeletionDeclined;
use App\Mail\Account\AccountDeletionDeclinedMail;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class SendAccountDeletionDeclinedMail implements ShouldQueue
{
    /** R273: the decline note goes to the account's own address; an erased (trashed) user gets nothing. */
    /**
     * `sendNow()`, not `send()`: this listener is already queued, and a second queue hop would fix the address before an
     * erasure that lands in between. The mailable reads the request (and any decline note) when it renders.
     */
    public function handle(AccountDeletionDeclined $event): void
    {
        $user = User::query()->find($event->request->user_id);

        if ($user === null) {
            return;
        }

        Mail::to($user)->sendNow(new AccountDeletionDeclinedMail($event->request));
    }
}
