<?php

namespace App\Notifications\Messaging;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * CP7 8e (R139/PRD §8): the bell for "new message", to the recipient. The sender's name is resolved
 * through `Conversation::counterpartNameFor()` — the same masking `MessageController` uses — so this
 * never carries an unmasked name before `first_lesson_completed_at` (invariant 8). Never carries the
 * message body (R134): only ids and the already-masked display name.
 */
class NewMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Conversation $conversation, private readonly User $recipient) {}

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
            'conversation_id' => $this->conversation->id,
            'sender_name' => $this->conversation->counterpartNameFor($this->recipient),
        ];
    }
}
