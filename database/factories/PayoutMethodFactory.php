<?php

namespace Database\Factories;

use App\Enums\PayoutMethodCode;
use App\Models\PayoutMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayoutMethod>
 */
class PayoutMethodFactory extends Factory
{
    /**
     * An inactive Wise row for every country. The migration already inserts the five real rows, so
     * tests normally update those; this exists for a test that starts from an empty table.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => PayoutMethodCode::Wise->value,
            'name' => PayoutMethodCode::Wise->label(),
            'is_active' => false,
            'countries' => [],
            'sort' => PayoutMethodCode::Wise->sort(),
        ];
    }

    public function forCode(PayoutMethodCode $code): static
    {
        return $this->state(fn () => ['code' => $code->value, 'name' => $code->label(), 'sort' => $code->sort()]);
    }

    public function active(): static
    {
        return $this->state(fn () => ['is_active' => true]);
    }

    /**
     * @param  list<string>  $countries
     */
    public function countries(array $countries): static
    {
        return $this->state(fn () => ['countries' => $countries]);
    }
}
