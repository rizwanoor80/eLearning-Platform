<?php

namespace App\Listeners\Video;

use App\Actions\Lessons\RecordAttendance;
use App\Enums\VideoEventType;
use App\Enums\VideoParticipant;
use App\Events\Video\VideoWebhookReceived;
use App\Models\Lesson;
use App\Services\Video\VideoRoom;
use Illuminate\Support\Facades\Date;

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
 */
class ApplyAttendanceEvent
{
    public function __construct(private RecordAttendance $recordAttendance) {}

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

        ($this->recordAttendance)($lesson, $participant, Date::instance($event->occurred_at));
    }
}
