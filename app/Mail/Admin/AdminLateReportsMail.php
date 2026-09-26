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
 * Sent when a tutor's third late progress report in 90 days opens an admin review (PRD §2.7 rule 5). Like
 * `AdminTutorSuspendedMail`, not a PRD §8 row; this sub-cycle's own ruling to keep an admin informed.
 */
class AdminLateReportsMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesSettingsSender;

    public function __construct(public TutorProfile $profile, public int $lateReports) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->settingsFromAddress(),
            subject: "Late reports need review: {$this->profile->user->name}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.admin.late_reports');
    }
}
