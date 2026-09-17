<?php

namespace App\Enums;

enum LevelTier: string
{
    case LowerSecondary = 'lower_secondary';
    case Exam1 = 'exam_1';
    case Exam2 = 'exam_2';

    /**
     * The PRD §3 illustrative band [min fils, max fils] for this tier.
     *
     * @return array{0: int, 1: int}
     */
    public function band(): array
    {
        return match ($this) {
            self::LowerSecondary => [8000, 15000],
            self::Exam1 => [10000, 20000],
            self::Exam2 => [13000, 26000],
        };
    }
}
