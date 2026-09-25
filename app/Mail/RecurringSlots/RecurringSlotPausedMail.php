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
 * A weekly slot was paused (R99/R102): one email in place of a skip email per released lesson.
 * A paused slot keeps its day and time; no new lessons are scheduled until it is resumed.
 */
class RecurringSlotPausedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesSettingsSender;

    public function __construct(public RecurringSlot $slot, public User $recipient, public int $releasedLessons) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->settingsFromAddress(),
            replyTo: array_filter([$this->settingsReplyToAddress()]),
            subject: 'A weekly lesson slot was paused',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.recurring-slots.paused');
    }
}
