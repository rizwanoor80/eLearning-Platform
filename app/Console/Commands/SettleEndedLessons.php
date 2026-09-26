<?php

namespace App\Console\Commands;

use App\Actions\Lessons\SettleEndedLesson;
use App\Enums\LessonStatus;
use App\Exceptions\LessonTransitionException;
use App\Models\Lesson;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class SettleEndedLessons extends Command
{
    protected $signature = 'lessons:settle-ended';

    protected $description = 'Complete in-progress lessons both sides attended; refund confirmed lessons nobody attended';

    /**
     * Candidates: `in_progress` past the scheduled end, and `confirmed` past the end plus the room
     * grace. Each lesson runs in its own transaction (`SettleEndedLesson`), and the state machine
     * asserts the edge on the locked row, so a lesson another action just moved is skipped, and a
     * second run finds nothing to do. One lesson failing never blocks the rest.
     */
    public function handle(SettleEndedLesson $settle): int
    {
        $now = Carbon::now();
        $done = ['completed' => 0, 'refunded' => 0];

        Lesson::query()
            ->where(function ($query) use ($now): void {
                $query->where(fn ($q) => $q->where('status', LessonStatus::InProgress)->where('ends_at', '<=', $now))
                    ->orWhere(fn ($q) => $q->where('status', LessonStatus::Confirmed)
                        ->where('ends_at', '<=', $now->copy()->subMinutes((int) config('video.close_grace_minutes'))));
            })
            ->lazyById()
            ->each(function (Lesson $lesson) use ($settle, &$done): void {
                try {
                    $to = $settle($lesson);
                } catch (LessonTransitionException) {
                    return;
                } catch (Throwable $e) {
                    report($e);

                    return;
                }

                match ($to) {
                    LessonStatus::Completed => $done['completed']++,
                    LessonStatus::Refunded => $done['refunded']++,
                    default => null,
                };
            });

        $this->info("Completed {$done['completed']}, refunded {$done['refunded']}.");

        return self::SUCCESS;
    }
}
