<?php

namespace App\Listeners\Tutor;

use App\Events\Tutor\TutorPermitExpiring;
use App\Mail\Tutor\TutorPermitExpiringMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class SendTutorPermitExpiringMail implements ShouldQueue
{
    public function handle(TutorPermitExpiring $event): void
    {
        Mail::to($event->profile->user)->send(new TutorPermitExpiringMail($event->profile, $event->daysRemaining));
    }
}
