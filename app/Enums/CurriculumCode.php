<?php

namespace App\Enums;

enum CurriculumCode: string
{
    case Gcse = 'GCSE';
    case ALevel = 'A_LEVEL';
    case IbMyp = 'IB_MYP';
    case IbDp = 'IB_DP';
    case Cbse = 'CBSE';

    /**
     * The level tiers that exist for this curriculum (PRD §3): the UK and IB
     * tracks are already split by code (lower-secondary/exam-years-1 under
     * GCSE and IB_MYP, exam-years-2 under A_LEVEL and IB_DP); CBSE is the
     * only track that spans all three tiers under one code.
     *
     * @return array<int, LevelTier>
     */
    public function tiers(): array
    {
        return match ($this) {
            self::Gcse, self::IbMyp => [LevelTier::LowerSecondary, LevelTier::Exam1],
            self::ALevel, self::IbDp => [LevelTier::Exam2],
            self::Cbse => [LevelTier::LowerSecondary, LevelTier::Exam1, LevelTier::Exam2],
        };
    }
}
