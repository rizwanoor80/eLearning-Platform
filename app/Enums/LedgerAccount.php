<?php

namespace App\Enums;

/**
 * The ledger's accounts (DATA_MODEL v1.4). `gateway` is the external counter-leg
 * of money entering from, or returning to, the parent's payment method — the one
 * account not in v1.3, added so that every lesson's entries sum to zero (R52).
 * `bank` (23h, R253) is the counter-leg of a payout: money that has left the platform for a tutor's bank or payout
 * provider. Its entries carry no lesson, only the payout line they pay.
 */
enum LedgerAccount: string
{
    case Gateway = 'gateway';
    case Escrow = 'escrow';
    case Tutor = 'tutor';
    case Platform = 'platform';
    case Refund = 'refund';
    case Bank = 'bank';
}
