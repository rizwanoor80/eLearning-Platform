<?php

namespace App\Enums;

/**
 * The two fake-gateway test cards the "Add a card" page offers (R100). Picked from a list — no card
 * number is ever typed (invariant 15). The token prefix carries the charge outcome that 4e's fake
 * driver follows, and matches `PaymentMethodFactory`'s tokens.
 */
enum TestCard: string
{
    case Succeeds = 'succeeds';
    case Declines = 'declines';

    public function label(): string
    {
        return match ($this) {
            self::Succeeds => 'Test card — always succeeds',
            self::Declines => 'Test card — always declines',
        };
    }

    public function last4(): string
    {
        return match ($this) {
            self::Succeeds => '4242',
            self::Declines => '0002',
        };
    }

    public function tokenPrefix(): string
    {
        return 'fake_pm_'.$this->value.'_';
    }
}
