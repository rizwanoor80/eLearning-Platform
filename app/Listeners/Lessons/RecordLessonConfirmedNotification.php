<?php

namespace App\Listeners\Lessons;

use App\Enums\LessonStatus;
use App\Events\Lessons\LessonStatusChanged;
use App\Notifications\Lessons\LessonConfirmedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * CP7 8e (R139): the "booking confirmed" bell, both parties, on every transition into `confirmed`
 * regardless of `from` — see the class docblock on `LessonConfirmedNotification` for why this does not
 * follow `SendLessonConfirmedMail`'s parent carve-out.
 */
class RecordLessonConfirmedNotification implements ShouldQueue
{
    public function handle(LessonStatusChanged $event): void
    {
        if ($event->to !== LessonStatus::Confirmed) {
            return;
        }

        $lesson = $event->lesson;

        $lesson->learner->account->notify(new LessonConfirmedNotification($lesson));
        $lesson->tutorProfile->user->notify(new LessonConfirmedNotification($lesson));
    }
}
