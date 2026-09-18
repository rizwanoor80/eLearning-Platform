<?php

namespace Database\Factories;

use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Learner>
 */
class LearnerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_user_id' => User::factory(),
            'display_name' => fake()->firstName(),
            'is_minor' => true,
            'year_group' => 'Year '.fake()->numberBetween(1, 13),
            'curriculum_id' => Curriculum::factory(),
            'school' => null,
            'notes' => null,
        ];
    }

    /**
     * The adult student's own learner row.
     */
    public function selfLearner(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_minor' => false,
            'year_group' => null,
            'curriculum_id' => null,
        ]);
    }
}
