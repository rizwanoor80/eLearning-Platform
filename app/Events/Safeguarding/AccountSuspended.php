<?php

namespace App\Events\Safeguarding;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after `SuspendAccount` commits (R232). Carries ids, not models, so the queued listener reads the account fresh.
 */
class AccountSuspended
{
    use Dispatchable;

    public function __construct(public int $accountId, public ?int $actorId) {}
}
