<?php

namespace App\Listeners\Payments;

use App\Events\Payments\RemovedCardNotDetached;
use App\Mail\Admin\AdminRemovedCardMail;
use App\Support\Mail\AdminRecipients;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

/**
 * 25c (R268): tells admins a removed card is still attached at the gateway. Queued; never sent in the failing job.
 */
class SendAdminRemovedCardMail implements ShouldQueue
{
    public bool $afterCommit = true;

    public function handle(RemovedCardNotDetached $event): void
    {
        if (($admins = AdminRecipients::resolve()) !== []) {
            Mail::to($admins)->send(new AdminRemovedCardMail($event->paymentMethodId));
        }
    }
}
