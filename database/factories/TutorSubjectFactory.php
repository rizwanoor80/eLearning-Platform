<?php

namespace Database\Factories;

use App\Enums\LevelTier;
use App\Models\Curriculum;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\TutorSubject;
use App\Models\YearGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TutorSubject>
 */
class TutorSubjectFactory extends Factory
{
    /**
     * Define the model's default state. The two year groups are shared per
     * curriculum (found or created), so many rows can use one curriculum:
     * "Factory low" (sort 7) up to "Factory high" (sort 9), both lower secondary.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tutor_profile_id' => TutorProfile::factory(),
            'curriculum_id' => Curriculum::factory(),
            'subject_id' => Subject::factory(),
            'level_min_id' => fn (array $attributes) => self::yearGroup($attributes['curriculum_id'], 'factory-low', 'Factory low', 7)->id,
            'level_max_id' => fn (array $attributes) => self::yearGroup($attributes['curriculum_id'], 'factory-high', 'Factory high', 9)->id,
            'level_tier' => LevelTier::LowerSecondary,
        ];
    }

    private static function yearGroup(int $curriculumId, string $code, string $label, int $sort): YearGroup
    {
        return YearGroup::query()->firstOrCreate(
            ['curriculum_id' => $curriculumId, 'code' => $code],
            ['label' => $label, 'sort' => $sort, 'level_tier' => LevelTier::LowerSecondary],
        );
    }
}
