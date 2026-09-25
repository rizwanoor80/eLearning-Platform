<?php

namespace App\Mail\Payments;

use App\Models\Lesson;
use App\Models\User;
use App\Support\Mail\UsesSettingsSender;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A weekly lesson's charge failed and the platform will try again at `$retryAt` (R102: parent only).
 */
class WeeklyLessonChargeFailedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesSettingsSender;

    public function __construct(public Lesson $lesson, public User $recipient, public CarbonImmutable $retryAt) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->settingsFromAddress(),
            replyTo: array_filter([$this->settingsReplyToAddress()]),
            subject: 'We could not charge your card for a weekly lesson',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.payments.charge-failed');
    }
}
