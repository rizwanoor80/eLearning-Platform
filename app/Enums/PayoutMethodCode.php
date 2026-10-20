<?php

namespace App\Enums;

/**
 * The codes the `payout_methods` registry holds (R249, ADR-030). Every method is manual in v1: the
 * platform exports a file per method and an admin pays in the bank's or provider's own portal, so a
 * code carries no driver, only the kind of detail a tutor must give for it.
 */
enum PayoutMethodCode: string
{
    case BankTransfer = 'bank_transfer';
    case Payoneer = 'payoneer';
    case PayPal = 'paypal';
    case Stripe = 'stripe';
    case Wise = 'wise';

    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => 'Bank transfer',
            self::Payoneer => 'Payoneer',
            self::PayPal => 'PayPal',
            self::Stripe => 'Stripe',
            self::Wise => 'Wise',
        };
    }

    /**
     * Bank transfer uses the bank fields on the tutor profile (the same ones onboarding collects);
     * every other method needs one account email.
     */
    public function needsBankDetails(): bool
    {
        return $this === self::BankTransfer;
    }

    public function needsAccountEmail(): bool
    {
        return ! $this->needsBankDetails();
    }

    public function sort(): int
    {
        return match ($this) {
            self::BankTransfer => 1,
            self::Payoneer => 2,
            self::PayPal => 3,
            self::Stripe => 4,
            self::Wise => 5,
        };
    }
}
