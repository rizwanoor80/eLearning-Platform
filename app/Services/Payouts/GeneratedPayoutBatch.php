<?php

namespace App\Services\Payouts;

use App\Models\PayoutBatch;

/**
 * What `GeneratePayoutBatch` made: the batch (null when nobody qualified, in which case nothing was written) and the
 * tutors who had at least the payout minimum available but were left out, with the reason, so the admin can chase them.
 * `$anonymisedOwed` is separate: closed accounts that are still owed money (any positive amount), kept out of every batch for
 * an admin to decide manually (R263).
 */
final readonly class GeneratedPayoutBatch
{
    /**
     * @param  list<array{tutor_profile_id: int, tutor: string, reason: string}>  $skipped
     * @param  list<array{tutor_profile_id: int, amount_fils: int}>  $anonymisedOwed
     */
    public function __construct(
        public ?PayoutBatch $batch,
        public array $skipped,
        public array $anonymisedOwed = [],
    ) {}
}
