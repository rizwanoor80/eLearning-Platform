<?php

namespace App\Mail\Lessons;

use App\Models\ProgressReport;
use App\Models\User;
use App\Support\Mail\UsesSettingsSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The tutor's report, to the account holder of the learner — never to the learner (invariant 7). A trial
 * report also carries the link to set up a weekly slot with that tutor (CP6 box 4). The link stays plain
 * (tutor and learner ids only): the form it opens computes its own pre-fill from the signed-in parent's
 * own completed trial (`TrialSlotPrefill`, 7f), so nothing about the trial travels in the URL.
 */
class ProgressReportMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesSettingsSender;

    public function __construct(public ProgressReport $report, public User $recipient) {}

    public function envelope(): Envelope
    {
        $lesson = $this->report->lesson;
        $kind = $this->report->trial_suitability !== null ? 'Trial lesson report' : 'Progress report';

        return new Envelope(
            from: $this->settingsFromAddress(),
            replyTo: array_filter([$this->settingsReplyToAddress()]),
            subject: $kind.' for '.$lesson->learner->display_name,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.lessons.progress_report');
    }
}
