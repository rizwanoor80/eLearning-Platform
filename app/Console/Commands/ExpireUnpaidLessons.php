<?php

namespace App\Console\Commands;

use App\Enums\LessonStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\LessonTransitionException;
use App\Models\Lesson;
use App\Services\Lessons\LessonStateMachine;
use Illuminate\Console\Command;

class ExpireUnpaidLessons extends Command
{
    /**
     * A lesson stays biddable on its slot for at most this long without a
     * captured payment (PLAN.md step 3c).
     */
    public const UNPAID_TIMEOUT_MINUTES = 15;

    protected $signature = 'lessons:expire-unpaid';

    protected $description = 'Expire pending_payment lessons that have had no captured payment for '.self::UNPAID_TIMEOUT_MINUTES.' minutes';

    /**
     * The predicate is "unpaid", not merely "old and pending_payment": a
     * lesson whose payment already captured but whose `confirmed` transition
     * hasn't landed yet (BookLesson's capture-then-confirm gap) must never be
     * swept out from under captured money — that would create exactly the
     * captured-with-no-HOLD state the race-condition test documents, with no
     * record beyond an exception message. `whereDoesntHave('payment', ...
     * Captured)` excludes it.
     *
     * Idempotent: expiring a lesson removes it from the query's own
     * `pending_payment` filter, so a second run (or an overlapping one,
     * guarded by `onOneServer()->withoutOverlapping()` in routes/console.php)
     * finds nothing left to do.
     */
    public function handle(): int
    {
        $cutoff = now()->subMinutes(self::UNPAID_TIMEOUT_MINUTES);
        $expired = 0;

        Lesson::query()
            ->where('status', LessonStatus::PendingPayment)
            ->where('created_at', '<=', $cutoff)
            ->whereDoesntHave('payment', fn ($query) => $query->where('status', PaymentStatus::Captured))
            ->each(function (Lesson $lesson) use (&$expired): void {
                try {
                    LessonStateMachine::transition($lesson, LessonStatus::Expired);
                    $expired++;
                } catch (LessonTransitionException) {
                    // Moved on (captured and confirmed, or otherwise transitioned) between the
                    // query and the lock — nothing left to expire.
                }
            });

        $this->info("Expired {$expired} unpaid lesson(s).");

        return self::SUCCESS;
    }
}
