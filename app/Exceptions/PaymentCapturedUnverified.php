<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A `PaymentGateway::capture()` call went through at the gateway but what came back is not a clean,
 * fully paid charge (still processing, a short amount, already refunded or disputed, no charge to
 * read). Money may have moved, so this is not a decline: `BookLesson` records the payment as
 * `captured` with its gateway reference, frees the slot and raises a `BookingException` for review,
 * and `ledger:verify` lists the payment as stranded until someone settles it.
 */
class PaymentCapturedUnverified extends RuntimeException
{
    /** @param array<string, mixed> $rawResponse */
    public function __construct(
        public readonly string $gatewayRef,
        public readonly array $rawResponse,
        string $reason = 'payment_not_completed',
    ) {
        parent::__construct($reason);
    }
}
