<?php

namespace App\Actions\Video;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Services\Video\NoActiveVideoProvider;
use App\Services\Video\VideoProviderException;
use App\Services\Video\VideoProviderManager;
use App\Services\Video\VideoRoom;

/**
 * Creates the lesson's room once (PRD §9). The provider call comes first and is idempotent by room
 * name (`lesson-<id>`), so a repeat or a concurrent run gets the same room back; only the run whose
 * conditional UPDATE matches (`room_id IS NULL`) records it, so exactly one row change per lesson.
 * The provider is the active one at this moment and is stored on the lesson — every later call for
 * this lesson (close, tokens, webhooks) uses that stored code, never the then-active provider.
 */
class CreateLessonRoom
{
    public function __construct(private VideoProviderManager $providers) {}

    /**
     * @return bool whether this call recorded the room
     *
     * @throws NoActiveVideoProvider
     * @throws VideoProviderException
     */
    public function __invoke(Lesson $lesson): bool
    {
        if ($lesson->status !== LessonStatus::Confirmed || $lesson->room_id !== null) {
            return false;
        }

        $row = $this->providers->activeRow() ?? throw new NoActiveVideoProvider('No video provider is active. An admin must activate one under Video providers.');
        $room = $this->providers->forRow($row)->createRoom(
            VideoRoom::nameFor($lesson->id),
            $lesson->ends_at->copy()->addMinutes((int) config('video.close_grace_minutes')),
        );

        return Lesson::query()
            ->whereKey($lesson->id)
            ->where('status', LessonStatus::Confirmed)
            ->whereNull('room_id')
            ->update(['room_provider' => $row->code, 'room_id' => $room->name, 'room_created_at' => now()]) === 1;
    }
}
