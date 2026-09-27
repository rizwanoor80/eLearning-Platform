<?php

namespace App\Actions\Admin;

use App\Actions\RecordAuditLog;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use RuntimeException;

/**
 * R138 (Safeguarding queue): the way back from `SuspendAccount`. Login is unblocked; nothing
 * cancelled by the suspension is restored (R138) — a `reserved`/`confirmed` lesson the sweep
 * touched stays cancelled/refunded, and a paused weekly slot stays paused for the parent to
 * resume by hand (`RecurringSlotPolicy::resume`). If the account is also a suspended tutor
 * profile, that is `ReinstateTutor`'s separate decision, not cascaded from here.
 */
class ReinstateAccount
{
    public function __construct(private readonly RecordAuditLog $recordAuditLog) {}

    /**
     * @throws RuntimeException when the target is an admin (use EnableAdminUser) or is not suspended
     */
    public function __invoke(User $actor, User $target): void
    {
        if ($target->role === Role::Admin) {
            throw new RuntimeException('Admin accounts are enabled (EnableAdminUser), not reinstated here.');
        }

        if ($target->status !== UserStatus::Suspended) {
            throw new RuntimeException('Only a suspended account can be reinstated.');
        }

        $before = ['status' => $target->status->value];

        $target->forceFill([
            'status' => UserStatus::Active,
            'suspended_reason' => null,
        ])->save();

        ($this->recordAuditLog)($actor, 'user.reinstated', $target, $before, [
            'status' => UserStatus::Active->value,
        ]);
    }
}
