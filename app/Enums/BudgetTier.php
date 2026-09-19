<?php

namespace App\Enums;

/**
 * PRD §2.3 asks for a "budget band" without naming the bands; three coarse,
 * unpriced tiers keep the form simple (owner can relabel — Owner action 4).
 */
enum BudgetTier: string
{
    case Low = 'low';
    case Mid = 'mid';
    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Budget-friendly',
            self::Mid => 'Mid-range',
            self::High => 'Premium',
        };
    }
}
