<?php

namespace Database\Factories;

use App\Enums\CurriculumCode;
use App\Models\Curriculum;
use Illuminate\Database\Eloquent\Factories\Factory;

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
        $code = fake()->unique()->randomElement(CurriculumCode::cases());

        return [
            'code' => $code,
            'name' => $code->value,
            'sort' => fake()->numberBetween(0, 100),
        ];
    }
}
