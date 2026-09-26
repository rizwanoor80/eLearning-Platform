<?php

namespace App\Console\Commands;

use App\Actions\Video\CloseLessonRoom;
use App\Models\Lesson;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class CloseLessonRooms extends Command
{
    protected $signature = 'lessons:close-rooms';

    protected $description = 'Close the video room of every lesson whose end plus the grace has passed';

    /**
     * Lessons in any status that have a room not yet closed: a lesson cancelled or refunded after its
     * room was made still has one to close. Closing goes to the provider recorded on the lesson,
     * never the currently active one. A provider failure leaves the lesson unmarked and is retried on
     * the next run.
     */
    public function handle(CloseLessonRoom $closeLessonRoom): int
    {
        $closed = 0;
        $failed = 0;

        Lesson::query()
            ->whereNotNull('room_id')
            ->whereNull('room_closed_at')
            ->where('ends_at', '<=', Carbon::now()->subMinutes((int) config('video.close_grace_minutes')))
            ->lazyById()
            ->each(function (Lesson $lesson) use ($closeLessonRoom, &$closed, &$failed): void {
                try {
                    $closed += $closeLessonRoom($lesson) ? 1 : 0;
                } catch (Throwable $e) {
                    $failed++;
                    report($e);
                }
            });

        $this->info("Closed {$closed} room(s), {$failed} failed.");

        return self::SUCCESS;
    }
}
