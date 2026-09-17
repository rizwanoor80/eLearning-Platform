<?php

namespace App\Http\Requests\Tutor\Onboarding;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileStepRequest extends FormRequest
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
            'headline' => ['required', 'string', 'max:255'],
            'bio' => ['required', 'string', 'max:5000'],
            'intro_video_url' => ['nullable', 'url', 'max:255'],
        ];
    }
}
