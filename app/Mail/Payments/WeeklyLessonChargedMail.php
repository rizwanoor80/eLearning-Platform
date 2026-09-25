<?php

namespace App\Mail\Payments;

use App\Models\Lesson;
use App\Models\User;
use App\Support\Mail\UsesSettingsSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A weekly lesson was charged to the saved card and is now confirmed (R102: parent only; it
 * replaces the generic confirmed email for a weekly lesson).
 */
class WeeklyLessonChargedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesSettingsSender;

    public function __construct(public Lesson $lesson, public User $recipient) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->settingsFromAddress(),
            replyTo: array_filter([$this->settingsReplyToAddress()]),
            subject: 'Your weekly lesson is paid and confirmed',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.payments.charged');
    }
}
