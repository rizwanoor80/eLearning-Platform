<?php

namespace App\Actions\RecurringSlots;

use App\Mail\RecurringSlots\RecurringSlotSkippedMail;
use App\Models\Learner;
use App\Models\RecurringSlot;
use App\Models\RecurringSlotSkip;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Emails each account once about the occurrences `recurring:generate` skipped (R98). One email per
 * slot per run lists every newly skipped date, not one per date. This is its own sweep over
 * `notified_at IS NULL` rather than a step of generation, so it does not depend on which run wrote
 * the row; each slot's rows are locked, marked and mailed in one transaction, so a second run — or
 * an overlapping one — finds nothing left to send. Only the account is ever mailed (invariant 7).
 */
class SendSlotSkipNotices
{
    /**
     * @return int emails sent
     */
    public function __invoke(): int
    {
        $sent = 0;

        RecurringSlotSkip::query()->whereNull('notified_at')->distinct()->orderBy('recurring_slot_id')
            ->pluck('recurring_slot_id')
            ->each(function (int $slotId) use (&$sent): void {
                $sent += DB::transaction(function () use ($slotId): int {
                    $skips = RecurringSlotSkip::query()
                        ->where('recurring_slot_id', $slotId)
                        ->whereNull('notified_at')
                        ->orderBy('starts_at')
                        ->lockForUpdate()
                        ->get();

                    if ($skips->isEmpty()) {
                        return 0;
                    }

                    RecurringSlotSkip::query()->whereKey($skips->modelKeys())->update(['notified_at' => now()]);

                    $slot = RecurringSlot::query()->find($slotId);
                    $learner = $slot === null ? null : Learner::query()->withTrashed()->find($slot->learner_id);
                    $account = $learner?->account;

                    // Nobody left to tell (a removed learner or account): the rows are settled, not retried daily.
                    // The email names the learner, and `RecurringSlot::learner()` does not load a removed one.
                    if ($slot === null || $learner === null || $learner->trashed() || $account === null || $account->trashed()) {
                        return 0;
                    }

                    Mail::to($account)->send(new RecurringSlotSkippedMail($slot, $account, $skips));

                    return 1;
                });
            });

        return $sent;
    }
}
