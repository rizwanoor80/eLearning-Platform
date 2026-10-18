<?php

namespace App\Actions\Video;

use App\Actions\RecordAuditLog;
use App\Enums\UserStatus;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Video\VideoProviderManager;
use Throwable;

/**
 * R232: closes every still-open provider room of a suspended account — as the tutor, or as the account holder of a
 * learner — so a connected call ends and a join token already issued opens nothing. The room is closed through the
 * provider recorded on the lesson (never the active one) and `room_closed_at` is set, which also stops
 * `IssueJoinToken` re-creating it for the other party.
 *
 * Deliberately not filtered by lesson status: `CancelSuspendedTutorLessons` runs first and cancels confirmed
 * lessons, whose rooms (open from fifteen minutes before the start) would otherwise stay live with the innocent
 * party in them. The window is `CloseLessonRooms`' own: a room is open until the end plus the grace. The audit row
 * is written only when this call wins the conditional UPDATE, so a retried job audits each lesson once. The provider is
 * closed before the row is marked (a crash between the two is retried; the reverse order would never retry), so a token
 * issued in that instant can recreate the room, which then lapses at its own `exp` (the end plus the grace). Idempotent
 * and safe to run twice; a reinstated account is left alone.
 */
class CloseSuspendedAccountRooms
{
    public function __construct(
        private readonly VideoProviderManager $providers,
        private readonly RecordAuditLog $recordAuditLog,
    ) {}

    /**
     * @return array{closed: int, failed: int}
     */
    public function __invoke(User $account, ?User $actor = null): array
    {
        $fresh = User::query()->find($account->id);

        if ($fresh === null || $fresh->status !== UserStatus::Suspended) {
            return ['closed' => 0, 'failed' => 0];
        }

        $closed = 0;
        $failed = 0;

        Lesson::query()
            ->whereNotNull('room_id')
            ->whereNotNull('room_provider')
            ->whereNull('room_closed_at')
            ->where('ends_at', '>', now()->subMinutes((int) config('video.close_grace_minutes')))
            ->where(function ($query) use ($fresh): void {
                $query->whereIn('tutor_profile_id', TutorProfile::query()->where('user_id', $fresh->id)->select('id'))
                    ->orWhereIn('learner_id', Learner::withTrashed()->where('account_user_id', $fresh->id)->select('id'));
            })
            ->lazyById()
            ->each(function (Lesson $lesson) use ($fresh, $actor, &$closed, &$failed): void {
                try {
                    $this->providers->forCode((string) $lesson->room_provider)->closeRoom((string) $lesson->room_id);

                    $won = Lesson::query()
                        ->whereKey($lesson->id)
                        ->whereNull('room_closed_at')
                        ->update(['room_closed_at' => now()]) === 1;

                    if ($won) {
                        ($this->recordAuditLog)($actor, 'lesson.room_closed_suspension', $lesson, null, ['account_user_id' => $fresh->id]);
                        $closed++;
                    }
                } catch (Throwable $e) {
                    report($e);
                    $failed++;
                }
            });

        return ['closed' => $closed, 'failed' => $failed];
    }
}
