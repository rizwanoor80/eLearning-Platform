<?php

namespace Database\Factories;

use App\Enums\LevelTier;
use App\Models\Curriculum;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\TutorSubject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TutorSubject>
 */
class TutorSubjectFactory extends Factory
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
            'curriculum_id' => Curriculum::factory(),
            'subject_id' => Subject::factory(),
            'level_min' => 'Year 7',
            'level_max' => 'Year 9',
            'level_tier' => LevelTier::LowerSecondary,
        ];
    }
}
