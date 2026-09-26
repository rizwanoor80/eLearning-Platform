<?php

namespace Database\Factories;

use App\Models\Lesson;
use App\Models\ProgressReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProgressReport>
 */
class ProgressReportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lesson_id' => Lesson::factory(),
            'tutor_profile_id' => fn (array $attributes): int => Lesson::query()->whereKey($attributes['lesson_id'])->value('tutor_profile_id'),
            'topics_covered' => 'Fractions and ratios.',
            'went_well' => 'Confident with the worked examples.',
            'work_on_next' => 'Word problems.',
            'homework' => 'Exercise 4b.',
            'engagement' => 4,
            'submitted_at' => now(),
        ];
    }
}
