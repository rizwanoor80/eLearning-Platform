<?php

namespace App\Notifications\Lessons;

use App\Models\Lesson;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * CP7 8e (R139/PRD §8): the bell for "booking cancelled", to both parties. No reason is carried in the
 * data: `SendLessonListener`'s combined gate (see `RecordLessonCancelledNotification`) only reaches this
 * class for cancellations that already have a neutral, sayable story (a paid cancellation, a free skip,
 * "tutor unavailable", or a failed weekly charge) — a suspension-driven cancellation never reaches here
 * at all (invariant 11 / R138: that story is never said to either party), so there is nothing to redact.
 */
class LessonCancelledNotification extends Notification implements ShouldQueue
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
