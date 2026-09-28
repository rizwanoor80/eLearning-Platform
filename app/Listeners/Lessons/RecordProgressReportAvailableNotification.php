<?php

namespace App\Listeners\Lessons;

use App\Events\Lessons\ProgressReportSubmitted;
use App\Notifications\Lessons\ProgressReportAvailableNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\DatabaseNotification;

/**
 * CP7 8e (R139): the "report available" bell, to the account holder only — mirrors
 * `SendProgressReportMail`'s recipient exactly. The database channel has no built-in dedup (unlike
 * `SendProgressReportMail`'s `emailed_at`-claim pattern, which only protects the email), so a queue retry
 * is guarded here with an existence check against this report's id before creating a second row.
 */
class RecordProgressReportAvailableNotification implements ShouldQueue
{
    public function handle(ProgressReportSubmitted $event): void
    {
        $parent = $event->report->lesson->learner->account;

        $alreadyRecorded = DatabaseNotification::query()
            ->where('notifiable_type', $parent->getMorphClass())
            ->where('notifiable_id', $parent->getKey())
            ->where('type', ProgressReportAvailableNotification::class)
            // The migration's `data` column is `text` (Laravel's own default notifications stub), not
            // `json`/`jsonb` — Postgres's `->`/`->>` operators don't exist on `text` at all ("operator does
            // not exist: text -> unknown", SQLSTATE 42883), so Eloquent's `where('data->report_id', ...)`
            // json-path sugar can't be used here. Cast explicitly instead: `::jsonb` first, then `->>` to
            // extract as text, compared against a string binding throughout.
            ->whereRaw("data::jsonb ->> 'report_id' = ?", [(string) $event->report->id])
            ->exists();

        if ($alreadyRecorded) {
            return;
        }

        $parent->notify(new ProgressReportAvailableNotification($event->report));
    }
}
