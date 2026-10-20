<?php

namespace App\Enums;

/**
 * The codes the `payment_gateways` registry holds in v1 (ADR-030): the fake driver for local, CI
 * and rehearsal, and Stripe, the one real collection gateway. DATA_MODEL still names `tap`, `telr`
 * and `paypal`; each would be a new case and a new driver, with no schema change.
 */
enum PaymentGatewayCode: string
{
    case Fake = 'fake';
    case Stripe = 'stripe';

    /**
     * Whether a driver for this code exists. Stripe was registered by 23a and got its driver in 23b;
     * a future code without one cannot be made active.
     */
    public function hasDriver(): bool
    {
        return match ($this) {
            self::Fake => true,
            self::Stripe => true,
        };
    }

    /**
     * The credential keys the admin enters for this driver.
     *
     * @return list<string>
     */
    public function credentialKeys(): array
    {
        return match ($this) {
            self::Fake => [],
            self::Stripe => ['publishable_key', 'secret_key', 'webhook_secret'],
        };
    }

    /**
     * The keys that must all hold a value before the row can be active. The fake driver takes none.
     *
     * @return list<string>
     */
    public function requiredCredentialKeys(): array
    {
        return $this->credentialKeys();
    }

    public function label(): string
    {
        return match ($this) {
            self::Fake => 'Fake (local and rehearsal only)',
            self::Stripe => 'Stripe',
        };
    }
}
