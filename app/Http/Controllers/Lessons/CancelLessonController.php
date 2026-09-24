<?php

namespace App\Http\Controllers\Lessons;

use App\Actions\Lessons\CancelLesson;
use App\Actions\Lessons\SkipLesson;
use App\Enums\LessonStatus;
use App\Exceptions\CancellationException;
use App\Exceptions\LessonTransitionException;
use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * First HTTP entry point for CancelLesson/SkipLesson (PLAN.md step 5).
 * Parent-only (LessonPolicy::cancel) — no tutor-facing cancel route exists
 * in this slice.
 */
class CancelLessonController extends Controller
{
    public function __invoke(Request $request, Lesson $lesson, CancelLesson $cancelLesson, SkipLesson $skipLesson): RedirectResponse
    {
        Gate::authorize('cancel', $lesson);

        /** @var User $user */
        $user = $request->user();

        $action = match ($lesson->status) {
            LessonStatus::Reserved => $skipLesson,
            LessonStatus::Confirmed => $cancelLesson,
            default => null,
        };

        if ($action === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This lesson can no longer be cancelled.')]);

            return to_route('dashboard');
        }

        try {
            $action($user, $lesson);
        } catch (CancellationException|LessonTransitionException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return to_route('dashboard');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Lesson cancelled.')]);

        return to_route('dashboard');
    }
}
