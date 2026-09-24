<?php

namespace App\Listeners\Lessons;

use App\Enums\LessonStatus;
use App\Events\Lessons\LessonStatusChanged;
use App\Mail\Lessons\LessonCancelledMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

/**
 * A `confirmed` lesson moving to `cancelled_by_parent`/`cancelled_by_tutor`
 * is a paid cancellation (CancelLesson). The free, unpaid case from
 * `reserved` is a skip — see `SendLessonSkippedMail`, which fires instead.
 */
class SendLessonCancelledMail implements ShouldQueue
{
    public function handle(LessonStatusChanged $event): void
    {
        if ($event->from !== LessonStatus::Confirmed) {
            return;
        }

        if (! in_array($event->to, [LessonStatus::CancelledByParent, LessonStatus::CancelledByTutor], true)) {
            return;
        }

        $lesson = $event->lesson;

        Mail::to($lesson->learner->account)->send(new LessonCancelledMail($lesson, $lesson->learner->account));
        Mail::to($lesson->tutorProfile->user)->send(new LessonCancelledMail($lesson, $lesson->tutorProfile->user));
    }
}
