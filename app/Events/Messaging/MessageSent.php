<?php

namespace App\Events\Messaging;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * CP7 8e (R139/R134): fired by `SendMessage`, after its transaction commits, so the new-message DB
 * notification and the throttled email can fire off the queue. Carries **ids only** — never the message
 * body, masked or not — so the text this event is about to trigger notifications for never itself enters
 * a job payload or a log. Listeners re-fetch the `Message`/`Conversation` rows themselves.
 */
class MessageSent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $messageId,
        public readonly int $conversationId,
        public readonly int $senderUserId,
    ) {}
}
