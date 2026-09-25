<?php

namespace App\Listeners\Lessons;

use App\Enums\LessonCancelReason;
use App\Enums\LessonStatus;
use App\Events\Lessons\LessonStatusChanged;
use App\Mail\Lessons\LessonSkippedMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

/**
 * A `reserved` (unpaid) lesson moving to `cancelled_by_parent`/
 * `cancelled_by_tutor` is a free skip (SkipLesson). The paid case from
 * `confirmed` is a cancellation — see `SendLessonCancelledMail`, which fires
 * instead.
 */
class SendLessonSkippedMail implements ShouldQueue
{
    public function handle(LessonStatusChanged $event): void
    {
        if ($event->from !== LessonStatus::Reserved) {
            return;
        }

        if (! in_array($event->to, [LessonStatus::CancelledByParent, LessonStatus::CancelledByTutor], true)) {
            return;
        }

        $lesson = $event->lesson;

        // A slot pause or end cancels a run of lessons at once and sends one slot email instead
        // (`SendSlotPausedMail` / `SendSlotEndedMail`), not one per lesson.
        if (LessonCancelReason::isReserved($lesson->cancel_reason)) {
            return;
        }

        Mail::to($lesson->learner->account)->send(new LessonSkippedMail($lesson, $lesson->learner->account));
        Mail::to($lesson->tutorProfile->user)->send(new LessonSkippedMail($lesson, $lesson->tutorProfile->user));
    }
}
