<?php

namespace App\Http\Requests\Learner;

use App\Models\Learner;
use App\Support\YearGroups\YearGroupOptions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreLearnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Learner::class) ?? false;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:255'],
            'year_group_id' => ['required', 'integer', YearGroupOptions::belongingTo($this->input('curriculum_id'))],
            'curriculum_id' => ['required', 'integer', 'exists:curricula,id'],
            'school' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
