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
 * A real transfer is always recordable (R258): the admin has already sent the money, so nothing about the tutor's balance
 * may refuse the record. Under the tutor's profile row lock it writes the entries and the line, then reads the tutor's
 * `available` bucket; if that is negative (a dispute settlement clawed back money that had already been paid) the line is
 * recorded all the same, the audit row says so, and admins see a flag on the tutor and the batch. The next batch nets the
 * negative amount against what the tutor earns next. `GeneratePayoutBatch` only includes money past its dispute window,
 * so a line made by it does not normally go negative.
 *
 * Lock order: the tutor's profile row, then the line.
 */
class MarkPayoutLinePaid
{
    public function __construct(private LedgerService $ledger, private TutorEarnings $earnings, private RecordAuditLog $recordAuditLog) {}

    /**
     * @throws PayoutException
     */
    public function __invoke(User $admin, PayoutBatchLine $line, string $externalReference): void
    {
        AssertPayoutAdmin::for($admin, 'record a payout');

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
                'tutor_available_after_fils' => $this->earnings->buckets($profile)->available->toFils(),
            ]);
        });
    }
}
