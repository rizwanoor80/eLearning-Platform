<?php

namespace App\Listeners\Messaging;

use App\Actions\Messaging\EnsureConversation;
use App\Actions\Messaging\RecordFirstLessonCompleted;
use App\Enums\LessonStatus;
use App\Events\Lessons\LessonStatusChanged;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * R133: opens the conversation when a lesson between an account and a tutor becomes `confirmed`, or is
 * created `reserved` (a weekly lesson); an expired `pending_payment` opens none. When a lesson enters
 * `completed` it also stamps the pair's `first_lesson_completed_at`, and it ensures the conversation
 * itself first, so the order in which queued listeners run cannot matter. It runs whether or not
 * `features.messaging` is on: switching messaging off hides it, and keeps the data.
 */
class SyncConversationForLesson implements ShouldQueue
{
    public function __construct(
        private readonly EnsureConversation $ensureConversation,
        private readonly RecordFirstLessonCompleted $recordFirstLessonCompleted,
    ) {}

    public function handle(LessonStatusChanged $event): void
    {
        if ($event->to === LessonStatus::Completed) {
            ($this->recordFirstLessonCompleted)($event->lesson);

            return;
        }

        if ($event->to === LessonStatus::Confirmed || ($event->from === null && $event->to === LessonStatus::Reserved)) {
            ($this->ensureConversation)($event->lesson);
        }
    }
}
