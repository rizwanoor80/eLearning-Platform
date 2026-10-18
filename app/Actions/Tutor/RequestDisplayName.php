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
            $locked = TutorProfile::query()->whereKey($profile->getKey())->lockForUpdate()->firstOrFail();

            DisplayNameRequests::assertAccountOpen($locked, 'This account has been closed, so a display name cannot be requested.');

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
