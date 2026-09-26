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
        $problem = self::problemFor($actor, $lesson);

        if ($problem !== null) {
            throw new AttendanceException($problem);
        }

        $participant = LessonParties::participantFor($lesson, $actor);

        return ($this->recordAttendance)($lesson, $participant, now());
    }

    /**
     * Why `$actor` cannot say "I've joined" right now, or null when they can. The lesson page reads
     * this to show the button; `__invoke` refuses on the same rule, so the page is a convenience and
     * never the authority.
     */
    public static function problemFor(User $actor, Lesson $lesson): ?string
    {
        if (LessonParties::participantFor($lesson, $actor) === null) {
            return 'Only the tutor or the parent of this lesson may mark themselves as joined.';
        }

        try {
            IssueJoinToken::assertJoinable($lesson);
        } catch (AttendanceException $e) {
            return $e->getMessage();
        }

        $provider = VideoProvider::query()->where('code', $lesson->room_provider)->first();

        if ($provider === null || $provider->supports_attendance_webhooks) {
            return 'Attendance for this lesson is recorded automatically.';
        }

        if (now()->greaterThanOrEqualTo($lesson->ends_at)) {
            return 'The scheduled time for this lesson is over, so a join can no longer be recorded.';
        }

        return null;
    }
}
