<?php

namespace App\Events\Payments;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * 25c (R268): a removed Stripe card could not be detached at the gateway after every retry
 * (`DetachRemovedCard`). The card can never be charged again; only Stripe still holds it. Carries the
 * payment-method id only: the mail goes to `AdminRecipients::resolve()`, which can be an external inbox.
 */
class RemovedCardNotDetached
{
    use Dispatchable;

    public function __construct(public readonly int $paymentMethodId) {}
}
