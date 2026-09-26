<?php

namespace App\Listeners\Tutor;

use App\Events\Tutor\TutorLateReportsFlagged;
use App\Mail\Admin\AdminLateReportsMail;
use App\Support\Mail\AdminRecipients;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class SendTutorLateReportsFlaggedMail implements ShouldQueue
{
    public function handle(TutorLateReportsFlagged $event): void
    {
        if (($admins = AdminRecipients::resolve()) !== []) {
            Mail::to($admins)->send(new AdminLateReportsMail($event->profile, $event->lateReports));
        }
    }
}
