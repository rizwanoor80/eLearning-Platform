<?php

namespace App\Mail\Admin;

use App\Support\Mail\UsesSettingsSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 25c (R268): a parent removed a saved card but the gateway would not forget it. Names the payment-method id
 * only — no parent, no card detail — because `AdminRecipients::resolve()` can be an external support inbox.
 */
class AdminRemovedCardMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesSettingsSender;

    public function __construct(public int $paymentMethodId) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->settingsFromAddress(),
            subject: 'A removed card is still attached at the payment gateway',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.admin.removed_card_not_detached');
    }
}
