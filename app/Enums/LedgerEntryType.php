<?php

namespace App\Enums;

enum LedgerEntryType: string
{
    case Hold = 'hold';
    case ReleaseTutor = 'release_tutor';
    case ReleaseCommission = 'release_commission';
    case Refund = 'refund';
    case Goodwill = 'goodwill';
    case Payout = 'payout';

    /**
     * CP8/R150: claws back a RELEASE leg (tutor or commission) into escrow
     * before `LedgerService::settle()` writes its own legs on a lesson that
     * had already been released when the dispute opened. Always appears in
     * a pair with the RELEASE leg it reverses; never used outside `settle()`.
     */
    case ReleaseReversal = 'release_reversal';
}
