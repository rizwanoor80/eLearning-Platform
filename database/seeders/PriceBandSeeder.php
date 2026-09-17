<?php

namespace Database\Seeders;

use App\Models\Curriculum;
use App\Models\PriceBand;
use Illuminate\Database\Seeder;

class PriceBandSeeder extends Seeder
{
    /**
     * Fixed, never `now()` — a re-seed must update the same row, not create
     * a second effective-dated band.
     */
    private const EFFECTIVE_FROM = '2026-01-01';

    public function run(): void
    {
        Curriculum::query()->get()->each(function (Curriculum $curriculum): void {
            foreach ($curriculum->code->tiers() as $tier) {
                [$min, $max] = $tier->band();

                PriceBand::query()->updateOrCreate(
                    [
                        'curriculum_id' => $curriculum->id,
                        'level_tier' => $tier->value,
                        'effective_from' => self::EFFECTIVE_FROM,
                    ],
                    ['min_rate' => $min, 'max_rate' => $max],
                );
            }
        });
    }
}
