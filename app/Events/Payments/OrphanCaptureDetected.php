<?php

namespace App\Events\Payments;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * R259 (4): the gateway took money for a PaymentIntent that no payment row on this platform carries.
 * Only the gateway's own identifiers travel: no payer, no card, no lesson.
 */
class OrphanCaptureDetected
{
    use Dispatchable;

    public function __construct(
        public readonly string $gateway,
        public readonly string $intentId,
        public readonly int $amount,
        public readonly string $currency,
    ) {}
}
