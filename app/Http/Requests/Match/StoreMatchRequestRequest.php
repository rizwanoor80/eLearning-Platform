<?php

namespace App\Http\Requests\Match;

use App\Enums\BudgetTier;
use App\Models\MatchRequest;
use App\Support\YearGroups\YearGroupOptions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMatchRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', MatchRequest::class) ?? false;
    }

    /**
     * The curriculum is always chosen explicitly (an adult student's own
     * learner may have none), and the learner must be the caller's own and not deleted.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'learner_id' => ['required', 'integer', Rule::exists('learners', 'id')->where('account_user_id', $this->user()?->id)->whereNull('deleted_at')],
            'curriculum_id' => ['required', 'integer', 'exists:curricula,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'year_group_id' => ['required', 'integer', YearGroupOptions::belongingTo($this->input('curriculum_id'))],
            'goals' => ['required', 'string', 'max:2000'],
            'preferred_times' => ['nullable', 'string', 'max:1000'],
            'budget_tier' => ['required', Rule::enum(BudgetTier::class)],
        ];
    }
}
