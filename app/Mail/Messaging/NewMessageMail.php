<?php

namespace App\Mail\Messaging;

use App\Models\Conversation;
use App\Models\User;
use App\Support\Mail\UsesSettingsSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * CP7 8e (R139): "you have a new message" — the sender's display name (already masked by
 * `Conversation::counterpartNameFor()` before this mailable is built, R134/invariant 8) and a link to the
 * thread. Never the message text itself. At most one of these per conversation per recipient per 30
 * minutes (`RecordNewMessageNotification`'s sibling listener, `SendNewMessageMail`, enforces the throttle
 * before this is even queued).
 */
class NewMessageMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesSettingsSender;

    public function __construct(public Conversation $conversation, public User $recipient, public string $senderName) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->settingsFromAddress(),
            replyTo: array_filter([$this->settingsReplyToAddress()]),
            subject: 'New message from '.$this->senderName,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.messaging.new_message');
    }
}
