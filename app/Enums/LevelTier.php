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

    /**
     * The words a tutor sees for the tier (PRD §3's table), e.g. next to the price band that applies.
     */
    public function label(): string
    {
        return match ($this) {
            self::LowerSecondary => 'Lower secondary',
            self::Exam1 => 'Exam years 1',
            self::Exam2 => 'Exam years 2',
        };
    }

    /**
     * Ordering from youngest to oldest students, used to pick the "highest
     * tier" a tutor teaches when validating their rate against a price band.
     */
    public function rank(): int
    {
        return match ($this) {
            self::LowerSecondary => 0,
            self::Exam1 => 1,
            self::Exam2 => 2,
        };
    }
}
