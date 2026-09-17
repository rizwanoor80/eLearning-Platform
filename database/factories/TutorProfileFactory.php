<?php

namespace Database\Factories;

use App\Enums\TutorProfileStatus;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TutorProfile>
 */
class TutorProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->tutor(),
            'headline' => fake()->sentence(4),
            'bio' => fake()->paragraph(),
            'hourly_rate' => fake()->numberBetween(8000, 15000),
            'status' => TutorProfileStatus::Draft,
            'permit_number' => fake()->bothify('PMT-########'),
            'permit_expires_at' => fake()->dateTimeBetween('+1 month', '+2 years'),
            'bank_name' => fake()->company(),
            'bank_account_name' => fake()->name(),
            'bank_iban' => fake()->iban('AE'),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TutorProfileStatus::Approved,
            'approved_at' => now(),
        ]);
    }

    public function withExpiredPermit(): static
    {
        return $this->state(fn (array $attributes) => [
            'permit_expires_at' => now()->subDay(),
        ]);
    }

    public function withPermitExpiringToday(): static
    {
        return $this->state(fn (array $attributes) => [
            'permit_expires_at' => now()->startOfDay(),
        ]);
    }
}
