<?php

namespace App\Listeners\Tutor;

use App\Events\Tutor\TutorPermitExpiring;
use App\Mail\Admin\AdminPermitNoticeMail;
use App\Mail\Tutor\TutorPermitExpiringMail;
use App\Support\Mail\AdminRecipients;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class SendTutorPermitExpiringMail implements ShouldQueue
{
    public function handle(TutorPermitExpiring $event): void
    {
        Mail::to($event->profile->user)->send(new TutorPermitExpiringMail($event->profile, $event->daysRemaining));

        if (($admins = AdminRecipients::resolve()) !== []) {
            Mail::to($admins)->send(new AdminPermitNoticeMail($event->profile, $event->daysRemaining));
        }
    }
}
