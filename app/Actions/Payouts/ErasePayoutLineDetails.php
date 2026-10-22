<?php

namespace App\Actions\Payouts;

use App\Enums\PayoutLineStatus;
use App\Models\PayoutBatchLine;
use App\Models\TutorProfile;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * R274: the only writer that erases the encrypted account-details copy on a payout line. Amount, method, currency,
 * reference, external reference and paid date stay (who was paid what, when, and the bank's reference); nothing here
 * deletes a line. Idempotent: a line already erased is not touched, and the count returned is the lines erased now.
 *
 * The caller holds the tutor's profile row `FOR UPDATE` inside a transaction (the action can only check the transaction; passing the
 * locked model documents the requirement, and every caller locks it first):
 * `MarkPayoutLinePaid` takes the same lock before it marks a line paid, so a payment recorded while an erasure runs is
 * either seen by the erasure or sees the erased account and erases its own copy. A query-builder UPDATE on purpose:
 * the model refuses any change to `details`, and the database trigger refuses anything but setting it to null.
 *
 * @param  list<PayoutLineStatus>  $statuses  the lines to erase, by status
 */
class ErasePayoutLineDetails
{
    /**
     * @param  list<PayoutLineStatus>  $statuses
     */
    public function __invoke(TutorProfile $lockedProfile, array $statuses): int
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Payout line details are erased inside the caller\'s transaction, under the tutor profile lock.');
        }

        if ($statuses === []) {
            return 0;
        }

        $now = now();

        return PayoutBatchLine::query()
            ->where('tutor_profile_id', $lockedProfile->getKey())
            ->whereNotNull('details')
            ->whereIn('status', array_map(fn (PayoutLineStatus $status) => $status->value, $statuses))
            ->toBase()
            ->update(['details' => null, 'details_erased_at' => $now, 'updated_at' => $now]);
    }

    /**
     * Whether the tutor still has a line whose details copy exists, of any status.
     */
    public static function anyLeft(TutorProfile $profile): bool
    {
        return PayoutBatchLine::query()->where('tutor_profile_id', $profile->getKey())->whereNotNull('details')->exists();
    }
}
