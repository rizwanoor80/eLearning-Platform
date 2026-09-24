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
     * Captured)` excludes it, but that is only a snapshot: `lazyById()` pages
     * through the query in small chunks, so time passes between reading a
     * row and locking it, and `BookLesson` can capture the payment in that
     * gap. `transition()`'s own `$work` closure runs after the row lock, on
     * the just-locked model, so it re-checks the payment there and refuses
     * the edge (R77 item 4) rather than trusting the now-stale snapshot.
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
            ->lazyById()
            ->each(function (Lesson $lesson) use (&$expired): void {
                try {
                    LessonStateMachine::transition($lesson, LessonStatus::Expired, function (Lesson $locked): void {
                        if ($locked->payment?->status === PaymentStatus::Captured) {
                            throw new LessonTransitionException(
                                "Lesson {$locked->id}'s payment captured between the sweep's query and its row lock; leaving it for BookLesson's own confirm (or ledger:verify, if that never lands)."
                            );
                        }
                    });
                    $expired++;
                } catch (LessonTransitionException) {
                    // Moved on (captured and confirmed, captured mid-sweep, or otherwise
                    // transitioned) between the query and the lock — nothing left to expire.
                }
            });

        $this->info("Expired {$expired} unpaid lesson(s).");

        return self::SUCCESS;
    }
}
