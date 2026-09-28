<?php

namespace App\Listeners\Messaging;

use App\Events\Messaging\MessageSent;
use App\Mail\Messaging\NewMessageMail;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * CP7 8e (R139): "new message" email, to the recipient — sender's display name and a link only, never the
 * message text (R134/invariant 8: the name comes from `Conversation::counterpartNameFor()`, the same
 * masking `MessageController` uses). At most one per conversation per recipient per 30 minutes: gated by
 * an atomic `Cache::add()` (never a get-then-put pair, which would race two queued jobs for the same
 * conversation) — the first message in a window sends the email, later ones in the same window are
 * silently skipped, not queued for later.
 */
class SendNewMessageMail implements ShouldQueue
{
    public function handle(MessageSent $event): void
    {
        $conversation = Conversation::query()->findOrFail($event->conversationId);
        $sender = User::query()->findOrFail($event->senderUserId);
        $recipient = $conversation->otherPartyOf($sender);

        $throttleKey = "new-message-mail:{$conversation->id}:{$recipient->id}";

        if (! Cache::add($throttleKey, true, now()->addMinutes(30))) {
            return;
        }

        $senderName = $conversation->counterpartNameFor($recipient);

        Mail::to($recipient)->send(new NewMessageMail($conversation, $recipient, $senderName));
    }
}
