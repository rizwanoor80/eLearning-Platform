<?php

namespace App\Actions\Video;

use App\Models\Lesson;
use App\Services\Video\VideoProviderException;
use App\Services\Video\VideoProviderManager;

/**
 * Closes the lesson's room at the scheduled end plus the grace (PRD §9), through the provider that
 * made it (`room_provider`), whichever provider is active now. Closing is idempotent at the
 * provider, and the conditional UPDATE records it once; a provider failure leaves the lesson
 * unmarked so the next run retries.
 */
class CloseLessonRoom
{
    public function __construct(private VideoProviderManager $providers) {}

    /**
     * @return bool whether this call recorded the close
     *
     * @throws VideoProviderException
     */
    public function __invoke(Lesson $lesson): bool
    {
        if ($lesson->room_id === null || $lesson->room_provider === null || $lesson->room_closed_at !== null) {
            return false;
        }

        if ($lesson->ends_at->copy()->addMinutes((int) config('video.close_grace_minutes'))->isFuture()) {
            return false;
        }

        $this->providers->forCode($lesson->room_provider)->closeRoom($lesson->room_id);

        return Lesson::query()
            ->whereKey($lesson->id)
            ->whereNull('room_closed_at')
            ->update(['room_closed_at' => now()]) === 1;
    }
}
