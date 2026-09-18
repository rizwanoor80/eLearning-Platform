<?php

namespace Database\Factories;

use App\Models\AvailabilityRule;
use App\Models\TutorProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AvailabilityRule>
 */
class AvailabilityRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tutor_profile_id' => TutorProfile::factory(),
            'weekday' => fake()->numberBetween(0, 6),
            'start_time' => '16:00',
            'end_time' => '18:00',
            'timezone' => 'Asia/Dubai',
        ];
    }
}
