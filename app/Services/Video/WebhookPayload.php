<?php

namespace App\Services\Video;

use App\Enums\VideoEventType;
use App\Enums\VideoParticipant;
use Carbon\CarbonImmutable;

/**
 * The attendance-event shape shared by Daily and the fake provider: `{id, type, event_ts,
 * payload: {room, user_id}}`, where the type is `participant.joined` or `participant.left` and
 * `user_id` is the participant id we put in the join token (`tutor` or `learner`).
 */
final class WebhookPayload
{
    public static function parse(string $body): ?AttendanceEvent
    {
        $data = json_decode($body, true);

        if (! is_array($data) || ! is_array($data['payload'] ?? null)) {
            return null;
        }

        $type = match ($data['type'] ?? null) {
            'participant.joined' => VideoEventType::Joined,
            'participant.left' => VideoEventType::Left,
            default => null,
        };

        $id = $data['id'] ?? null;
        $room = $data['payload']['room'] ?? null;

        $timestamp = $data['event_ts'] ?? null;

        if ($type === null || ! is_string($id) || $id === '' || ! is_string($room) || $room === '' || ! is_numeric($timestamp)) {
            return null;
        }

        $userId = $data['payload']['user_id'] ?? null;

        return new AttendanceEvent(
            $id,
            $type,
            $room,
            is_string($userId) ? VideoParticipant::tryFrom($userId) : null,
            CarbonImmutable::createFromTimestampUTC((int) $timestamp),
        );
    }
}
