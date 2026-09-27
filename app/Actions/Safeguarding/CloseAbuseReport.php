<?php

namespace App\Actions\Safeguarding;

use App\Actions\RecordAuditLog;
use App\Enums\AbuseReportStatus;
use App\Models\AbuseReport;
use App\Models\User;
use RuntimeException;

/**
 * CP7 8d (R137): closes a report with the admin's internal note as `action_taken` — this text
 * is never shown to the reported party (it is admin-only, unlike `SuspendTutor`'s tutor-visible
 * `review_note`, which the Filament action below passes a fixed neutral string instead).
 */
class CloseAbuseReport
{
    public function __construct(private readonly RecordAuditLog $recordAuditLog) {}

    /**
     * @throws RuntimeException when the report is already closed
     */
    public function __invoke(User $admin, AbuseReport $report, string $actionTaken): void
    {
        if ($report->status === AbuseReportStatus::Closed) {
            throw new RuntimeException('This report is already closed.');
        }

        $before = ['status' => $report->status->value, 'action_taken' => $report->action_taken];

        $report->forceFill([
            'status' => AbuseReportStatus::Closed,
            'action_taken' => $actionTaken,
            'handled_by' => $admin->id,
            'closed_at' => now(),
        ])->save();

        ($this->recordAuditLog)($admin, 'abuse_report.closed', $report, $before, [
            'status' => AbuseReportStatus::Closed->value,
            'action_taken' => $actionTaken,
            'handled_by' => $admin->id,
        ]);
    }
}
