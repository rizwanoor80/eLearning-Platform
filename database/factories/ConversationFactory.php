<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_user_id' => User::factory(),
            'tutor_profile_id' => TutorProfile::factory()->approved(),
        ];
    }

    /**
     * A pair whose first lesson has been completed, so contact details are no longer masked.
     */
    public function afterFirstLesson(): static
    {
        return $this->state(fn (array $attributes) => ['first_lesson_completed_at' => now()->subDay()]);
    }
}
