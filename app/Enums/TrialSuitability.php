<?php

namespace App\Enums;

enum TrialSuitability: string
{
    case GoodFit = 'good_fit';
    case PartialFit = 'partial_fit';
    case NotAFit = 'not_a_fit';

    public function label(): string
    {
        return match ($this) {
            self::GoodFit => 'A good fit',
            self::PartialFit => 'A partial fit',
            self::NotAFit => 'Not a fit',
        };
    }
}
