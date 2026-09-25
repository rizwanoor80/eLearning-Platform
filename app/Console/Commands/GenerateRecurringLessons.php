<?php

namespace App\Console\Commands;

use App\Actions\RecurringSlots\GenerateSlotLessons;
use App\Actions\RecurringSlots\SendSlotSkipNotices;
use App\Models\RecurringSlot;
use Illuminate\Console\Command;
use Throwable;

class GenerateRecurringLessons extends Command
{
    protected $signature = 'recurring:generate';

    protected $description = 'Extend every active weekly slot to the generation horizon, end slots whose end date has passed, and email skipped dates';

    /**
     * Safe to run twice (invariant 14, R98): see `GenerateSlotLessons`. A slot that throws is
     * reported and the rest still run; the exit code says something failed.
     */
    public function handle(GenerateSlotLessons $generate, SendSlotSkipNotices $notify): int
    {
        $created = 0;
        $skipped = 0;
        $ended = 0;
        $failed = 0;

        RecurringSlot::query()
            ->whereIn('status', RecurringSlot::holdingStatuses())
            ->lazyById()
            ->each(function (RecurringSlot $slot) use ($generate, &$created, &$skipped, &$ended, &$failed): void {
                try {
                    $result = $generate($slot);
                } catch (Throwable $e) {
                    $failed++;
                    $this->error("Slot {$slot->id} failed: {$e->getMessage()}");
                    report($e);

                    return;
                }

                $created += $result['created'];
                $skipped += $result['skipped'];
                $ended += $result['ended'] ? 1 : 0;
            });

        $emails = $notify();

        $this->info("Created {$created} lesson(s), skipped {$skipped} occurrence(s), ended {$ended} slot(s), sent {$emails} skip email(s), {$failed} slot(s) failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
