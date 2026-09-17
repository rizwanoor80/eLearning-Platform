<?php

namespace App\Http\Requests\Tutor\Onboarding;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PersonalStepRequest extends FormRequest
{
    /**
     * Authorization is the `access-tutor-area` route middleware; this
     * request only validates its own step's input.
     */
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
            'phone' => ['required', 'string', 'max:32'],
            'timezone' => ['required', 'string', 'timezone:all'],
        ];
    }
}
