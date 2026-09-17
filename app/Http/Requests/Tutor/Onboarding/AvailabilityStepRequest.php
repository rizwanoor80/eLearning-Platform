<?php

namespace App\Http\Requests\Tutor\Onboarding;

use App\Enums\AvailabilityExceptionType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AvailabilityStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'rules' => ['required', 'array', 'min:1'],
            'rules.*.weekday' => ['required', 'integer', 'between:0,6'],
            'rules.*.start_time' => ['required', 'date_format:H:i'],
            'rules.*.end_time' => ['required', 'date_format:H:i', 'after:rules.*.start_time'],
            'exceptions' => ['sometimes', 'array'],
            'exceptions.*.date' => ['required', 'date', 'after_or_equal:today'],
            'exceptions.*.start_time' => ['required', 'date_format:H:i'],
            'exceptions.*.end_time' => ['required', 'date_format:H:i', 'after:exceptions.*.start_time'],
            'exceptions.*.type' => ['required', Rule::enum(AvailabilityExceptionType::class)],
        ];
    }
}
