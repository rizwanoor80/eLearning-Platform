<?php

namespace Database\Factories;

use App\Enums\LevelTier;
use App\Models\Curriculum;
use App\Models\YearGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<YearGroup>
 */
class YearGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1, 30000);

        return [
            'curriculum_id' => Curriculum::factory(),
            'code' => 'yg'.$n,
            'label' => 'Year '.$n,
            'sort' => $n,
            'level_tier' => LevelTier::LowerSecondary,
        ];
    }
}
