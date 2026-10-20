<?php

namespace App\Events\Payments;

use App\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A verified gateway webhook says something an admin must look at (23b, R247): a refund the ledger
 * did not make, or a dispute (chargeback) opened with the card network. Nothing has been changed on the
 * lesson or in the ledger; this only tells people. Dispatched inside the webhook's transaction, so its
 * queued listener is `afterCommit`: a rolled-back webhook sends nothing and the worker never reads a
 * half-written payment.
 */
class PaymentNeedsAttention
{
    use Dispatchable, SerializesModels;

    public const REFUNDED_AT_GATEWAY = 'refunded_at_gateway';

    public const DISPUTED_AT_GATEWAY = 'disputed_at_gateway';

    public function __construct(public readonly Payment $payment, public readonly string $reason) {}
}
