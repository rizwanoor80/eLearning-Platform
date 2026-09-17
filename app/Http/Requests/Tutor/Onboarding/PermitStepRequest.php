<?php

namespace App\Http\Requests\Tutor\Onboarding;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PermitStepRequest extends FormRequest
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
            'permit_number' => ['required', 'string', 'max:64'],
            'permit_expires_at' => ['required', 'date', 'after:today'],
        ];
    }
}
