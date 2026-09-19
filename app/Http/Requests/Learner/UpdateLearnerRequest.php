<?php

namespace App\Http\Requests\Learner;

use App\Models\Learner;
use App\Support\YearGroups\YearGroupOptions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLearnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Learner $learner */
        $learner = $this->route('learner');

        return $this->user()?->can('update', $learner) ?? false;
    }

    /**
     * A parent-added learner needs a curriculum and year group; an adult
     * student's own learner may still be incomplete (it starts empty).
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        /** @var Learner $learner */
        $learner = $this->route('learner');
        $required = $learner->isSelf() ? 'nullable' : 'required';

        return [
            'display_name' => [$learner->isSelf() ? 'sometimes' : 'required', 'string', 'max:255'],
            'year_group_id' => [$required, 'integer', YearGroupOptions::belongingTo($this->input('curriculum_id'))],
            'curriculum_id' => [$required, 'integer', 'exists:curricula,id'],
            'school' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
