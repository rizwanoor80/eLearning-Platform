<?php

namespace App\Console\Commands;

use App\Actions\RecurringSlots\ChargeReservedLesson;
use App\Enums\ChargeOutcome;
use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Services\Payments\PaymentGateway;
use Illuminate\Console\Command;
use Throwable;

class ChargeRecurringLessons extends Command
{
    protected $signature = 'recurring:charge';

    protected $description = 'Charge the saved card for each weekly lesson that is due, retry failures, and cancel weekly lessons whose start has passed uncharged';

    /**
     * Safe to run twice (invariant 14, R101): each attempt is a `payments` row unique on
     * `(lesson_id, attempt_no)` whose gateway idempotency key is `lesson:{id}:attempt:{n}`, and
     * `charge_attempts` / `next_charge_at` move only when an attempt's result is recorded — see
     * `ChargeReservedLesson`. One lesson that throws is reported and the rest still run.
     *
     * No `PaymentGateway` is bound outside tests until CP5 (invariant 16, the 3c decision): the
     * command then charges nothing, says so and exits 0, but still cancels lessons whose start has
     * passed, because that never touches a gateway.
     */
    public function handle(ChargeReservedLesson $charge): int
    {
        $gateway = app()->bound(PaymentGateway::class) ? app(PaymentGateway::class) : null;

        if ($gateway === null) {
            $this->warn('No payment gateway is configured: nothing will be charged.');
        }

        $counts = array_fill_keys(array_map(fn (ChargeOutcome $outcome): string => $outcome->value, ChargeOutcome::cases()), 0);
        $failed = 0;

        Lesson::query()
            ->where('status', LessonStatus::Reserved)
            ->whereNotNull('recurring_slot_id')
            ->where(fn ($due) => $due->where('next_charge_at', '<=', now())->orWhere('starts_at', '<=', now()))
            ->orderBy('starts_at')
            ->pluck('id')
            ->each(function (int $id) use ($charge, $gateway, &$counts, &$failed): void {
                try {
                    $counts[$charge($id, $gateway)->value]++;
                } catch (Throwable $e) {
                    $failed++;
                    $this->error("Lesson {$id} failed: {$e->getMessage()}");
                    report($e);
                }
            });

        $this->info(sprintf(
            'Charged %d, retry scheduled %d, cancelled %d, missed %d, tutor unavailable %d, skipped %d, needs review %d, %d lesson(s) errored.',
            $counts[ChargeOutcome::Charged->value],
            $counts[ChargeOutcome::RetryScheduled->value],
            $counts[ChargeOutcome::Cancelled->value],
            $counts[ChargeOutcome::Missed->value],
            $counts[ChargeOutcome::TutorUnavailable->value],
            $counts[ChargeOutcome::Skipped->value],
            $counts[ChargeOutcome::NeedsReview->value],
            $failed,
        ));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
