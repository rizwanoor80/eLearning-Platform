<?php

namespace Database\Factories;

use App\Enums\CurriculumCode;
use App\Models\Curriculum;
use Illuminate\Database\Eloquent\Factories\Factory;
use LogicException;

/**
 * @extends Factory<Curriculum>
 */
class CurriculumFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // A code no curriculum row has yet: `unique()` only remembers what this factory drew, so it collided with
        // curricula the seeders (or a test) had already created. The code space is five wide; a sixth is a test bug.
        $taken = Curriculum::query()->pluck('code')->map(fn ($code) => $code instanceof CurriculumCode ? $code->value : $code)->all();
        $free = array_values(array_filter(CurriculumCode::cases(), fn (CurriculumCode $case) => ! in_array($case->value, $taken, true)));

        if ($free === []) {
            throw new LogicException('Every curriculum code already exists; find it with Curriculum::query()->firstOrCreate() instead of the factory.');
        }

        $code = fake()->randomElement($free);

        return [
            'code' => $code,
            'name' => $code->value,
            'sort' => fake()->numberBetween(0, 100),
        ];
    }
}
