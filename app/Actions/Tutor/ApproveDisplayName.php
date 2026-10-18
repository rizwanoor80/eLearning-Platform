<?php

namespace App\Actions\Tutor;

use App\Actions\RecordAuditLog;
use App\Models\TutorProfile;
use App\Models\User;
use App\Rules\DisplayNameRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * R195: an admin approves the display name a tutor requested. `$expectedName` is the name the admin was shown;
 * if the tutor has since replaced it (or the request is gone), nothing is approved, so an admin never approves a
 * name they did not read. The validator runs again at this moment, so a request stored under an older rule
 * cannot reach `display_name`, and a request on a closed (anonymised) account is refused (R217). Approval copies
 * the request to `display_name`, clears it, and writes the audit row.
 */
class ApproveDisplayName
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    public function __invoke(User $admin, TutorProfile $profile, string $expectedName): void
    {
        DisplayNameRequests::assertAdmin($admin);

        DB::transaction(function () use ($admin, $profile, $expectedName): void {
            $locked = TutorProfile::query()->whereKey($profile->getKey())->lockForUpdate()->firstOrFail();

            DisplayNameRequests::assertAccountOpen($locked, 'This tutor\'s account has been closed, so the name cannot be approved.');

            $requested = DisplayNameRequests::current($locked, $expectedName);

            $failure = DisplayNameRule::failure($requested);

            if ($failure !== null) {
                throw ValidationException::withMessages(['display_name' => "This name no longer passes the display-name rules: {$failure}"]);
            }

            $before = [
                'display_name' => $locked->display_name,
                'display_name_requested' => $requested,
            ];

            $locked->forceFill([
                'display_name' => $requested,
                'display_name_requested' => null,
                'display_name_requested_at' => null,
            ])->save();

            ($this->recordAuditLog)($admin, 'tutor.display_name_approved', $locked, $before, [
                'display_name' => $requested,
                'display_name_requested' => null,
            ]);
        });

        $profile->refresh();
    }
}
