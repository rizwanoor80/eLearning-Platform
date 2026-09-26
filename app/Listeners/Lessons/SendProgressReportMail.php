<?php

namespace App\Listeners\Lessons;

use App\Events\Lessons\ProgressReportSubmitted;
use App\Mail\Lessons\ProgressReportMail;
use App\Models\ProgressReport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails the report to the learner's account holder (invariant 7). `emailed_at` is claimed with a
 * conditional update first, so a retried or duplicated delivery of the event sends nothing twice.
 */
class SendProgressReportMail implements ShouldQueue
{
    public function handle(ProgressReportSubmitted $event): void
    {
        $claimed = ProgressReport::query()
            ->whereKey($event->report->getKey())
            ->whereNull('emailed_at')
            ->update(['emailed_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        try {
            $report = $event->report->fresh(['lesson.learner.account', 'lesson.tutorProfile.user', 'lesson.subject']);
            $account = $report->lesson->learner->account;

            // The mailable is queued, so a failure here is a failure to hand it over; the claim must not
            // outlive it, or the retry would find the claim and the parent would never get the report.
            Mail::to($account)->send(new ProgressReportMail($report, $account));
        } catch (Throwable $e) {
            ProgressReport::query()->whereKey($event->report->getKey())->update(['emailed_at' => null]);

            throw $e;
        }
    }
}
