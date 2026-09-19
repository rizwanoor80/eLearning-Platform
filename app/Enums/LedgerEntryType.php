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
}
