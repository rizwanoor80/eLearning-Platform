<?php

namespace App\Notifications\Lessons;

use App\Models\ProgressReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * CP7 8e (R139/PRD §8): the bell for "progress report / trial report available", to the account holder
 * only — never the learner (invariant 7). Mirrors `SendProgressReportMail`'s recipient exactly.
 */
class ProgressReportAvailableNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly ProgressReport $report) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $lesson = $this->report->lesson;

        return [
            'report_id' => $this->report->id,
            'lesson_id' => $lesson->id,
            'subject' => $lesson->subject?->name,
            'learner_display_name' => $lesson->learner->display_name,
            'is_trial' => $this->report->trial_suitability !== null,
        ];
    }
}
