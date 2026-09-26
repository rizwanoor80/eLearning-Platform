<?php

namespace App\Services\Lessons;

use App\Actions\Lessons\MarkJoined;
use App\Actions\Lessons\MarkNoShow;
use App\Actions\Video\IssueJoinToken;
use App\Enums\LessonStatus;
use App\Enums\VideoParticipant;
use App\Exceptions\AttendanceException;
use App\Models\Lesson;
use App\Models\User;
use App\Models\VideoProvider;

/**
 * What the lesson page shows one party (CP6 7d). Every gate here is read from the same rule the
 * matching action enforces (`IssueJoinToken::assertJoinable`, `MarkJoined::problemFor`,
 * `MarkNoShow::problemFor`), so a button the page shows is one the action will accept — but the
 * action is still the authority and re-checks on the locked row. Only booleans about the provider
 * leave here, never the registry row; and the join token is never part of this: it is minted by
 * POST `lessons.room` at click time and lives only in the browser's memory.
 */
final class LessonRoomView
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Lesson $lesson, User $user): array
    {
        $party = LessonParties::participantFor($lesson, $user);
        $isTutor = $party === VideoParticipant::Tutor;
        $timezone = $user->timezone;

        $opensAt = $lesson->starts_at->copy()->subMinutes((int) config('video.join_lead_minutes'));
        $closesAt = $lesson->ends_at->copy()->addMinutes((int) config('video.close_grace_minutes'));

        // The lesson's own provider, never the active one (a lesson made on one provider is joined on it).
        $provider = $lesson->room_provider === null
            ? null
            : VideoProvider::query()->where('code', $lesson->room_provider)->first();

        $canJoin = false;
        $joinProblem = null;

        try {
            IssueJoinToken::assertJoinable($lesson);
            $canJoin = $provider !== null;
        } catch (AttendanceException $e) {
            $joinProblem = $e->getMessage();
        }

        $myJoinedAt = $isTutor ? $lesson->tutor_joined_at : $lesson->learner_joined_at;
        $otherJoinedAt = $isTutor ? $lesson->learner_joined_at : $lesson->tutor_joined_at;

        return [
            'id' => $lesson->id,
            'status' => $lesson->status->value,
            'side' => $isTutor ? 'tutor' : 'parent',
            'subject' => $lesson->subject?->name,
            'duration_minutes' => $lesson->duration_minutes,
            'learner_display_name' => $lesson->learner->display_name,
            'tutor_display_name' => $lesson->tutorProfile->displayName(),
            'starts_at_label' => $lesson->starts_at->copy()->setTimezone($timezone)->format('D, j M Y, g:i A'),
            'timezone' => $timezone,
            'starts_at' => $lesson->starts_at->copy()->utc()->toIso8601String(),
            'ends_at' => $lesson->ends_at->copy()->utc()->toIso8601String(),
            'join_opens_at' => $opensAt->copy()->utc()->toIso8601String(),
            'join_opens_label' => $opensAt->copy()->setTimezone($timezone)->format('g:i A'),
            'room_closes_at' => $closesAt->copy()->utc()->toIso8601String(),
            'room_ready' => $lesson->room_id !== null && $provider !== null,
            'window' => now()->lessThan($opensAt) ? 'before' : (now()->lessThan($closesAt) ? 'open' : 'after'),
            'embed' => $provider !== null && $provider->supports_embed,
            'can_join' => $canJoin,
            'join_problem' => $joinProblem,
            'can_mark_joined' => $myJoinedAt === null && MarkJoined::problemFor($user, $lesson) === null,
            'can_mark_no_show' => MarkNoShow::problemFor($user, $lesson) === null,
            'i_joined' => $myJoinedAt !== null,
            'other_joined' => $otherJoinedAt !== null,
            'other_role' => $isTutor ? 'student' : 'tutor',
            'no_show_outcome' => $isTutor ? 'pay_tutor' : 'refund_parent',
            'terminal' => ! in_array($lesson->status, [LessonStatus::Reserved, LessonStatus::Confirmed, LessonStatus::InProgress], true),
        ];
    }
}
