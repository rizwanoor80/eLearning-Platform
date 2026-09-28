<?php

namespace App\Actions\Safeguarding;

use App\Actions\RecordAuditLog;
use App\Enums\AbuseReportStatus;
use App\Models\AbuseReport;
use App\Models\User;
use RuntimeException;

/**
 * CP7 8d (R137): an admin picks up an open report from the Safeguarding queue. Purely a
 * bookkeeping move — no money, no lesson, no account touched — so a plain `RuntimeException`
 * on a closed report is enough, matching `SuspendAccount`/`ReinstateAccount`'s guard style.
 */
class MarkAbuseReportReviewing
{
    public function __construct(private readonly RecordAuditLog $recordAuditLog) {}

    /**
     * @throws RuntimeException when the report is already closed
     */
    public function __invoke(User $admin, AbuseReport $report): void
    {
        if ($report->status === AbuseReportStatus::Closed) {
            throw new RuntimeException('A closed report cannot be reopened here.');
        }

        $before = ['status' => $report->status->value, 'handled_by' => $report->handled_by];

        $report->forceFill([
            'status' => AbuseReportStatus::Reviewing,
            'handled_by' => $admin->id,
        ])->save();

        ($this->recordAuditLog)($admin, 'abuse_report.reviewing', $report, $before, [
            'status' => AbuseReportStatus::Reviewing->value,
            'handled_by' => $admin->id,
        ]);
    }
}
