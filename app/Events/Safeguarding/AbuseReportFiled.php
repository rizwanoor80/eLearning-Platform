<?php

namespace App\Events\Safeguarding;

use App\Models\AbuseReport;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * CP7 8d (R137): a report was filed. Only admins are told (via
 * `SendAbuseReportFiledMail`) — the reported party must never learn a report exists.
 */
class AbuseReportFiled
{
    use Dispatchable, SerializesModels;

    public function __construct(public AbuseReport $report) {}
}
