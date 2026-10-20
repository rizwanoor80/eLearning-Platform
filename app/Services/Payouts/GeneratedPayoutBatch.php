<?php

namespace App\Services\Payouts;

use App\Models\PayoutBatch;

/**
 * What `GeneratePayoutBatch` made: the batch (null when nobody qualified, in which case nothing was written) and the
 * tutors who had at least the payout minimum available but were left out, with the reason, so the admin can chase them.
 */
final readonly class GeneratedPayoutBatch
{
    /**
     * @param  list<array{tutor_profile_id: int, tutor: string, reason: string}>  $skipped
     */
    public function __construct(
        public ?PayoutBatch $batch,
        public array $skipped,
    ) {}
}
