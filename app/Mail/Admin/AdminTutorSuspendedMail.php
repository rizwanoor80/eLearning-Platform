<?php

namespace App\Mail\Admin;

use App\Models\TutorProfile;
use App\Support\Mail\UsesSettingsSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when `SuspendTutorForStrikes` auto-suspends a tutor after 3 strikes
 * within 90 days — not a PRD §8 row (that table has no "tutor suspended"
 * entry); this sub-cycle's own ruling to keep an admin informed for review.
 */
class AdminTutorSuspendedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesSettingsSender;

    public function __construct(public TutorProfile $profile, public int $strikeCount) {}

    public function envelope(): Envelope
    {
        $name = $this->profile->user->name;

        return new Envelope(
            from: $this->settingsFromAddress(),
            subject: "Tutor suspended for strikes: {$name}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.admin.tutor_suspended');
    }
}
