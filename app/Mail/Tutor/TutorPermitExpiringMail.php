<?php

namespace App\Mail\Tutor;

use App\Models\TutorProfile;
use App\Support\Mail\UsesSettingsSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TutorPermitExpiringMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesSettingsSender;

    public function __construct(public TutorProfile $profile, public int $daysRemaining) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->settingsFromAddress(),
            replyTo: array_filter([$this->settingsReplyToAddress()]),
            subject: "Your tutoring permit expires in {$this->daysRemaining} days",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.tutor.permit_expiring');
    }
}
