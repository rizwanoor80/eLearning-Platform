<?php

namespace Database\Factories;

use App\Enums\CurriculumCode;
use App\Enums\RecurringSlotStatus;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\RecurringSlot;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/**
 * @extends Factory<RecurringSlot>
 */
class RecurringSlotFactory extends Factory
{
    /**
     * Define the model's default state: an active Tuesday 17:00 (tutor local) slot at AED 100.00,
     * nothing generated yet. Curriculum and subject are shared found-or-created rows (as
     * `LessonFactory`), the learner sits in that curriculum.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $starts = Date::today();

        return [
            'learner_id' => fn () => Learner::factory()->create(['curriculum_id' => self::curriculumId()])->id,
            'tutor_profile_id' => TutorProfile::factory(),
            'curriculum_id' => fn () => self::curriculumId(),
            'subject_id' => fn () => Subject::query()->firstOrCreate(['slug' => 'lesson-factory-subject'], ['name' => 'Lesson factory subject', 'sort' => 0])->id,
            'weekday' => 2,
            'start_time' => '17:00',
            'timezone' => 'Asia/Dubai',
            'starts_on' => $starts,
            'ends_on' => null,
            'price' => 10000,
            'status' => RecurringSlotStatus::Active,
            'consecutive_charge_failures' => 0,
            'generated_until' => $starts->copy()->subDay(),
            'created_by_user_id' => User::factory(),
        ];
    }

    public function paused(): static
    {
        return $this->state(fn (array $attributes) => ['status' => RecurringSlotStatus::Paused]);
    }

    public function ended(): static
    {
        return $this->state(fn (array $attributes) => ['status' => RecurringSlotStatus::Ended, 'ended_at' => now()]);
    }

    private static function curriculumId(): int
    {
        return Curriculum::query()->firstOrCreate(['code' => CurriculumCode::Gcse], ['name' => CurriculumCode::Gcse->value, 'sort' => 0])->id;
    }
}
