<?php

namespace App\Http\Controllers\Lessons;

use App\Actions\Lessons\SubmitProgressReport;
use App\Enums\LessonType;
use App\Enums\TrialSuitability;
use App\Exceptions\LessonTransitionException;
use App\Exceptions\ProgressReportException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lessons\StoreProgressReportRequest;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tutor's report form (CP6 7e). Authorised through `LessonPolicy::report` first, so another tutor, a
 * parent or an admin is a 403 before any rule is looked at; `SubmitProgressReport` is the authority on
 * whether the lesson can take a report now and re-checks on the locked row.
 */
class ProgressReportController extends Controller
{
    public function create(Request $request, Lesson $lesson): Response|RedirectResponse
    {
        Gate::authorize('report', $lesson);

        $problem = SubmitProgressReport::problemFor($this->user($request), $lesson);

        if ($problem !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $problem]);

            return to_route('lessons.show', $lesson);
        }

        $lesson->loadMissing(['learner', 'subject:id,name']);

        return Inertia::render('lessons/Report', [
            'lesson' => [
                'id' => $lesson->id,
                'subject' => $lesson->subject?->name,
                'learner_display_name' => $lesson->learner->display_name,
                'starts_at_label' => $lesson->starts_at->copy()->setTimezone($this->user($request)->timezone)->format('D, j M Y, g:i A'),
                'timezone' => $this->user($request)->timezone,
                'is_trial' => $lesson->type === LessonType::Trial,
                'released_already' => $lesson->report_late_at !== null,
            ],
            'suitabilityOptions' => array_map(fn (TrialSuitability $s): array => ['value' => $s->value, 'label' => $s->label()], TrialSuitability::cases()),
        ]);
    }

    public function store(StoreProgressReportRequest $request, Lesson $lesson, SubmitProgressReport $submit): RedirectResponse
    {
        Gate::authorize('report', $lesson);

        try {
            $submit($this->user($request), $lesson, $request->validated());
        } catch (ProgressReportException|LessonTransitionException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return to_route('lessons.show', $lesson);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Report submitted.')]);

        return to_route('lessons.show', $lesson);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
