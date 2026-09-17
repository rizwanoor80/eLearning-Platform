<?php

namespace Database\Factories;

use App\Enums\AvailabilityExceptionType;
use App\Models\AvailabilityException;
use App\Models\TutorProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AvailabilityException>
 */
class AvailabilityExceptionFactory extends Factory
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
            'date' => fake()->dateTimeBetween('+1 day', '+1 month'),
            'start_time' => '10:00',
            'end_time' => '12:00',
            'type' => AvailabilityExceptionType::Blocked,
        ];
    }
}
