<?php

namespace App\Http\Requests\Tutor\Onboarding;

use App\Enums\LevelTier;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubjectsStepRequest extends FormRequest
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
            'subjects' => ['required', 'array', 'min:1'],
            'subjects.*.curriculum_id' => ['required', 'integer', Rule::exists('curricula', 'id')],
            'subjects.*.subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')],
            'subjects.*.level_min' => ['required', 'string', 'max:64'],
            'subjects.*.level_max' => ['required', 'string', 'max:64'],
            'subjects.*.level_tier' => ['required', Rule::enum(LevelTier::class)],
        ];
    }
}
