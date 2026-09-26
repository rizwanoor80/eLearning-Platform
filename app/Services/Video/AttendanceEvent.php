<?php

namespace App\Services\Video;

use App\Enums\VideoEventType;
use App\Enums\VideoParticipant;
use Carbon\CarbonImmutable;

/**
 * One join or leave, parsed from a verified webhook. `id` is the provider's event id, the replay
 * key. `participant` is null when the event's user id is not one of ours.
 */
final readonly class AttendanceEvent
{
    public function __construct(
        public string $id,
        public VideoEventType $type,
        public string $roomName,
        public ?VideoParticipant $participant,
        public CarbonImmutable $occurredAt,
    ) {}

    public function lessonId(): ?int
    {
        return VideoRoom::lessonIdFrom($this->roomName);
    }
}
