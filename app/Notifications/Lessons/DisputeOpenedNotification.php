<?php

namespace App\Notifications\Lessons;

use App\Models\Dispute;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * CP8 (R150/PRD §8, R139): the tutor's bell for "a dispute was opened on your lesson". Carries no
 * part of the account holder's free-text `description` — invariant-9-adjacent, matching
 * `ResolveDispute`'s own audit-log exclusion of the same field.
 */
class DisputeOpenedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Dispute $dispute) {}

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
        $lesson = $this->dispute->lesson;

        return [
            'lesson_id' => $lesson->id,
            'subject' => $lesson->subject?->name,
            'learner_display_name' => $lesson->learner->display_name,
            'starts_at' => $lesson->starts_at->copy()->utc()->toIso8601String(),
            'reason' => $this->dispute->reason->value,
        ];
    }
}
