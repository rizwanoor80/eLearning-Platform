<?php

namespace App\Actions\Admin;

use App\Actions\RecordAuditLog;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use RuntimeException;

class EnableAdminUser
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    /**
     * @throws RuntimeException when the target is not an admin.
     */
    public function __invoke(User $actor, User $target): void
    {
        if ($target->role !== Role::Admin) {
            throw new RuntimeException('Only admin users can be enabled here.');
        }

        $before = ['status' => $target->status->value];

        $target->forceFill([
            'status' => UserStatus::Active,
            'suspended_reason' => null,
        ])->save();

        ($this->recordAuditLog)($actor, 'admin_user.enabled', $target, $before, [
            'status' => UserStatus::Active->value,
        ]);
    }
}
