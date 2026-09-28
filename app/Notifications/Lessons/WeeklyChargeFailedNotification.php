<?php

namespace App\Notifications\Lessons;

use App\Models\Lesson;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * CP7 8e (R139/PRD §8): the bell for "auto-charge failed (update card)", to the account holder only.
 * Mirrors `SendWeeklyChargeMails::handleChargeFailed` exactly.
 */
class WeeklyChargeFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Lesson $lesson, private readonly CarbonImmutable $retryAt) {}

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
            'retry_at' => $this->retryAt->copy()->utc()->toIso8601String(),
        ];
    }
}
