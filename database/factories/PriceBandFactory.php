<?php

namespace Database\Factories;

use App\Enums\LevelTier;
use App\Models\Curriculum;
use App\Models\PriceBand;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceBand>
 */
class PriceBandFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tier = fake()->randomElement(LevelTier::cases());
        [$min, $max] = $tier->band();

        return [
            'curriculum_id' => Curriculum::factory(),
            'level_tier' => $tier,
            'min_rate' => $min,
            'max_rate' => $max,
            'effective_from' => fake()->date(),
        ];
    }
}
