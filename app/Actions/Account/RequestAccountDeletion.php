<?php

namespace App\Actions\Account;

use App\Actions\RecordAuditLog;
use App\Enums\AccountDeletionRequestStatus;
use App\Enums\Role;
use App\Events\Account\AccountDeletionRequested;
use App\Models\AccountDeletionRequest;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * R273: a parent or a tutor asks an admin to erase their account. This never erases, suspends or changes anything
 * on the account; it only files a row in the admin queue and tells the requester so, by event, on the address
 * on the account. One open request per user: refused here under the user's own row lock, and enforced again by
 * the partial unique index (`account_deletion_requests_one_open_per_user`). A declined or erased request does not
 * block a later one. The audit row holds ids, role and status only — no name, email or free text.
 */
class RequestAccountDeletion
{
    private const ALREADY_OPEN = 'You have already asked for your account to be deleted. We will be in touch.';

    public function __construct(private RecordAuditLog $recordAuditLog) {}

    public function __invoke(User $user): AccountDeletionRequest
    {
        if (! in_array($user->role, [Role::AccountOwner, Role::Tutor], true)) {
            throw ValidationException::withMessages(['account' => 'Only a parent or a tutor account can ask for deletion.']);
        }

        $request = DB::transaction(function () use ($user): AccountDeletionRequest {
            // Lock order (R225(b)): the requester's `users` row first.
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw ValidationException::withMessages(['account' => 'This account has been closed.']);
            }

            if (AccountDeletionRequest::query()->where('user_id', $locked->id)->open()->exists()) {
                throw ValidationException::withMessages(['account' => self::ALREADY_OPEN]);
            }

            $request = new AccountDeletionRequest(['user_id' => $locked->id, 'requested_at' => now()]);

            try {
                $request->forceFill(['status' => AccountDeletionRequestStatus::Open])->save();
            } catch (UniqueConstraintViolationException) {
                // The partial unique index is the backstop if the check above is ever bypassed.
                throw ValidationException::withMessages(['account' => self::ALREADY_OPEN]);
            }

            ($this->recordAuditLog)($locked, 'account_deletion.requested', $request, null, [
                'role' => $locked->role->value,
                'status' => AccountDeletionRequestStatus::Open->value,
            ]);

            return $request;
        });

        AccountDeletionRequested::dispatch($request);

        return $request;
    }
}
