<?php

namespace App\Services\Video;

use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Whether an account was suspended at a given instant, read from the audit trail (`user.suspended` /
 * `user.reinstated`, the only writers of either): the users table keeps the status, not when it changed.
 * The last of the two at or before the instant decides. The audit clock is whole seconds and the provider's event
 * time is whole seconds on its own clock, so a join within a few seconds of the suspension can fall on either side;
 * a tie (same second) is read as suspended, the conservative side where attendance feeds a payout.
 */
class AccountSuspensions
{
    public static function wasSuspendedAt(int $userId, CarbonInterface $at): bool
    {
        return AuditLog::query()
            ->where('subject_type', User::class)
            ->where('subject_id', $userId)
            ->whereIn('action', ['user.suspended', 'user.reinstated'])
            ->where('created_at', '<=', $at)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('action') === 'user.suspended';
    }
}
