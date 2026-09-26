<?php

namespace App\Http\Requests\Lessons;

use App\Enums\LessonType;
use App\Enums\TrialSuitability;
use App\Models\Lesson;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The progress-report form (CP6 7e). Shape only: the five fields always, and the three trial fields
 * required exactly when the route lesson is a trial and prohibited when it is not — read from the lesson,
 * never from the client (invariant 12). `SubmitProgressReport` re-checks all of it on the locked row.
 */
class StoreProgressReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        /** @var Lesson $lesson */
        $lesson = $this->route('lesson');
        $isTrial = $lesson->type === LessonType::Trial;
        $trial = $isTrial ? 'required' : 'prohibited';

        return [
            'topics_covered' => ['required', 'string', 'max:3000'],
            'went_well' => ['required', 'string', 'max:3000'],
            'work_on_next' => ['required', 'string', 'max:3000'],
            'homework' => ['required', 'string', 'max:3000'],
            'engagement' => ['required', 'integer', 'between:1,5'],
            'trial_suitability' => [$trial, Rule::enum(TrialSuitability::class)],
            'trial_recommended_frequency' => [$trial, 'integer', 'between:1,3'],
            'trial_focus_areas' => [$trial, 'string', 'max:3000'],
        ];
    }
}
