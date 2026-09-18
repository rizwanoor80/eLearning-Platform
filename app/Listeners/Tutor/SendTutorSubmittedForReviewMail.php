<?php

namespace App\Listeners\Tutor;

use App\Events\Tutor\TutorSubmittedForReview;
use App\Mail\Tutor\TutorSubmittedForReviewMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class SendTutorSubmittedForReviewMail implements ShouldQueue
{
    public function handle(TutorSubmittedForReview $event): void
    {
        Mail::to($event->profile->user)->send(new TutorSubmittedForReviewMail($event->profile));
    }
}
