<?php

namespace App\Actions\Payouts;

use App\Actions\RecordAuditLog;
use App\Enums\PayoutLineStatus;
use App\Exceptions\PayoutException;
use App\Models\PayoutBatchLine;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Tutors\TutorEarnings;
use Illuminate\Support\Facades\DB;

/**
 * 23h (R251, R253): an admin has paid a line outside the platform and records it, with the bank's or provider's own
 * reference (required: a line is never "paid" on trust). In one transaction this writes the `payout` ledger entries through
 * `LedgerService::payout()` and the line's `paid` columns; money moves from processing to paid to date.
 *
 * Before the ledger is touched, and under the tutor's profile row lock, it checks the tutor's `available` bucket (which
 * already has every unpaid line, this one included, taken out of it) has not gone negative. It would if a dispute opened
 * on a lesson whose money the line includes after the batch was made: that money is on hold and the settlement may reverse
 * it, so paying it out now could leave the tutor's account negative. `OpenDispute` takes the same profile lock first, so a
 * dispute cannot land between this check and the commit. When the check fails the admin gets the reason and can mark the
 * line failed (the money goes back to the tutor's buckets) and generate again once the dispute is settled.
 *
 * Lock order, as everywhere: the tutor's profile row, then the line.
 */
class MarkPayoutLinePaid
{
    public function __construct(private LedgerService $ledger, private TutorEarnings $earnings, private RecordAuditLog $recordAuditLog) {}

    /**
     * @throws PayoutException
     */
    public function __invoke(User $admin, PayoutBatchLine $line, string $externalReference): void
    {
        $reference = trim(preg_replace('/\s+/u', ' ', $externalReference) ?? '');

        if ($reference === '' || mb_strlen($reference) > 191) {
            throw new PayoutException('Enter the payment reference from the bank or provider (up to 191 characters).');
        }

        DB::transaction(function () use ($admin, $line, $reference): void {
            $profile = TutorProfile::query()->whereKey($line->tutor_profile_id)->lockForUpdate()->firstOrFail();
            $locked = PayoutBatchLine::query()->whereKey($line->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== PayoutLineStatus::Pending) {
                throw new PayoutException("This payout is already {$locked->status->value}.");
            }

            if ($this->earnings->buckets($profile)->available->isNegative()) {
                throw new PayoutException('The tutor no longer has this much available: a lesson in this payout is now in a dispute. Mark the line failed and generate a new batch once the dispute is settled.');
            }

            $this->ledger->payout($locked, $admin);

            $locked->update([
                'status' => PayoutLineStatus::Paid,
                'external_reference' => $reference,
                'paid_at' => now(),
                'resolved_by_user_id' => $admin->id,
            ]);

            ($this->recordAuditLog)($admin, 'payout_line.paid', $locked, ['status' => PayoutLineStatus::Pending->value], [
                'status' => PayoutLineStatus::Paid->value,
                'reference' => $locked->reference,
                'amount_fils' => $locked->amount->toFils(),
            ]);
        });
    }
}
