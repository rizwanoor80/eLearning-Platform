<?php

namespace App\Http\Controllers\Lessons;

use App\Actions\Lessons\MarkJoined;
use App\Actions\Lessons\MarkNoShow;
use App\Actions\Video\IssueJoinToken;
use App\Exceptions\AttendanceException;
use App\Exceptions\LessonTransitionException;
use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Video\VideoProviderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The three room actions a lesson's own tutor or parent can take (CP6 7c): get a join token, say
 * "I've joined" (only where the provider sends no attendance webhooks), and mark the other side
 * absent. Each authorises through `LessonPolicy::attend` first, so another parent's lesson is a 403
 * before any rule is looked at. The lesson page that calls these is 7d's.
 */
class LessonRoomController extends Controller
{
    public function join(Request $request, Lesson $lesson, IssueJoinToken $issueJoinToken): JsonResponse
    {
        Gate::authorize('attend', $lesson);

        try {
            $join = $issueJoinToken($this->user($request), $lesson);
        } catch (AttendanceException|VideoProviderException $e) {
            return response()->json(['message' => $e instanceof AttendanceException ? $e->getMessage() : __('The video room is unavailable right now. Please try again in a moment.')], 422);
        }

        return response()->json(['url' => $join['url'], 'token' => $join['token'], 'participant' => $join['participant']->value]);
    }

    public function joined(Request $request, Lesson $lesson, MarkJoined $markJoined): RedirectResponse
    {
        Gate::authorize('attend', $lesson);

        return $this->respond(fn () => $markJoined($this->user($request), $lesson), __('Marked as joined.'));
    }

    public function noShow(Request $request, Lesson $lesson, MarkNoShow $markNoShow): RedirectResponse
    {
        Gate::authorize('attend', $lesson);

        return $this->respond(fn () => $markNoShow($this->user($request), $lesson), __('No-show recorded.'));
    }

    private function respond(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (AttendanceException|LessonTransitionException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $success]);

        return back();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
