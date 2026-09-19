<?php

namespace Database\Factories;

use App\Enums\CurriculumCode;
use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;

/**
 * @extends Factory<Lesson>
 */
class LessonFactory extends Factory
{
    /**
     * Define the model's default state: a confirmed, regular lesson at a price of
     * AED 100.00 with the seed policy, its commission split frozen exactly as
     * `BookLesson` will freeze it (commission from the percentage, tutor amount the
     * remainder — so the two always add up to the price).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = Date::now()->addDays(3)->startOfHour();

        return [
            'type' => LessonType::Regular,
            'tutor_profile_id' => TutorProfile::factory(),
            // In the same shared curriculum as the lesson (a fresh factory curriculum could
            // collide with it on the unique code).
            'learner_id' => fn () => Learner::factory()->create([
                'curriculum_id' => Curriculum::query()->firstOrCreate(['code' => CurriculumCode::Gcse], ['name' => CurriculumCode::Gcse->value, 'sort' => 0])->id,
            ])->id,
            'booked_by_user_id' => User::factory(),
            // Shared rows, found or created: the curricula have a small unique code space, so a
            // factory that made a fresh one per lesson would run out of codes in a batch.
            'curriculum_id' => fn () => Curriculum::query()->firstOrCreate(['code' => CurriculumCode::Gcse], ['name' => CurriculumCode::Gcse->value, 'sort' => 0])->id,
            'subject_id' => fn () => Subject::query()->firstOrCreate(['slug' => 'lesson-factory-subject'], ['name' => 'Lesson factory subject', 'sort' => 0])->id,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHour(),
            'duration_minutes' => 60,
            'price' => 10000,
            'commission_pct' => 25,
            'commission_amount' => 2500,
            'tutor_amount' => 7500,
            'cancel_window_hours' => 24,
            'student_grace_min' => 15,
            'tutor_grace_min' => 10,
            'status' => LessonStatus::Confirmed,
        ];
    }

    /**
     * Tests must be able to build a lesson in any state, so creating one opens
     * the same scope the state machine uses; nothing under `app/` may do this.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        return Lesson::allowingStatusWrites(fn () => parent::create($attributes, $parent));
    }

    public function startingAt(\DateTimeInterface $start): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => $start,
            'ends_at' => Date::instance($start)->addHour(),
        ]);
    }

    public function trial(): static
    {
        return $this->state(fn (array $attributes) => ['type' => LessonType::Trial]);
    }

    public function withStatus(LessonStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }
}
