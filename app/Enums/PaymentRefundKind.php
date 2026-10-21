<?php

namespace App\Enums;

enum PaymentRefundKind: string
{
    /** Returns a `Refund`-account ledger entry to the parent's card (23d). */
    case Ledger = 'ledger';

    /** Returns a capture the platform never held (no ledger entry at all, R259). */
    case Unverified = 'unverified';

    /** Made at the gateway by someone else (the Stripe dashboard); recorded, never applied to the ledger (R260). */
    case External = 'external';
}
