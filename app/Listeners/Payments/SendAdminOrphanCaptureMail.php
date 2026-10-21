<?php

namespace App\Listeners\Payments;

use App\Events\Payments\OrphanCaptureDetected;
use App\Mail\Admin\AdminOrphanCaptureMail;
use App\Support\Mail\AdminRecipients;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

/** R259 (4): queued; the `AdminRecipients::resolve()` pattern. */
class SendAdminOrphanCaptureMail implements ShouldQueue
{
    public function handle(OrphanCaptureDetected $event): void
    {
        if (($admins = AdminRecipients::resolve()) !== []) {
            Mail::to($admins)->send(new AdminOrphanCaptureMail($event->gateway, $event->intentId, $event->amount, $event->currency));
        }
    }
}
