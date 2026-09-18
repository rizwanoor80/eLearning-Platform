<?php

namespace App\Listeners\Tutor;

use App\Events\Tutor\TutorPermitExpired;
use App\Mail\Admin\AdminPermitNoticeMail;
use App\Mail\Tutor\TutorPermitExpiredMail;
use App\Support\Mail\AdminRecipients;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class SendTutorPermitExpiredMail implements ShouldQueue
{
    public function handle(TutorPermitExpired $event): void
    {
        Mail::to($event->profile->user)->send(new TutorPermitExpiredMail($event->profile));

        if (($admins = AdminRecipients::resolve()) !== []) {
            Mail::to($admins)->send(new AdminPermitNoticeMail($event->profile));
        }
    }
}
