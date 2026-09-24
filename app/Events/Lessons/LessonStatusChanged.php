<?php

namespace App\Events\Lessons;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by `LessonStateMachine` for every edge, after the transaction commits
 * (R51). `$from` is null when the lesson was just created. Side effects
 * (emails, room creation, reminders) hang off this as queued listeners.
 * `SerializesModels` added alongside 3e's first queued listeners on this
 * event, matching the Tutor events' sibling pattern.
 */
class LessonStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Lesson $lesson,
        public readonly ?LessonStatus $from,
        public readonly LessonStatus $to,
    ) {}
}
