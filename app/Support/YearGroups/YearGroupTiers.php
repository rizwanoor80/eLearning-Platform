<?php

namespace App\Support\YearGroups;

use App\Enums\LevelTier;
use App\Models\YearGroup;

/**
 * The level tier of a tutor's subject row is derived, never typed (R33): the
 * highest tier — by rank — among the curriculum's year groups whose `sort`
 * lies between the row's lowest and highest year group.
 */
class YearGroupTiers
{
    /**
     * Null when either end is missing, the ends are of different curricula, or
     * min sorts after max — there is no valid range to derive from.
     */
    public static function derive(?YearGroup $min, ?YearGroup $max): ?LevelTier
    {
        if ($min === null || $max === null || $min->curriculum_id !== $max->curriculum_id || $min->sort > $max->sort) {
            return null;
        }

        $tiers = YearGroup::query()
            ->where('curriculum_id', $min->curriculum_id)
            ->whereBetween('sort', [$min->sort, $max->sort])
            ->pluck('level_tier');

        return $tiers->isEmpty() ? null : $tiers->sortByDesc(fn (LevelTier $tier): int => $tier->rank())->first();
    }
}
