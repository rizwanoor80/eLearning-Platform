<?php

namespace App\Listeners\Lessons;

use App\Enums\LessonStatus;
use App\Events\Lessons\LessonStatusChanged;
use App\Mail\Admin\AdminDisputeOpenedMail;
use App\Support\Mail\AdminRecipients;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

/**
 * CP8 (R150): "every active admin is emailed" — the `AdminRecipients::resolve()` pattern (R31,
 * matching `SendAbuseReportFiledMail`/`SendTutorSuspendedForStrikesMail` and four other admin-mail
 * listeners): the `support_address` setting when set, else every active admin.
 */
class SendAdminDisputeOpenedMail implements ShouldQueue
{
    public function handle(LessonStatusChanged $event): void
    {
        if (! in_array($event->from, [LessonStatus::Completed, LessonStatus::CompletedReported], true)
            || $event->to !== LessonStatus::Disputed) {
            return;
        }

        $dispute = $event->lesson->dispute;

        if ($dispute === null) {
            return;
        }

        if (($admins = AdminRecipients::resolve()) !== []) {
            Mail::to($admins)->send(new AdminDisputeOpenedMail($dispute));
        }
    }
}
