<?php

namespace App\Listeners\Lessons;

use App\Enums\LessonStatus;
use App\Events\Lessons\LessonStatusChanged;
use App\Mail\Lessons\DisputeOpenedMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

/**
 * CP8 (R150): "the tutor is emailed that a dispute was opened". The frozen transition table has
 * exactly two edges into `disputed` (`completed`/`completed_reported` -> `disputed`, R150's own
 * state-machine note) — both checked here, matching `RecordLessonCancelledNotification`'s
 * from/to style, rather than trusting `to === Disputed` alone to mean only this.
 */
class SendDisputeOpenedMail implements ShouldQueue
{
    public function handle(LessonStatusChanged $event): void
    {
        if (! in_array($event->from, [LessonStatus::Completed, LessonStatus::CompletedReported], true)
            || $event->to !== LessonStatus::Disputed) {
            return;
        }

        $dispute = $event->lesson->dispute;

        if ($dispute === null) {
            return;
        }

        Mail::to($event->lesson->tutorProfile->user)->send(new DisputeOpenedMail($dispute));
    }
}
