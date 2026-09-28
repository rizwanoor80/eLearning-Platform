<?php

namespace App\Listeners\Safeguarding;

use App\Events\Safeguarding\AbuseReportFiled;
use App\Mail\Admin\AdminAbuseReportMail;
use App\Support\Mail\AdminRecipients;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class SendAbuseReportFiledMail implements ShouldQueue
{
    public function handle(AbuseReportFiled $event): void
    {
        if (($admins = AdminRecipients::resolve()) !== []) {
            Mail::to($admins)->send(new AdminAbuseReportMail($event->report));
        }
    }
}
