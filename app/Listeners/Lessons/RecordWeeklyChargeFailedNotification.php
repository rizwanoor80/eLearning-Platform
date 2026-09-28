<?php

namespace App\Listeners\Lessons;

use App\Events\Lessons\LessonChargeFailed;
use App\Notifications\Lessons\WeeklyChargeFailedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * CP7 8e (R139): the "auto-charge failed" bell, to the account holder only. Mirrors
 * `SendWeeklyChargeMails::handleChargeFailed` exactly.
 */
class RecordWeeklyChargeFailedNotification implements ShouldQueue
{
    public function handle(LessonChargeFailed $event): void
    {
        $parent = $event->lesson->learner->account;

        $parent->notify(new WeeklyChargeFailedNotification($event->lesson, $event->retryAt));
    }
}
