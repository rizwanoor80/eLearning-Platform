<?php

namespace App\Http\Controllers\Reviews;

use App\Actions\Reviews\SubmitReview;
use App\Exceptions\ReviewException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reviews\StoreReviewRequest;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The account holder's review form for one lesson (CP7 8c, R136). Authorised through
 * `LessonPolicy::review` first, so a tutor, another family or a guest is a 404 before any
 * eligibility rule is looked at; `SubmitReview::eligibilityProblem` is the authority on whether the
 * lesson can be reviewed now and re-checks on the locked row.
 */
class ReviewController extends Controller
{
    public function create(Request $request, Lesson $lesson): Response|RedirectResponse
    {
        Gate::authorize('review', $lesson);

        $problem = SubmitReview::eligibilityProblem($this->user($request), $lesson);

        if ($problem !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $problem]);

            return to_route('lessons.show', $lesson);
        }

        $lesson->loadMissing(['tutorProfile.user:id,name', 'subject:id,name']);

        return Inertia::render('lessons/Review', [
            'lesson' => [
                'id' => $lesson->id,
                'subject' => $lesson->subject?->name,
                'tutor_display_name' => $lesson->tutorProfile->displayName(),
                'starts_at_label' => $lesson->starts_at->copy()->setTimezone($this->user($request)->timezone)->format('D, j M Y, g:i A'),
            ],
        ]);
    }

    public function store(StoreReviewRequest $request, Lesson $lesson, SubmitReview $submit): RedirectResponse
    {
        Gate::authorize('review', $lesson);

        try {
            $submit($this->user($request), $lesson, (int) $request->validated('rating'), $request->validated('comment'));
        } catch (ReviewException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return to_route('lessons.show', $lesson);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Thank you for your review.')]);

        return to_route('lessons.show', $lesson);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
