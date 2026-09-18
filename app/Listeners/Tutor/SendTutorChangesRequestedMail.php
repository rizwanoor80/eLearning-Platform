<?php

namespace App\Listeners\Tutor;

use App\Events\Tutor\TutorChangesRequested;
use App\Mail\Tutor\TutorChangesRequestedMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class SendTutorChangesRequestedMail implements ShouldQueue
{
    public function handle(TutorChangesRequested $event): void
    {
        Mail::to($event->profile->user)->send(new TutorChangesRequestedMail($event->profile));
    }
}
