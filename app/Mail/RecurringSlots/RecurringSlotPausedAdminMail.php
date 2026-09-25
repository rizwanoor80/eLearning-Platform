<?php

namespace App\Mail\RecurringSlots;

use App\Models\RecurringSlot;
use App\Support\Mail\UsesSettingsSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The admin-side notice when a weekly slot is paused because charges failed repeatedly (R102).
 * Goes to the `support_address` or the active admins (`AdminRecipients`).
 */
class RecurringSlotPausedAdminMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesSettingsSender;

    public function __construct(public RecurringSlot $slot, public int $releasedLessons) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->settingsFromAddress(),
            replyTo: array_filter([$this->settingsReplyToAddress()]),
            subject: 'A weekly slot was paused after failed charges',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.recurring-slots.paused-admin');
    }
}
