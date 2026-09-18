<?php

namespace App\Actions\Admin;

use App\Actions\RecordAuditLog;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use RuntimeException;

class DisableAdminUser
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    /**
     * @throws RuntimeException when the target is not an admin, is the acting
     *                          admin themselves, or is the last active admin
     *                          (all three would risk locking everyone out).
     */
    public function __invoke(User $actor, User $target, string $reason): void
    {
        if ($target->role !== Role::Admin) {
            throw new RuntimeException('Only admin users can be disabled here.');
        }

        if ($target->is($actor)) {
            throw new RuntimeException('You cannot disable your own account.');
        }

        $otherActiveAdmins = User::query()
            ->where('role', Role::Admin)
            ->where('status', UserStatus::Active)
            ->whereKeyNot($target->getKey())
            ->count();

        if ($otherActiveAdmins === 0) {
            throw new RuntimeException('You cannot disable the last active admin.');
        }

        $before = ['status' => $target->status->value];

        $target->forceFill([
            'status' => UserStatus::Suspended,
            'suspended_reason' => $reason,
        ])->save();

        ($this->recordAuditLog)($actor, 'admin_user.disabled', $target, $before, [
            'status' => UserStatus::Suspended->value,
            'suspended_reason' => $reason,
        ]);
    }
}
