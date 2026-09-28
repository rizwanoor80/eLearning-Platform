<?php

namespace App\Actions\Admin;

use App\Actions\RecordAuditLog;
use App\Actions\Tutor\SuspendTutor;
use App\Enums\Role;
use App\Enums\TutorProfileStatus;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * R138 (Safeguarding queue): suspends any non-admin account — a parent or a tutor. Every
 * `sessions` row and passkey is revoked and `remember_token` rotated (the `AnonymizeUser`
 * precedent for cutting off other devices); `EnsureAccountActive` middleware catches the one
 * request already in flight, and every one after, on any session that somehow survives.
 *
 * If the account is a tutor's own approved profile, `SuspendTutor` runs too — one suspension, one
 * cascade, not two independent ones racing the same lessons. `CancelSuspendedAccountLessons` (the
 * parent-side sweep: free `reserved` lessons, list `confirmed` ones for the admin, pause active
 * slots) fires via `DB::afterCommit`, the same reasoning `SuspendTutor` already applies to its own
 * cascade (CYCLE-LOG 2026-09-27 18:12) — a single lesson's failure must not unwind the suspension.
 */
class SuspendAccount
{
    public function __construct(
        private readonly RecordAuditLog $recordAuditLog,
        private readonly SuspendTutor $suspendTutor,
        private readonly CancelSuspendedAccountLessons $cancelLessons,
    ) {}

    /**
     * @throws RuntimeException when the target is an admin (use DisableAdminUser) or the acting admin themselves
     */
    public function __invoke(User $actor, User $target, string $reason): void
    {
        if ($target->role === Role::Admin) {
            throw new RuntimeException('Admin accounts are disabled (DisableAdminUser), not suspended here.');
        }

        if ($target->is($actor)) {
            throw new RuntimeException('You cannot suspend your own account.');
        }

        DB::transaction(function () use ($actor, $target, $reason): void {
            $locked = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            $before = ['status' => $locked->status->value];

            $locked->forceFill([
                'status' => UserStatus::Suspended,
                'suspended_reason' => $reason,
                'remember_token' => Str::random(60),
            ])->save();

            DB::table('passkeys')->where('user_id', $locked->id)->delete();
            DB::table('sessions')->where('user_id', $locked->id)->delete();

            ($this->recordAuditLog)($actor, 'user.suspended', $locked, $before, [
                'status' => UserStatus::Suspended->value,
                'suspended_reason' => $reason,
            ]);

            $profile = $locked->tutorProfile;

            if ($profile !== null && $profile->status === TutorProfileStatus::Approved) {
                ($this->suspendTutor)($actor, $profile, 'Account suspended.');
            }

            DB::afterCommit(fn () => ($this->cancelLessons)($locked, $actor));
        });
    }
}
