<?php

namespace App\Http\Controllers\Tutor;

use App\Actions\RecurringSlots\EndRecurringSlot;
use App\Enums\LessonStatus;
use App\Enums\RecurringSlotStatus;
use App\Exceptions\RecurringSlotException;
use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\RecurringSlot;
use App\Models\User;
use App\Support\Facades\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tutor ends a weekly slot with notice (R99): the slot runs through the last notice day and
 * the learner's later reserved lessons are released. The rules live in `EndRecurringSlot`.
 */
class TutorWeeklySlotController extends Controller
{
    public function endShow(Request $request, RecurringSlot $slot): Response
    {
        Gate::authorize('end', $slot);

        $slot->loadMissing(['learner', 'subject:id,name']);

        $noticeDays = (int) Settings::get('recurring_tutor_end_notice_days');
        $lastDay = $slot->end_effective_on !== null && $slot->ended_by_user_id !== null
            ? CarbonImmutable::instance($slot->end_effective_on)
            : CarbonImmutable::now($slot->timezone)->startOfDay()->addDays($noticeDays);

        $released = Lesson::query()
            ->where('recurring_slot_id', $slot->id)
            ->where('status', LessonStatus::Reserved)
            ->where('starts_at', '>=', CarbonImmutable::createFromFormat('!Y-m-d', $lastDay->toDateString(), $slot->timezone)->addDay()->utc())
            ->where('starts_at', '>', now())
            ->count();

        return Inertia::render('tutor/EndWeeklySlot', [
            'weekly_slot' => [
                'id' => $slot->id,
                'ended' => $slot->status === RecurringSlotStatus::Ended,
                'notice_given' => $slot->end_effective_on !== null && $slot->ended_by_user_id !== null,
                'learner' => $slot->learner->display_name,
                'subject' => $slot->subject?->name,
                'schedule' => $slot->scheduleLabel(),
            ],
            'notice_days' => $noticeDays,
            'last_day' => $lastDay->format('D, j M Y'),
            'released_count' => $released,
        ]);
    }

    public function end(Request $request, RecurringSlot $slot, EndRecurringSlot $end): RedirectResponse
    {
        Gate::authorize('end', $slot);

        /** @var User $user */
        $user = $request->user();

        try {
            $end($user, $slot);
        } catch (RecurringSlotException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return to_route('tutor.dashboard');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notice given. The weekly slot ends on its last day.')]);

        return to_route('tutor.dashboard');
    }
}
