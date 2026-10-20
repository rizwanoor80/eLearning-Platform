<?php

namespace App\Events\Payouts;

use App\Models\TutorProfile;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A tutor saved their payout method or its details (R249). Carries no detail values: the queued listener
 * tells the account holder that it happened, so a hijacked session cannot redirect money unnoticed.
 */
class PayoutDetailsChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(public TutorProfile $profile, public string $methodLabel) {}
}
