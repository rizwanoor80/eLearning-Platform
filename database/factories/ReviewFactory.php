<?php

namespace Database\Factories;

use App\Models\Lesson;
use App\Models\Review;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lesson_id' => Lesson::factory(),
            'tutor_profile_id' => TutorProfile::factory(),
            'account_user_id' => User::factory(),
            'rating' => fake()->numberBetween(1, 5),
            'comment' => fake()->boolean(70) ? fake()->sentence() : null,
            'published_at' => now(),
        ];
    }

    public function unpublished(): static
    {
        return $this->state(fn (): array => ['published_at' => null]);
    }
}
