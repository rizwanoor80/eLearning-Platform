<?php

namespace App\Services\Pricing;

use App\Enums\LevelTier;
use App\Models\PriceBand;

class PriceBandOverlapCheck
{
    /**
     * R26 catches a rate that can't satisfy several curricula; this catches
     * the data error at its source. For one level tier, takes each
     * curriculum's current band (latest `effective_from` not in the future)
     * and reports the curricula by name when those bands share no common
     * rate. In one dimension, pairwise overlap implies a common overlap, so
     * "max of the minimums above min of the maximums" is the whole test.
     *
     * @return array<int, string> empty when the tier's current bands all overlap
     */
    public function conflictingCurricula(LevelTier $tier): array
    {
        $current = PriceBand::query()
            ->with('curriculum')
            ->where('level_tier', $tier)
            ->where('effective_from', '<=', now()->toDateString())
            ->orderByDesc('effective_from')
            ->get()
            ->unique('curriculum_id');

        if ($current->count() < 2) {
            return [];
        }

        $highestMin = $current->max(fn (PriceBand $band) => $band->min_rate->toFils());
        $lowestMax = $current->min(fn (PriceBand $band) => $band->max_rate->toFils());

        if ($highestMin <= $lowestMax) {
            return [];
        }

        return $current->map(fn (PriceBand $band) => $band->curriculum->name)->values()->all();
    }
}
