<?php

namespace App\Events\Payments;

use App\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * R259 (2): the gateway took a booking's payment but the booking could not be confirmed, so the money has
 * to go back to the parent's card. Its queued listener makes the refund; the hourly sweep is the net.
 */
class UnverifiedCaptureNeedsRefund
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Payment $payment) {}
}
