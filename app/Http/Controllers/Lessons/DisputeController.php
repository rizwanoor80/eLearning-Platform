<?php

namespace App\Http\Controllers\Lessons;

use App\Actions\Lessons\OpenDispute;
use App\Enums\DisputeReason;
use App\Exceptions\DisputeException;
use App\Exceptions\LessonTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lessons\StoreDisputeRequest;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The account holder's dispute form for one lesson (CP8, R150). Authorised through
 * `LessonPolicy::openDispute` first, so a tutor, another family or a guest is a 404 before any
 * eligibility rule is looked at; `OpenDispute::problemFor` is the authority on whether the lesson
 * can be disputed now and re-checks on the locked row.
 */
class DisputeController extends Controller
{
    public function create(Request $request, Lesson $lesson): Response|RedirectResponse
    {
        Gate::authorize('openDispute', $lesson);

        $problem = OpenDispute::problemFor($this->user($request), $lesson);

        if ($problem !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $problem]);

            return to_route('lessons.show', $lesson);
        }

        $lesson->loadMissing(['tutorProfile.user:id,name', 'subject:id,name']);

        return Inertia::render('lessons/Dispute', [
            'lesson' => [
                'id' => $lesson->id,
                'subject' => $lesson->subject?->name,
                'tutor_display_name' => $lesson->tutorProfile->displayName(),
                'starts_at_label' => $lesson->starts_at->copy()->setTimezone($this->user($request)->timezone)->format('D, j M Y, g:i A'),
            ],
            'reasons' => DisputeReason::options(),
        ]);
    }

    public function store(StoreDisputeRequest $request, Lesson $lesson, OpenDispute $open): RedirectResponse
    {
        Gate::authorize('openDispute', $lesson);

        try {
            $open($this->user($request), $lesson, DisputeReason::from($request->validated('reason')), $request->validated('description'));
        } catch (DisputeException|LessonTransitionException $e) {
            // `LessonTransitionException` is the state machine's own edge-assert rejecting a
            // status this lesson can no longer move from (e.g. a stale tab resubmitting on an
            // already-disputed or since-settled lesson) — `problemFor()` inside the locked
            // transition catches the ordinary cases first, but the assert can still fire before
            // `$work` ever runs. Caught here the same way `CancelLessonController` catches it for
            // the same race, so a double-click flashes a toast instead of a 500.
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return to_route('lessons.show', $lesson);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your dispute has been opened. We will be in touch.')]);

        return to_route('lessons.show', $lesson);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
