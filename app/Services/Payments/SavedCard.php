<?php

namespace App\Services\Payments;

/**
 * What a gateway returns when a card is saved: the token and display details only — never a
 * card number (invariant 15). `SaveTestCard` stores it as the account's `payment_methods` row.
 */
final readonly class SavedCard
{
    public function __construct(
        public string $gatewayCustomerRef,
        public string $gatewayToken,
        public string $brand,
        public string $last4,
        public int $expMonth,
        public int $expYear,
    ) {}
}
