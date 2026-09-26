<?php

namespace App\Mail\Lessons;

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
 * The tutor's prompt to write a lesson's report (PRD §2.7 rule 1), sent when the lesson completes.
 */
class ReportDueMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesSettingsSender;

    public function __construct(public Lesson $lesson, public User $recipient) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->settingsFromAddress(),
            replyTo: array_filter([$this->settingsReplyToAddress()]),
            subject: 'Please write your lesson report',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.lessons.report_due');
    }
}
