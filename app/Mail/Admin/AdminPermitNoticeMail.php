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
 * PRD §8: permit expiring (30d, 7d) / expired notices go to "Tutor + admin".
 * `$daysRemaining` is null for the expired notice.
 */
class AdminPermitNoticeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesSettingsSender;

    public function __construct(public TutorProfile $profile, public ?int $daysRemaining = null) {}

    public function envelope(): Envelope
    {
        $name = $this->profile->user->name;

        return new Envelope(
            from: $this->settingsFromAddress(),
            subject: $this->daysRemaining === null
                ? "Tutor permit expired: {$name}"
                : "Tutor permit expires in {$this->daysRemaining} days: {$name}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.admin.permit_notice');
    }
}
