<?php

namespace App\Listeners\Tutor;

use App\Events\Tutor\DisplayNameRejected;
use App\Mail\Tutor\DisplayNameRejectedMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class SendDisplayNameRejectedMail implements ShouldQueue
{
    public function handle(DisplayNameRejected $event): void
    {
        Mail::to($event->profile->user)->send(new DisplayNameRejectedMail($event->profile, $event->name, $event->note));
    }
}
