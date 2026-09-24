<?php

namespace App\Listeners\Tutor;

use App\Events\Tutor\TutorSuspendedForStrikes;
use App\Mail\Admin\AdminTutorSuspendedMail;
use App\Support\Mail\AdminRecipients;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class SendTutorSuspendedForStrikesMail implements ShouldQueue
{
    public function handle(TutorSuspendedForStrikes $event): void
    {
        if (($admins = AdminRecipients::resolve()) !== []) {
            Mail::to($admins)->send(new AdminTutorSuspendedMail($event->profile, $event->strikeCount));
        }
    }
}
