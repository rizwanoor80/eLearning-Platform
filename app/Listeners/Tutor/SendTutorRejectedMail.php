<?php

namespace App\Listeners\Tutor;

use App\Events\Tutor\TutorRejected;
use App\Mail\Tutor\TutorRejectedMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class SendTutorRejectedMail implements ShouldQueue
{
    public function handle(TutorRejected $event): void
    {
        Mail::to($event->profile->user)->send(new TutorRejectedMail($event->profile));
    }
}
