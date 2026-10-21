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
        // Neither the code nor the label can match a seeded row (`y7`, `Year 7`, `MYP 1`, `Grade 6`, ...) in the same
        // curriculum: the label is not of the `Year N` form the defaults use.
        $n = fake()->unique()->numberBetween(1, 30000);

        return [
            'curriculum_id' => Curriculum::factory(),
            'code' => 'yg'.$n,
            'label' => 'Factory year group '.$n,
            'sort' => $n,
            'level_tier' => LevelTier::LowerSecondary,
        ];
    }
}
