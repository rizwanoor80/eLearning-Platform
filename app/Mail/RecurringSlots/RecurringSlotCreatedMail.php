<?php

namespace App\Mail\RecurringSlots;

use App\Models\RecurringSlot;
use App\Models\User;
use App\Support\Mail\UsesSettingsSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A weekly slot was set up (R102), to the parent and to the tutor. The lessons themselves appear
 * week by week as the daily generator reaches them; nothing is charged until 4e's charge job.
 */
class RecurringSlotCreatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesSettingsSender;

    public function __construct(public RecurringSlot $slot, public User $recipient) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->settingsFromAddress(),
            replyTo: array_filter([$this->settingsReplyToAddress()]),
            subject: 'A weekly lesson slot was set up',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.recurring-slots.created');
    }
}
