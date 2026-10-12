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
     * `phone` dropped to optional (R170 advisor ruling): submission requires only name, country,
     * timezone, CV-or-LinkedIn and agreement — phone is not in that list. `country` is new
     * (R170): a plain ISO alpha-2 code, not validated against an exhaustive country list.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone' => ['nullable', 'string', 'max:32'],
            'country' => ['required', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'timezone' => ['required', 'string', 'timezone:all'],
        ];
    }
}
