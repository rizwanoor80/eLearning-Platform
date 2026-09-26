<?php

namespace App\Events\Lessons;

use App\Models\ProgressReport;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after the commit of `SubmitProgressReport`, and only there: the parent's report email hangs off
 * this, not off `LessonStatusChanged`, because `completed_reported` is also reached by the auto-release,
 * a student no-show and a late parent cancellation, none of which has a report.
 */
class ProgressReportSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly ProgressReport $report) {}
}
