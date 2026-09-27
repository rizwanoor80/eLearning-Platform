<?php

namespace App\Actions\Messaging;

use App\Models\Lesson;

/**
 * R133: the pair's first lesson has been held, so contact details stop being masked from now on.
 * Only the first completion writes (`whereNull`), so a second completed lesson, a replay of the
 * listener or a concurrent run all leave the first date in place. Messages already stored stay masked.
 */
class RecordFirstLessonCompleted
{
    public function __construct(private readonly EnsureConversation $ensureConversation) {}

    public function __invoke(Lesson $lesson): void
    {
        $conversation = ($this->ensureConversation)($lesson);

        $conversation->newQuery()
            ->whereKey($conversation->id)
            ->whereNull('first_lesson_completed_at')
            ->update(['first_lesson_completed_at' => $lesson->completed_at ?? now(), 'updated_at' => now()]);
    }
}
