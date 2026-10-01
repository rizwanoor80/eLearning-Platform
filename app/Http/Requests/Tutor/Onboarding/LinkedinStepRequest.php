<?php

namespace App\Http\Requests\Tutor\Onboarding;

use App\Rules\LinkedinUrlRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class LinkedinStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Nullable: posting an empty value clears a previously-set URL (a tutor who uploads a CV
     * instead no longer needs one on file).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'linkedin_url' => ['nullable', 'string', 'max:255', new LinkedinUrlRule],
        ];
    }
}
