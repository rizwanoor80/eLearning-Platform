<?php

namespace App\Console\Commands;

use App\Actions\Lessons\AutoReleaseLesson;
use App\Exceptions\LessonTransitionException;
use App\Models\Lesson;
use App\Support\Facades\Settings;
use Illuminate\Console\Command;
use Throwable;

class AutoReleaseReports extends Command
{
    protected $signature = 'lessons:auto-release-reports';

    protected $description = 'Release the escrow of completed lessons whose tutor filed no report by the 72 h deadline, and flag them late';

    /**
     * Each lesson runs in its own transaction (`AutoReleaseLesson`), and the state machine asserts the
     * edge on the locked row: a lesson a report or a dispute just moved is skipped, and a second run finds
     * nothing due. One lesson failing never blocks the rest.
     */
    public function handle(AutoReleaseLesson $release): int
    {
        $released = 0;

        AutoReleaseLesson::due(Lesson::query(), (int) Settings::get('auto_release_hours'))
            ->lazyById()
            ->each(function (Lesson $lesson) use ($release, &$released): void {
                try {
                    $release($lesson);
                    $released++;
                } catch (LessonTransitionException) {
                    return;
                } catch (Throwable $e) {
                    report($e);
                }
            });

        $this->info("Released {$released} unreported lesson(s).");

        return self::SUCCESS;
    }
}
