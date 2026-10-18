<?php

namespace App\Listeners\Video;

use App\Actions\Lessons\RecordAttendance;
use App\Actions\RecordAuditLog;
use App\Enums\VideoEventType;
use App\Enums\VideoParticipant;
use App\Events\Video\VideoWebhookReceived;
use App\Models\Lesson;
use App\Services\Video\AccountSuspensions;
use App\Services\Video\VideoRoom;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

/**
 * Turns a stored attendance webhook into a join time. Deliberately synchronous — not queued, not
 * `afterCommit`, no `SerializesModels`: it runs inside the webhook controller's transaction, so if
 * it cannot run the dispatch throws, the stored event rolls back and the provider's retry is not
 * answered `duplicate` (see `VideoWebhookReceived`).
 *
 * Room names are `lesson-<id>` for every provider, so an event is applied only when the lesson's
 * own `room_provider` is the provider that sent it and the lesson's room is the one named. Anything
 * else — an unknown room, another provider's event, a participant that is not ours, a `left` —
 * returns without effect and without an exception (an exception would make the provider retry
 * something that can never apply).
 *
 * R232: a join whose participant was suspended at the event's own timestamp is ignored (logged and audited as
 * `attendance.ignored_suspended`), so a suspended party cannot start the clock that pays a lesson; a join stamped
 * before the suspension stands, and so does one delivered late. Attendance is the only thing decided here — the
 * settlement and no-show paths are untouched.
 */
class ApplyAttendanceEvent
{
    public function __construct(
        private RecordAttendance $recordAttendance,
        private RecordAuditLog $recordAuditLog,
    ) {}

    public function handle(VideoWebhookReceived $received): void
    {
        $event = $received->event;

        if ($event->type !== VideoEventType::Joined->value) {
            return;
        }

        $participant = VideoParticipant::tryFrom((string) $event->participant);
        $lessonId = VideoRoom::lessonIdFrom($event->room_name);

        if ($participant === null || $lessonId === null) {
            return;
        }

        $lesson = Lesson::query()->find($lessonId);

        if ($lesson === null || $lesson->room_provider !== $event->provider_code || $lesson->room_id !== $event->room_name) {
            return;
        }

        $occurredAt = Date::instance($event->occurred_at);
        $userId = $participant === VideoParticipant::Tutor
            ? $lesson->tutorProfile?->user_id
            : $lesson->learner?->account_user_id;

        if ($userId !== null && AccountSuspensions::wasSuspendedAt((int) $userId, $occurredAt)) {
            Log::info('Attendance join ignored: participant was suspended.', ['lesson_id' => $lesson->id, 'participant' => $participant->value]);
            ($this->recordAuditLog)(null, 'attendance.ignored_suspended', $lesson, null, [
                'participant' => $participant->value,
                'user_id' => (int) $userId,
                'occurred_at' => $occurredAt->toIso8601String(),
            ]);

            return;
        }

        ($this->recordAttendance)($lesson, $participant, $occurredAt);
    }
}
