<?php

namespace App\Mail\Match;

use App\Models\MatchRequest;
use App\Support\Mail\UsesSettingsSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * To the account owner only (invariant #7). The tutors arrive as public cards
 * from TutorPresenter — never as models — and the request's goals are never
 * copied into the mail.
 */
class MatchSuggestionsMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesSettingsSender;

    /**
     * @param  list<array<string, mixed>>  $cards
     */
    public function __construct(public MatchRequest $matchRequest, public array $cards) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->settingsFromAddress(),
            replyTo: array_filter([$this->settingsReplyToAddress()]),
            subject: 'Your tutor suggestions are ready',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.match.suggestions');
    }
}
