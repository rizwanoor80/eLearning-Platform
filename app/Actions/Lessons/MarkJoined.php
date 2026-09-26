<?php

namespace App\Actions\Lessons;

use App\Actions\Video\IssueJoinToken;
use App\Exceptions\AttendanceException;
use App\Models\Lesson;
use App\Models\User;
use App\Models\VideoProvider;
use App\Services\Lessons\LessonParties;

/**
 * The manual "I've joined" (PRD §9): the fallback for a provider that sends no attendance
 * webhooks. When the lesson's own provider does send them, attendance comes only from the
 * provider — a party cannot assert it, so this refuses. Weaker evidence, by the PRD's own words.
 */
class MarkJoined
{
    public function __construct(private RecordAttendance $recordAttendance) {}

    /**
     * @throws AttendanceException
     */
    public function __invoke(User $actor, Lesson $lesson): bool
    {
        $participant = LessonParties::participantFor($lesson, $actor)
            ?? throw new AttendanceException('Only the tutor or the parent of this lesson may mark themselves as joined.');

        IssueJoinToken::assertJoinable($lesson);

        $provider = VideoProvider::query()->where('code', $lesson->room_provider)->first();

        if ($provider === null || $provider->supports_attendance_webhooks) {
            throw new AttendanceException('Attendance for this lesson is recorded automatically.');
        }

        return ($this->recordAttendance)($lesson, $participant, now());
    }
}
