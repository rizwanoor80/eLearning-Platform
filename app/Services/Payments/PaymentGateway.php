<?php

namespace App\Services\Payments;

use App\Exceptions\PaymentCaptureException;
use App\Models\Lesson;
use App\Support\Money;

/**
 * Invariant 16: driver and credentials resolve from the `payment_gateways`
 * admin registry (CP5). Until then this interface is not bound in any
 * service provider — only tests bind a driver, so resolving it outside a
 * test fails loudly rather than pretending a gateway exists.
 */
interface PaymentGateway
{
    /**
     * The registry driver key this implementation is stored under, e.g.
     * `fake` or `stripe`. Written to `payments.gateway`.
     */
    public function driver(): string;

    /**
     * @throws PaymentCaptureException
     */
    public function capture(Lesson $lesson, Money $amount, string $idempotencyKey): PaymentCaptureResult;
}
