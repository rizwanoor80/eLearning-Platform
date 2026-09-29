<?php

namespace App\Mail\Lessons;

use App\Models\Dispute;
use App\Support\Mail\UsesSettingsSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * CP8 (R150/PRD §8): tells the lesson's own tutor a dispute was opened. Never quotes the account
 * holder's free-text `description` — R150 states this explicitly ("no parent text quoted").
 */
class DisputeOpenedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesSettingsSender;

    public function __construct(public Dispute $dispute) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->settingsFromAddress(),
            replyTo: array_filter([$this->settingsReplyToAddress()]),
            subject: 'A dispute has been opened on one of your lessons',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.lessons.dispute_opened');
    }
}
