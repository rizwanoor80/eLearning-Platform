<?php

namespace App\Console\Commands;

use App\Enums\LessonStatus;
use App\Mail\Lessons\LessonReminderMail;
use App\Models\Lesson;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class SendLessonReminders extends Command
{
    protected $signature = 'lessons:send-reminders';

    protected $description = 'Queue the 24h and 1h pre-lesson reminder emails for confirmed lessons';

    /**
     * Windows are half-open — `(now, now+1h]` for the 1h reminder, `(now+1h, now+24h]`
     * for the 24h one — so a lesson starting at exactly the 1h mark fires once, from
     * one window, never both. A lesson booked with under 24h to go simply never
     * enters the 24h window at all; no special-casing needed beyond the windows
     * themselves (PLAN.md step 5's reminder-job scope; no PRD line covers this edge,
     * so the window predicate is the whole rule, logged as a DECISION in CYCLE-LOG).
     *
     * Idempotent under a double run, or an overlapping one slipping past
     * `onOneServer()->withoutOverlapping()` in routes/console.php: each row is
     * claimed with its own `UPDATE ... WHERE reminder_*_sent_at IS NULL`, so only
     * the run whose UPDATE actually matches the row queues its mail. The scheduler
     * guard prevents a second worker from starting; this claim is what makes a
     * second worker that starts anyway harmless.
     */
    public function handle(): int
    {
        $now = Carbon::now();

        $sent1h = $this->sendWindow('1h', 'reminder_1h_sent_at', $now, $now->copy()->addHour());
        $sent24h = $this->sendWindow('24h', 'reminder_24h_sent_at', $now->copy()->addHour(), $now->copy()->addDay());

        $this->info("Sent {$sent1h} 1h reminder(s), {$sent24h} 24h reminder(s).");

        return self::SUCCESS;
    }

    /**
     * @param  '1h'|'24h'  $window
     */
    private function sendWindow(string $window, string $column, Carbon $after, Carbon $upTo): int
    {
        $sent = 0;

        Lesson::query()
            ->where('status', LessonStatus::Confirmed)
            ->whereNull($column)
            ->where('starts_at', '>', $after)
            ->where('starts_at', '<=', $upTo)
            ->lazyById()
            ->each(function (Lesson $lesson) use ($window, $column, &$sent): void {
                $claimed = Lesson::query()
                    ->where('id', $lesson->id)
                    ->whereNull($column)
                    ->update([$column => now()]);

                if ($claimed !== 1) {
                    return;
                }

                Mail::to($lesson->learner->account)->send(new LessonReminderMail($lesson, $lesson->learner->account, $window));
                Mail::to($lesson->tutorProfile->user)->send(new LessonReminderMail($lesson, $lesson->tutorProfile->user, $window));
                $sent++;
            });

        return $sent;
    }
}
