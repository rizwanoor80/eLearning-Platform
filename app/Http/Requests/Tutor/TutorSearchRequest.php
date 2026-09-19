<?php

namespace App\Http\Requests\Tutor;

use App\Models\Learner;
use App\Models\User;
use App\Services\Search\TutorSearchCriteria;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TutorSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        $price = ['nullable', 'regex:/^\d{1,6}(\.\d{1,2})?$/'];

        return [
            'learner' => ['nullable', 'integer'],
            'curriculum_id' => ['nullable', 'integer', 'exists:curricula,id'],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'year_group' => ['nullable', 'string', 'max:50'],
            'min_price' => $price,
            'max_price' => $price,
            'day' => ['nullable', 'integer', 'between:0,6'],
            'time_of_day' => ['nullable', Rule::in([TutorSearchCriteria::MORNING, TutorSearchCriteria::AFTERNOON, TutorSearchCriteria::EVENING])],
            'min_rating' => ['nullable', 'numeric', 'between:0,5'],
            'sort' => ['nullable', Rule::in([TutorSearchCriteria::SORT_RATING, TutorSearchCriteria::SORT_PRICE])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * The learner the parent is searching for, only if it is theirs: a
     * guest, another account's learner, a soft-deleted one and a missing one
     * all give null — never an error and never a hint the id exists.
     */
    public function ownLearner(): ?Learner
    {
        $user = $this->user();
        $id = $this->query('learner');

        if (! $user instanceof User || ! is_numeric($id)) {
            return null;
        }

        return Learner::query()->where('account_user_id', $user->id)->find((int) $id);
    }

    /**
     * Explicit filters win; a chosen learner only fills the curriculum and
     * year group that were left empty (a learner without a curriculum fills nothing).
     */
    public function criteria(): TutorSearchCriteria
    {
        $learner = $this->ownLearner();

        return new TutorSearchCriteria(
            curriculumId: $this->filled('curriculum_id') ? $this->integer('curriculum_id') : $learner?->curriculum_id,
            subjectId: $this->filled('subject_id') ? $this->integer('subject_id') : null,
            yearGroup: $this->filled('year_group') ? $this->string('year_group')->toString() : $learner?->year_group,
            minRate: $this->filled('min_price') ? Money::fromDecimalString($this->string('min_price')->toString()) : null,
            maxRate: $this->filled('max_price') ? Money::fromDecimalString($this->string('max_price')->toString()) : null,
            day: $this->filled('day') ? $this->integer('day') : null,
            timeOfDay: $this->filled('time_of_day') ? $this->string('time_of_day')->toString() : null,
            minRating: $this->filled('min_rating') ? $this->string('min_rating')->toString() : null,
            sort: $this->filled('sort') ? $this->string('sort')->toString() : TutorSearchCriteria::SORT_RATING,
        );
    }
}
