<?php

namespace App\Actions\Tutor;

use App\Actions\RecordAuditLog;
use App\Models\TutorProfile;
use App\Models\User;
use App\Rules\DisplayNameRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * R195: a tutor asks for a display name. It is stored as `display_name_requested` and shown to no one — not to
 * parents, not on the tutor's own profile page — until an admin approves it (`ApproveDisplayName`). The
 * rule-based validator (R192) runs here as well as in the Form Request, so the admin only ever sees names that
 * already passed it. A second request replaces the first; the audit row keeps the one it replaced. A closed
 * (anonymised) account takes none (R217).
 *
 * Lock order (R225(b), same as `AnonymizeUser`): the tutor's `users` row first, then the `tutor_profiles` row. The
 * audit insert below carries a foreign key to the tutor's `users` row, which Postgres checks with `FOR KEY SHARE`;
 * taken after the profile lock it would cross `AnonymizeUser` (users `FOR UPDATE`, then profile) and deadlock into a
 * 500 with nothing written. So a transaction that will reference the tutor's `users` row takes its lock on that
 * row BEFORE the profile lock (this action does; two older actions do not yet, see `AnonymizeUser`). `FOR SHARE` is enough: it waits for an anonymisation in flight (whose UPDATE needs
 * `FOR UPDATE`), keeps `deleted_at` stable until commit, and does not block other inserts that reference the row
 * (it does briefly hold off an UPDATE of that users row, such as a settings save).
 * `ApproveDisplayName` and `RejectDisplayName` need no such lock: their audit foreign key is the admin's row, and an
 * admin is never the target of `AnonymizeUser`. If either ever inserts a row that references the TUTOR's user row,
 * it must take this lock first as well.
 */
class RequestDisplayName
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    public function __invoke(User $tutor, TutorProfile $profile, string $name): void
    {
        if ($profile->user_id !== $tutor->id) {
            throw ValidationException::withMessages(['display_name' => 'You can only request a name for your own profile.']);
        }

        $name = DisplayNameRule::normalise($name);

        $failure = DisplayNameRule::failure($name);

        if ($failure !== null) {
            throw ValidationException::withMessages(['display_name' => $failure]);
        }

        DB::transaction(function () use ($tutor, $profile, $name): void {
            // withTrashed: a closed account is fetched and refused below, not a 404.
            $owner = User::withTrashed()->whereKey($tutor->getKey())->sharedLock()->firstOrFail();

            $locked = TutorProfile::query()->whereKey($profile->getKey())->lockForUpdate()->firstOrFail();

            if ($owner->trashed()) {
                throw ValidationException::withMessages(['display_name' => 'This account has been closed, so a display name cannot be requested.']);
            }

            if ($name === $locked->display_name) {
                throw ValidationException::withMessages(['display_name' => 'That is already your display name.']);
            }

            $before = ['display_name_requested' => $locked->display_name_requested];

            $locked->forceFill([
                'display_name_requested' => $name,
                'display_name_requested_at' => now(),
            ])->save();

            ($this->recordAuditLog)($tutor, 'tutor.display_name_requested', $locked, $before, [
                'display_name_requested' => $name,
            ]);
        });

        $profile->refresh();
    }
}
