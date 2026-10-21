<?php

namespace App\Events\Payments;

use App\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Something about a payment an admin must look at: a verified gateway webhook (23b, R247) reporting a
 * refund the ledger did not make or a dispute (chargeback) opened with the card network, or (23d, R256)
 * a refund the ledger made that the gateway has not carried out. Nothing has been changed on the
 * lesson or in the ledger; this only tells people. Dispatched inside the webhook's transaction, so its
 * queued listener is `afterCommit`: a rolled-back webhook sends nothing and the worker never reads a
 * half-written payment.
 */
class PaymentNeedsAttention
{
    use Dispatchable, SerializesModels;

    public const REFUNDED_AT_GATEWAY = 'refunded_at_gateway';

    public const DISPUTED_AT_GATEWAY = 'disputed_at_gateway';

    /** 23d (R256): the ledger returned money to the parent but the gateway refused, or kept failing to, refund it. */
    public const REFUND_FAILED = 'refund_failed';

    /** R259 (2): a captured payment for a booking that could not be confirmed was refunded to the parent's card automatically. */
    public const UNVERIFIED_CAPTURE_REFUNDED = 'unverified_capture_refunded';

    public function __construct(public readonly Payment $payment, public readonly string $reason) {}
}
