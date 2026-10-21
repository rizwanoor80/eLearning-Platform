<?php

namespace App\Actions\Payouts;

use App\Actions\RecordAuditLog;
use App\Enums\PayoutLineStatus;
use App\Exceptions\PayoutException;
use App\Models\PayoutBatchLine;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 23h (R251): the payment of a line did not go through (wrong account, bounced, provider refused). The line becomes
 * `failed` with a reason and the money is available to the tutor again, simply because it is no longer in an unpaid
 * line. Nothing is written to the ledger: no money moved. Only a `pending` line can fail; a paid line is never
 * un-paid here (a payment that needs taking back is a clawback and its own decision), and a failed line is final, a new
 * batch makes a new line.
 *
 * Lock order: the tutor's profile row, then the line.
 */
class MarkPayoutLineFailed
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    /**
     * @throws PayoutException
     */
    public function __invoke(User $admin, PayoutBatchLine $line, string $reason): void
    {
        AssertPayoutAdmin::for($admin, 'mark a payout failed');

        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new PayoutException('Say why the payout failed (up to 500 characters).');
        }

        DB::transaction(function () use ($admin, $line, $reason): void {
            TutorProfile::query()->whereKey($line->tutor_profile_id)->lockForUpdate()->firstOrFail();
            $locked = PayoutBatchLine::query()->whereKey($line->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== PayoutLineStatus::Pending) {
                throw new PayoutException("This payout is already {$locked->status->value}.");
            }

            $locked->update([
                'status' => PayoutLineStatus::Failed,
                'failed_at' => now(),
                'failure_reason' => $reason,
                'resolved_by_user_id' => $admin->id,
            ]);

            ($this->recordAuditLog)($admin, 'payout_line.failed', $locked, ['status' => PayoutLineStatus::Pending->value], [
                'status' => PayoutLineStatus::Failed->value,
                'reference' => $locked->reference,
                'reason' => $reason,
            ]);
        });
    }
}
