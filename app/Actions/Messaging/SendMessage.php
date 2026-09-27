<?php

namespace App\Actions\Messaging;

use App\Exceptions\ConversationClosedException;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\Messaging\MaskedMessage;
use App\Support\Messaging\MessageMasker;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * R133/R134: posts one message. Before the pair's first completed lesson the body is masked HERE,
 * before the row is written, so the original exists nowhere — not in the row, a log, a job payload or
 * an event (this action dispatches none). The caller's authorisation is the policy; the party check is
 * repeated so the action is safe on its own.
 *
 * @throws AuthorizationException when the sender is not a party to the conversation
 * @throws ConversationClosedException when either side is suspended
 */
class SendMessage
{
    public function __construct(private readonly MessageMasker $masker) {}

    public function __invoke(User $sender, Conversation $conversation, string $body): Message
    {
        if (! $conversation->hasParty($sender)) {
            throw new AuthorizationException;
        }

        if ($conversation->isClosed()) {
            throw new ConversationClosedException;
        }

        // Read fresh: the date can only go from null to set, so a stale read errs towards masking.
        $conversation->refresh();

        $stored = $conversation->contactIsVisible()
            ? new MaskedMessage($body, false)
            : $this->masker->mask($body);

        return DB::transaction(function () use ($sender, $conversation, $stored): Message {
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender_user_id' => $sender->id,
                'body' => $stored->text,
                'body_masked' => $stored->masked,
            ]);

            $conversation->forceFill(['last_message_at' => $message->created_at])->save();

            return $message;
        });
    }
}
