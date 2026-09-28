<?php

namespace App\Notifications\Lessons;

use App\Models\Lesson;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * CP7 8e (R139/PRD §8): the bell for "booking confirmed", to both parties. Fires on every transition
 * into `confirmed`, regardless of `from` — deliberately not the `SendLessonConfirmedMail` carve-out that
 * skips the parent's *email* when the confirmation came from the weekly auto-charge (they get
 * `WeeklyLessonChargedMail` instead, R102): the carve-out avoids two overlapping emails, not a distinct
 * piece of in-app information (logged as a DECISION, cycle 08).
 */
class LessonConfirmedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Lesson $lesson) {}

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
        return [
            'lesson_id' => $this->lesson->id,
            'subject' => $this->lesson->subject?->name,
            'learner_display_name' => $this->lesson->learner->display_name,
            'starts_at' => $this->lesson->starts_at->copy()->utc()->toIso8601String(),
        ];
    }
}
