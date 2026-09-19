<?php

namespace Database\Factories;

use App\Enums\CurriculumCode;
use App\Enums\LevelTier;
use App\Enums\TutorProfileStatus;
use App\Models\Curriculum;
use App\Models\PriceBand;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\TutorSubject;
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

    /**
     * A profile that passes the approval-time rate check (R36 f): one lower-secondary
     * subject in a GCSE curriculum (found or created) with a current band that
     * contains the profile's rate. Only the rate/band precondition — documents
     * and permit are set by the test.
     */
    public function approvable(): static
    {
        return $this->afterCreating(fn (TutorProfile $profile) => self::makeApprovable($profile));
    }

    /**
     * Gives an existing profile the subject and price band `approvable()` sets up,
     * and a rate inside that band.
     */
    public static function makeApprovable(TutorProfile $profile): void
    {
        $curriculum = Curriculum::query()->firstOrCreate(
            ['code' => CurriculumCode::Gcse],
            ['name' => CurriculumCode::Gcse->value, 'sort' => 0],
        );

        TutorSubject::factory()->create([
            'tutor_profile_id' => $profile->id,
            'curriculum_id' => $curriculum->id,
            'subject_id' => Subject::factory()->create()->id,
        ]);

        PriceBand::query()->firstOrCreate(
            ['curriculum_id' => $curriculum->id, 'level_tier' => LevelTier::LowerSecondary, 'effective_from' => '2000-01-01'],
            ['min_rate' => 5000, 'max_rate' => 20000],
        );

        $profile->forceFill(['hourly_rate' => 10000])->save();
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
