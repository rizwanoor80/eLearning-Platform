<?php

namespace App\Support\Payments;

/**
 * The PaymentIntent the parent's browser has just confirmed (R248, R256), carried for one request
 * from the checkout to the gateway's `capture()`, whose signature has no room for it (R255). Bound
 * with `scoped()`, so a queue job or a second request never sees another's intent. A driver that
 * takes the card another way (the fake) ignores it.
 */
final class CheckoutIntent
{
    private ?string $intentId = null;

    public function set(string $intentId): void
    {
        $this->intentId = $intentId;
    }

    public function get(): ?string
    {
        return $this->intentId;
    }
}
