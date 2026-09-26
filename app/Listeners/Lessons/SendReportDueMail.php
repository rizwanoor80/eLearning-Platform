<?php

namespace App\Listeners\Lessons;

use App\Enums\LessonStatus;
use App\Events\Lessons\LessonStatusChanged;
use App\Mail\Lessons\ReportDueMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

/**
 * The tutor's prompt, once, when the lesson reaches `completed` (`completed` is entered only from `in_progress`).
 */
class SendReportDueMail implements ShouldQueue
{
    public function handle(LessonStatusChanged $event): void
    {
        if ($event->to !== LessonStatus::Completed) {
            return;
        }

        $lesson = $event->lesson;
        $tutor = $lesson->tutorProfile->user;

        Mail::to($tutor)->send(new ReportDueMail($lesson, $tutor));
    }
}
