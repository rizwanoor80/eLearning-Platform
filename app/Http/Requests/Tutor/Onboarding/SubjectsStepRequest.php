<?php

namespace App\Http\Requests\Tutor\Onboarding;

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
            'subjects.*.level_min_id' => ['required', 'integer', 'exists:year_groups,id'],
            'subjects.*.level_max_id' => ['required', 'integer', 'exists:year_groups,id'],
        ];
    }
}
