<?php

namespace App\Actions\Account;

use App\Actions\Admin\AnonymizeUser;
use App\Actions\RecordAuditLog;
use App\Enums\AccountDeletionRequestStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Exceptions\UserDeletionException;
use App\Models\AccountDeletionRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * R273: the one entry point for erasing a parent's or tutor's account, used by the admin "Erase account" action and
 * by the deletion-request queue. It runs `AnonymizeUser` with its rules unchanged (open lessons refuse, admin accounts
 * refuse, R268 keeps bank details while money is owed) and, in the same transaction, closes any open deletion request
 * the account has as `erased`. A refusal rolls everything back, so the request stays open and the exception message
 * (lesson ids and UTC times only) is what the admin sees. No mail goes out: the address is a tombstone afterwards. An account
 * already erased is refused with a domain exception, not left to a `ModelNotFoundException` from the default scope.
 * Decline notes on the account's requests are nulled here (free text about the person).
 * Audit rows hold ids and status only.
 */
class EraseAccount
{
    public function __construct(
        private AnonymizeUser $anonymizeUser,
        private RecordAuditLog $recordAuditLog,
    ) {}

    public function __invoke(User $admin, User $target): void
    {
        if ($admin->role !== Role::Admin || $admin->status !== UserStatus::Active) {
            throw new UserDeletionException('Only an active admin can erase an account.');
        }

        DB::transaction(function () use ($admin, $target): void {
            // Lock order (R225(b)): the target's `users` row first, then the request row; `AnonymizeUser` then
            // takes the tutor profile row inside this same transaction.
            $locked = User::query()->whereKey($target->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new UserDeletionException('This account has already been erased.');
            }

            $open = AccountDeletionRequest::query()
                ->where('user_id', $locked->id)
                ->open()
                ->lockForUpdate()
                ->first();

            ($this->anonymizeUser)($admin, $locked);

            // A decline note is free text about the person; it does not outlive the account. The rest of a handled
            // request (ids, status, dates) is the record of how it was handled and holds no personal data.
            AccountDeletionRequest::query()->where('user_id', $locked->id)->update(['admin_note' => null]);

            if ($open === null) {
                return;
            }

            $open->forceFill([
                'status' => AccountDeletionRequestStatus::Erased,
                'handled_by' => $admin->id,
                'handled_at' => now(),
            ])->save();

            ($this->recordAuditLog)($admin, 'account_deletion.erased', $open, [
                'status' => AccountDeletionRequestStatus::Open->value,
            ], [
                'status' => AccountDeletionRequestStatus::Erased->value,
            ]);
        });
    }
}
