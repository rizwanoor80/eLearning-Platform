<?php

namespace App\Mail\Admin;

use App\Models\AbuseReport;
use App\Support\Mail\UsesSettingsSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * CP7 8d (R137): sent to admins only when a safeguarding report is filed — never to the
 * reported party. The description is shown unmasked; this mailable is admin-only.
 */
class AdminAbuseReportMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesSettingsSender;

    public function __construct(public AbuseReport $report) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->settingsFromAddress(),
            subject: 'A safeguarding report was filed',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin.abuse_report_filed',
            with: [
                'reporterName' => $this->report->reporter->name,
                'subjectTypeLabel' => $this->report->subject_type->value,
                'reasonLabel' => $this->report->reason->label(),
            ],
        );
    }
}
