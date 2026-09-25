<?php

namespace App\Http\Requests\Payments;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The weekly-slot setup form. Ownership of the learner and every business rule (trial, card,
 * availability, lead time) are enforced by `CreateRecurringSlot`, not here: this only checks shape.
 */
class StoreWeeklySlotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'learner_id' => ['required', 'integer'],
            'tutor_id' => ['required', 'integer'],
            'curriculum_id' => ['required', 'integer'],
            'subject_id' => ['required', 'integer'],
            'weekday' => ['required', 'integer', 'between:0,6'],
            'start_time' => ['required', 'regex:/^([01]\d|2[0-3]):00$/'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ];
    }
}
