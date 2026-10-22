<?php

namespace App\Actions\Account;

use App\Actions\RecordAuditLog;
use App\Enums\AccountDeletionRequestStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Events\Account\AccountDeletionDeclined;
use App\Models\AccountDeletionRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * R273: an admin declines a deletion request with a note (for example money still owed or a lesson still open). The
 * account is left exactly as it is; the user is mailed the note. The note is stored on the request and mailed — it is
 * never copied into the audit row, which holds ids and status only. A declined request does not stop a new one.
 */
class DeclineAccountDeletionRequest
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    public function __invoke(User $admin, AccountDeletionRequest $request, string $note): void
    {
        if ($admin->role !== Role::Admin || $admin->status !== UserStatus::Active) {
            throw new AuthorizationException('Only an active admin can decline a deletion request.');
        }

        $note = trim($note);

        if ($note === '') {
            throw ValidationException::withMessages(['note' => 'Write a note to the user saying why.']);
        }

        DB::transaction(function () use ($admin, $request, $note): void {
            // Same lock order as `EraseAccount`: the user's row, then the request.
            $user = User::query()->whereKey($request->user_id)->lockForUpdate()->first();

            if ($user === null) {
                throw ValidationException::withMessages(['request' => 'This account has already been erased.']);
            }

            $locked = AccountDeletionRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== AccountDeletionRequestStatus::Open) {
                throw ValidationException::withMessages(['request' => 'This request has already been handled.']);
            }

            $locked->forceFill([
                'status' => AccountDeletionRequestStatus::Declined,
                'handled_by' => $admin->id,
                'handled_at' => now(),
                'admin_note' => $note,
            ])->save();

            ($this->recordAuditLog)($admin, 'account_deletion.declined', $locked, [
                'status' => AccountDeletionRequestStatus::Open->value,
            ], [
                'status' => AccountDeletionRequestStatus::Declined->value,
            ]);

            $request->setRawAttributes($locked->getAttributes(), true);
        });

        AccountDeletionDeclined::dispatch($request);
    }
}
