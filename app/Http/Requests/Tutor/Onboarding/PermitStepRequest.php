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
     * R170: the permit is optional for everyone — "Skip for now" posts this step empty. Both
     * fields are nullable, but a tutor who does fill one in still gets the existing format/future
     * date checks (`required_with` keeps them paired: a number with no expiry, or an expiry with
     * no number, is still rejected).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'permit_number' => ['nullable', 'required_with:permit_expires_at', 'string', 'max:64'],
            'permit_expires_at' => ['nullable', 'required_with:permit_number', 'date', 'after:today'],
        ];
    }
}
