<?php

namespace App\Http\Controllers\Safeguarding;

use App\Actions\Safeguarding\FileAbuseReport;
use App\Enums\AbuseReportReason;
use App\Exceptions\AbuseReportException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Safeguarding\StoreConversationAbuseReportRequest;
use App\Http\Requests\Safeguarding\StoreLessonAbuseReportRequest;
use App\Http\Requests\Safeguarding\StoreTutorAbuseReportRequest;
use App\Models\Conversation;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * CP7 8d (R137): three thin store endpoints — no separate create page, since the report
 * button opens a modal in place, not a full-page navigation. Each request class already
 * gates via the matching policy's `reportAbuse` before this runs (a non-party gets a 404,
 * never a 403), so the subject passed to `FileAbuseReport` here is always the one Laravel
 * itself resolved from the URL, never a client-supplied id.
 */
class AbuseReportController extends Controller
{
    public function storeForTutor(StoreTutorAbuseReportRequest $request, TutorProfile $tutor, FileAbuseReport $file): RedirectResponse
    {
        /** @var User $reporter */
        $reporter = $request->user();

        return $this->file($reporter, $tutor, $request->validated(), $file, to_route('tutors.show', $tutor));
    }

    public function storeForLesson(StoreLessonAbuseReportRequest $request, Lesson $lesson, FileAbuseReport $file): RedirectResponse
    {
        /** @var User $reporter */
        $reporter = $request->user();

        return $this->file($reporter, $lesson, $request->validated(), $file, to_route('lessons.show', $lesson));
    }

    public function storeForConversation(StoreConversationAbuseReportRequest $request, Conversation $conversation, FileAbuseReport $file): RedirectResponse
    {
        /** @var User $reporter */
        $reporter = $request->user();

        return $this->file($reporter, $conversation, $request->validated(), $file, to_route('messages.show', $conversation));
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function file(User $reporter, TutorProfile|Lesson|Conversation $subject, array $validated, FileAbuseReport $file, RedirectResponse $back): RedirectResponse
    {
        try {
            $file(
                $reporter,
                $subject,
                AbuseReportReason::from($validated['reason']),
                $validated['description'],
            );
        } catch (AbuseReportException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return $back;
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Report submitted. Our safeguarding team will review it.')]);

        return $back;
    }
}
