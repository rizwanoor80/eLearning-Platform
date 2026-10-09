<?php

namespace App\Mail\Tutor;

use App\Enums\TutorReviewSection;
use App\Models\TutorProfile;
use App\Support\Mail\UsesSettingsSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TutorChangesRequestedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesSettingsSender;

    public function __construct(public TutorProfile $profile) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->settingsFromAddress(),
            replyTo: array_filter([$this->settingsReplyToAddress()]),
            subject: 'Changes needed on your profile',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.tutor.changes_requested',
            with: [
                // Labels of the sections the admin named (R185), in checklist order.
                'sections' => array_values(array_filter(array_map(
                    fn (string $key): ?string => TutorReviewSection::tryFrom($key)?->label(),
                    $this->profile->review_sections ?? [],
                ))),
            ],
        );
    }
}
