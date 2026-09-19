<?php

namespace Database\Factories;

use App\Enums\BudgetTier;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\MatchRequest;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatchRequest>
 */
class MatchRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_user_id' => User::factory(),
            'learner_id' => fn (array $attributes) => Learner::factory()->create(['account_user_id' => $attributes['account_user_id']])->id,
            'curriculum_id' => Curriculum::factory(),
            'subject_id' => Subject::factory(),
            'year_group' => 'Year 8',
            'goals' => 'Build confidence before the exams.',
            'preferred_times' => 'Weekday evenings',
            'budget_tier' => BudgetTier::Mid,
        ];
    }
}
