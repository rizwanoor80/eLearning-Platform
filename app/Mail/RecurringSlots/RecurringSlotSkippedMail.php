<?php

namespace App\Mail\RecurringSlots;

use App\Models\RecurringSlot;
use App\Models\RecurringSlotSkip;
use App\Models\User;
use App\Support\Mail\UsesSettingsSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One email listing every occurrence of a weekly slot that was not scheduled (R98). Nothing was
 * reserved and nothing is charged for those dates.
 */
class RecurringSlotSkippedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesSettingsSender;

    /**
     * @param  Collection<int, RecurringSlotSkip>  $skips
     */
    public function __construct(public RecurringSlot $slot, public User $recipient, public Collection $skips) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->settingsFromAddress(),
            replyTo: array_filter([$this->settingsReplyToAddress()]),
            subject: $this->skips->count() === 1 ? 'A weekly lesson was not scheduled' : 'Some weekly lessons were not scheduled',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.recurring-slots.skipped');
    }
}
