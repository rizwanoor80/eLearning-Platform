<?php

namespace App\Listeners\Lessons;

use App\Enums\LessonStatus;
use App\Events\Lessons\LessonStatusChanged;
use App\Mail\Lessons\LessonConfirmedMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class SendLessonConfirmedMail implements ShouldQueue
{
    public function handle(LessonStatusChanged $event): void
    {
        if ($event->to !== LessonStatus::Confirmed) {
            return;
        }

        $lesson = $event->lesson;

        Mail::to($lesson->learner->account)->send(new LessonConfirmedMail($lesson, $lesson->learner->account));
        Mail::to($lesson->tutorProfile->user)->send(new LessonConfirmedMail($lesson, $lesson->tutorProfile->user));
    }
}
