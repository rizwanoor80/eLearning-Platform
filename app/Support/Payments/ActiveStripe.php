<?php

namespace App\Support\Payments;

use App\Enums\PaymentGatewayCode;
use App\Models\PaymentGatewayAccount;
use App\Services\Payments\NoActivePaymentGateway;
use App\Services\Payments\PaymentGatewayManager;
use Stripe\StripeClient;

/**
 * The checkout's view of the registry (R248, invariant 16): the usable active row, but only when it is
 * Stripe. The browser pieces (the publishable key, the Payment Element) and the intent-creating calls
 * live outside `app/Services/Payments` (frozen, R255), so they build their own client from the same row
 * rather than from the gateway object, whose key is private. Nothing here is cached: every call reads
 * the row, so deactivating the gateway stops a checkout at its next request.
 */
final class ActiveStripe
{
    public function __construct(private readonly PaymentGatewayManager $gateways) {}

    public function row(): ?PaymentGatewayAccount
    {
        $row = $this->gateways->usableRow();

        return $row?->gatewayCode() === PaymentGatewayCode::Stripe ? $row : null;
    }

    public function isActive(): bool
    {
        return $this->row() !== null;
    }

    /** The key the browser loads Stripe.js with; public by design, null when the row has none. */
    public function publishableKey(): ?string
    {
        $key = (string) ($this->row()?->credentials['publishable_key'] ?? '');

        return $key === '' ? null : $key;
    }

    /**
     * @throws NoActivePaymentGateway
     */
    public function client(): StripeClient
    {
        $secret = (string) ($this->row()?->credentials['secret_key'] ?? '');

        if ($secret === '') {
            throw new NoActivePaymentGateway('Stripe is not the active payment gateway.');
        }

        return new StripeClient($secret);
    }
}
