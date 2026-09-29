<?php

namespace App\Mail\Admin;

use App\Models\Dispute;
use App\Support\Mail\UsesSettingsSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * CP8 (R150/PRD §8): sent to admins when a dispute is opened — never to the tutor or the account
 * holder. Unlike `AdminAbuseReportMail` (which shows its report description unmasked to admins),
 * this deliberately excludes the account holder's free-text `description` too: R150 states the
 * "no parent text quoted" rule once, for "the tutor is emailed", but nothing in R150 or PRD §8
 * carves out an admin-only exception the way the abuse-report flow does, and when
 * `AdminRecipients::resolve()` returns the `support_address` setting this mail can leave the
 * product entirely — quoting the family's private complaint into an arbitrary external inbox is
 * not something to do without it being named explicitly.
 */
class AdminDisputeOpenedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesSettingsSender;

    public function __construct(public Dispute $dispute) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->settingsFromAddress(),
            subject: 'A dispute was opened',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.admin.dispute_opened');
    }
}
