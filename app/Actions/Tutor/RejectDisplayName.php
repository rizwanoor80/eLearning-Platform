<?php

namespace App\Actions\Tutor;

use App\Actions\RecordAuditLog;
use App\Events\Tutor\DisplayNameRejected;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * R195: an admin rejects the display name a tutor requested, with a note. The request is cleared, the current
 * `display_name` (if any) is untouched, and the tutor is told by email, name and note quoted. `$expectedName`
 * works as in `ApproveDisplayName`: a name the admin did not read is not rejected on their behalf either. A request
 * on a closed (anonymised) account is refused here as it is in `ApproveDisplayName` (R225(a)): the tutor has no
 * address to tell, and `AnonymizeUser` has already cleared the request.
 */
class RejectDisplayName
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    public function __invoke(User $admin, TutorProfile $profile, string $expectedName, string $note): void
    {
        DisplayNameRequests::assertAdmin($admin);

        $requested = DB::transaction(function () use ($admin, $profile, $expectedName, $note): string {
            $locked = TutorProfile::query()->whereKey($profile->getKey())->lockForUpdate()->firstOrFail();

            DisplayNameRequests::assertAccountOpen($locked, 'This tutor\'s account has been closed, so there is nothing to reject.');

            $requested = DisplayNameRequests::current($locked, $expectedName);

            $locked->forceFill([
                'display_name_requested' => null,
                'display_name_requested_at' => null,
            ])->save();

            ($this->recordAuditLog)($admin, 'tutor.display_name_rejected', $locked, [
                'display_name_requested' => $requested,
            ], [
                'display_name_requested' => null,
                'note' => $note,
            ]);

            return $requested;
        });

        $profile->refresh();

        DisplayNameRejected::dispatch($profile, $requested, $note);
    }
}
