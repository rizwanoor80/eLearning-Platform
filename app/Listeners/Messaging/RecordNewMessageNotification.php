<?php

namespace App\Listeners\Messaging;

use App\Events\Messaging\MessageSent;
use App\Models\Conversation;
use App\Models\User;
use App\Notifications\Messaging\NewMessageNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * CP7 8e (R139): the "new message" bell, to the recipient (the conversation party who did not send it).
 * One row per message, not deduplicated to one per conversation: R139's text throttles only the *email*
 * ("at most one per conversation per recipient per 30 minutes") and says nothing about the bell itself,
 * so this is the plain per-event reading of PRD §8's "New message | Recipient" row (logged as a DECISION,
 * cycle 08 r6).
 */
class RecordNewMessageNotification implements ShouldQueue
{
    public function handle(MessageSent $event): void
    {
        $conversation = Conversation::query()->findOrFail($event->conversationId);
        $sender = User::query()->findOrFail($event->senderUserId);
        $recipient = $conversation->otherPartyOf($sender);

        $recipient->notify(new NewMessageNotification($conversation, $recipient));
    }
}
