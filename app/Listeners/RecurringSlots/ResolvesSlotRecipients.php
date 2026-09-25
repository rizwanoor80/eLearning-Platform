<?php

namespace App\Listeners\RecurringSlots;

use App\Models\RecurringSlot;
use App\Models\User;

/**
 * Who a weekly-slot email goes to (R102): the account holder — never the learner (invariant 7) —
 * and the tutor's own login. A removed or anonymised account is not mailed.
 */
trait ResolvesSlotRecipients
{
    protected function parentOf(RecurringSlot $slot): ?User
    {
        $account = $slot->learner?->account;

        return $account === null || $account->trashed() ? null : $account;
    }

    protected function tutorOf(RecurringSlot $slot): ?User
    {
        $tutor = $slot->tutorProfile?->user;

        return $tutor === null || $tutor->trashed() ? null : $tutor;
    }
}
