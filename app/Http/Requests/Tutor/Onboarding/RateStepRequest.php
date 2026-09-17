<?php

namespace App\Http\Requests\Tutor\Onboarding;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RateStepRequest extends FormRequest
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
            'hourly_rate' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
        ];
    }
}
