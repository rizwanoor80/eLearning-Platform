<?php

namespace App\Listeners\Tutor;

use App\Events\Tutor\TutorApproved;
use App\Mail\Tutor\TutorApprovedMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class SendTutorApprovedMail implements ShouldQueue
{
    public function handle(TutorApproved $event): void
    {
        Mail::to($event->profile->user)->send(new TutorApprovedMail($event->profile));
    }
}
