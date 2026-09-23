<?php

namespace App\Enums;

/**
 * The ledger's accounts (DATA_MODEL v1.4). `gateway` is the external counter-leg
 * of money entering from, or returning to, the parent's payment method — the one
 * account not in v1.3, added so that every lesson's entries sum to zero (R52).
 * A `bank` counter-account for payouts is expected at CP5.
 */
enum LedgerAccount: string
{
    case Gateway = 'gateway';
    case Escrow = 'escrow';
    case Tutor = 'tutor';
    case Platform = 'platform';
    case Refund = 'refund';
}
