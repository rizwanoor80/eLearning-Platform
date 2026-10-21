<?php

namespace App\Actions\Payouts;

use App\Exceptions\PayoutException;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * 24e (R263): the payout actions move or release money, so each checks for itself that the caller is an active admin
 * (the `access-admin-area` gate, one definition of that rule) instead of relying on the Filament page that calls it.
 * Called first, before any input check, lock, ledger write or audit row.
 */
final class AssertPayoutAdmin
{
    /**
     * @throws PayoutException
     */
    public static function for(User $user, string $doing): void
    {
        if (! Gate::forUser($user)->allows('access-admin-area')) {
            throw new PayoutException("Only an active admin can {$doing}.");
        }
    }
}
