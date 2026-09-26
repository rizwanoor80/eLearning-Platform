<?php

namespace App\Console\Commands;

use App\Actions\Video\CreateLessonRoom;
use App\Enums\LessonStatus;
use App\Models\Lesson;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class CreateLessonRooms extends Command
{
    protected $signature = 'lessons:create-rooms';

    protected $description = 'Create the video room for each confirmed lesson starting within the room lead time';

    /**
     * Confirmed lessons with no room yet, from T-15 min until the scheduled end. Idempotent under a
     * double or overlapping run: the provider returns the existing room for a name, and only the run
     * whose conditional UPDATE matches records it (`CreateLessonRoom`). A provider failure for one
     * lesson is reported and retried on the next run; it never stops the others.
     */
    public function handle(CreateLessonRoom $createLessonRoom): int
    {
        $now = Carbon::now();
        $created = 0;
        $failed = 0;

        Lesson::query()
            ->where('status', LessonStatus::Confirmed)
            ->whereNull('room_id')
            ->where('starts_at', '<=', $now->copy()->addMinutes((int) config('video.room_lead_minutes')))
            ->where('ends_at', '>', $now)
            ->lazyById()
            ->each(function (Lesson $lesson) use ($createLessonRoom, &$created, &$failed): void {
                try {
                    $created += $createLessonRoom($lesson) ? 1 : 0;
                } catch (Throwable $e) {
                    $failed++;
                    report($e);
                }
            });

        $this->info("Created {$created} room(s), {$failed} failed.");

        return self::SUCCESS;
    }
}
