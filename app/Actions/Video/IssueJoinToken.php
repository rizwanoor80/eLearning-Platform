<?php

namespace App\Actions\Video;

use App\Enums\LessonStatus;
use App\Enums\TutorProfileStatus;
use App\Enums\VideoParticipant;
use App\Exceptions\AttendanceException;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Lessons\LessonParties;
use App\Services\Video\VideoProviderException;
use App\Services\Video\VideoProviderManager;
use App\Services\Video\VideoRoom;

/**
 * Mints a join token for the caller's own side of the lesson, on request, from T-10 min until the
 * room closes (PRD §9). Tokens are never stored: not in `*_join_url`, not anywhere. The provider is
 * the lesson's own (`room_provider`). The learner's token is issued to the account holder, who
 * joins on the learner's behalf.
 */
class IssueJoinToken
{
    public function __construct(private VideoProviderManager $providers) {}

    /**
     * @return array{url: string, token: string, participant: VideoParticipant}
     *
     * @throws AttendanceException
     * @throws VideoProviderException
     */
    public function __invoke(User $actor, Lesson $lesson): array
    {
        $participant = LessonParties::participantFor($lesson, $actor)
            ?? throw new AttendanceException('Only the tutor or the parent of this lesson may join it.');

        self::assertJoinable($lesson);
        self::assertParticipantJoinable($lesson, $participant);

        $provider = $this->providers->forCode((string) $lesson->room_provider);
        $expiresAt = $lesson->ends_at->copy()->addMinutes((int) config('video.close_grace_minutes'));

        // Creating a room that exists returns it (the interface's contract), which is how the URL is
        // recovered without storing it.
        $room = $provider->createRoom(VideoRoom::nameFor($lesson->id), $expiresAt);
        $name = $participant === VideoParticipant::Tutor ? $lesson->tutorProfile->user->name : $lesson->learner->display_name;

        return [
            'url' => $room->url,
            'token' => $provider->joinToken($room->name, $participant, $name, $expiresAt),
            'participant' => $participant,
        ];
    }

    /**
     * The state and the clock a join needs, shared with the manual "I've joined".
     *
     * @throws AttendanceException
     */
    public static function assertJoinable(Lesson $lesson): void
    {
        if (! in_array($lesson->status, [LessonStatus::Confirmed, LessonStatus::InProgress], true)) {
            throw new AttendanceException('This lesson is not open for joining.');
        }

        if ($lesson->room_id === null || $lesson->room_provider === null) {
            throw new AttendanceException('The room for this lesson is not ready yet.');
        }

        if (now()->lessThan($lesson->starts_at->copy()->subMinutes((int) config('video.join_lead_minutes')))) {
            throw new AttendanceException('The room opens '.config('video.join_lead_minutes').' minutes before the lesson.');
        }

        if (now()->greaterThanOrEqualTo($lesson->ends_at->copy()->addMinutes((int) config('video.close_grace_minutes')))) {
            throw new AttendanceException('The room for this lesson has closed.');
        }
    }

    /**
     * R138: "join tokens refused" for a suspended tutor — including a lesson already `InProgress`,
     * which the suspension sweep (`CancelSuspendedTutorLessons`) never reaches, because
     * `LessonStateMachine` has no edge out of `InProgress` (R138/8d review round 1, FAIL #2:
     * without this, a suspended tutor could reload and rejoin a live room for the rest of its
     * window). Deliberately scoped to the tutor's own side only, never the lesson as a whole: the
     * account-holder side of a suspension already ends that party's session on every `lessons.*`
     * route via `EnsureAccountActive` (not one of its excluded prefixes), and a lesson-level
     * refusal here would also block the innocent other party — for a tutor suspension that would
     * strand the parent's own join/no-show path over an event the parent had nothing to do with,
     * and could even manufacture a no-show-student payout to a tutor who is being investigated
     * (invariant 13: refund-to-parent and pay-to-tutor stay independent decisions, never each
     * other's side effect). The message matches `assertJoinable`'s own generic wording so a
     * reported tutor cannot infer their status from it (round-1 review risk area #1).
     *
     * @throws AttendanceException
     */
    public static function assertParticipantJoinable(Lesson $lesson, ?VideoParticipant $participant): void
    {
        if ($participant === VideoParticipant::Tutor && $lesson->tutorProfile->status === TutorProfileStatus::Suspended) {
            throw new AttendanceException('This lesson is not open for joining.');
        }
    }
}
