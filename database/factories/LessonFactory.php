<?php

namespace Database\Factories;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\TutorProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/**
 * @extends Factory<Lesson>
 */
class LessonFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = Date::now()->addDays(3)->startOfHour();

        return [
            'tutor_profile_id' => TutorProfile::factory(),
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHour(),
            'status' => LessonStatus::Confirmed,
        ];
    }

    public function startingAt(\DateTimeInterface $start): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => $start,
            'ends_at' => Date::instance($start)->addHour(),
        ]);
    }
}
