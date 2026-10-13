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

class DisplayNameRejectedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesSettingsSender;

    public function __construct(public TutorProfile $profile, public string $name, public string $note) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->settingsFromAddress(),
            replyTo: array_filter([$this->settingsReplyToAddress()]),
            subject: 'About the display name you asked for',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.tutor.display_name_rejected');
    }
}
