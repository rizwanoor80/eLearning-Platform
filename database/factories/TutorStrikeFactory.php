<?php

namespace Database\Factories;

use App\Enums\StrikeType;
use App\Models\TutorProfile;
use App\Models\TutorStrike;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TutorStrike>
 */
class TutorStrikeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tutor_profile_id' => TutorProfile::factory(),
            'lesson_id' => null,
            'type' => StrikeType::LateCancel,
            'note' => null,
        ];
    }
}
