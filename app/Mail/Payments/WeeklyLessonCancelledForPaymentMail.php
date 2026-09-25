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
 * A weekly lesson was cancelled because it could not be charged (R102: parent and tutor). The
 * wording differs when the cause was the platform's timing (`charge_window_missed`) rather than a
 * failed card.
 */
class WeeklyLessonCancelledForPaymentMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesSettingsSender;

    public function __construct(public Lesson $lesson, public User $recipient) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->settingsFromAddress(),
            replyTo: array_filter([$this->settingsReplyToAddress()]),
            subject: 'A weekly lesson was cancelled',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.payments.cancelled-for-payment');
    }
}
