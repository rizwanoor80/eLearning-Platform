<?php

namespace App\Mail\RecurringSlots;

use App\Enums\Role;
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
 * A weekly slot was ended (R99/R102): one email per party in place of a skip email per lesson.
 * `$paidLessonsRemaining` counts the `confirmed` lessons that were not auto-cancelled — they were
 * already paid for and stay until someone cancels them under the normal rules. `$lastDay` is the
 * tutor-notice date fixed when the slot was ended, so the email still reads right if the slot is
 * ended outright before it is sent.
 */
class RecurringSlotEndedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesSettingsSender;

    public function __construct(
        public RecurringSlot $slot,
        public User $recipient,
        public Role $endedBy,
        public int $releasedLessons,
        public int $paidLessonsRemaining,
        public ?string $lastDay = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->settingsFromAddress(),
            replyTo: array_filter([$this->settingsReplyToAddress()]),
            subject: 'A weekly lesson slot has ended',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.recurring-slots.ended');
    }
}
