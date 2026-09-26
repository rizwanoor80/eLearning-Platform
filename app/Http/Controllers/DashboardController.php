<?php

namespace App\Http\Controllers;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $lessons = Lesson::query()
            ->whereHas('learner', fn ($query) => $query->where('account_user_id', $user->id))
            ->whereIn('status', [LessonStatus::Reserved, LessonStatus::Confirmed, LessonStatus::InProgress])
            ->with(['learner', 'tutorProfile.user', 'subject:id,name'])
            ->orderBy('starts_at')
            ->get();

        return Inertia::render('Dashboard', [
            'upcoming' => $lessons->map(fn (Lesson $lesson): array => $this->present($lesson, $user))->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Lesson $lesson, User $user): array
    {
        return [
            'id' => $lesson->id,
            'starts_at' => $lesson->starts_at->setTimezone($user->timezone)->format('D, j M Y, g:i A'),
            'duration_minutes' => $lesson->duration_minutes,
            'status' => $lesson->status->value,
            'subject' => $lesson->subject?->name,
            'learner_display_name' => $lesson->learner->display_name,
            'tutor_display_name' => $lesson->tutorProfile->displayName(),
            'price' => $lesson->price->format(),
            'weekly' => $lesson->recurring_slot_id !== null,
            'cancel_window_hours' => $lesson->cancel_window_hours,
            'cancel_kind' => match ($lesson->status) {
                LessonStatus::Reserved => 'skip',
                LessonStatus::Confirmed => 'cancel',
                default => null,
            },
        ];
    }
}
