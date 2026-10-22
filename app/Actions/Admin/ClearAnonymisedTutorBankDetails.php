<?php

namespace App\Actions\Admin;

use App\Actions\Payouts\ErasePayoutLineDetails;
use App\Actions\RecordAuditLog;
use App\Enums\PayoutLineStatus;
use App\Enums\Role;
use App\Exceptions\UserDeletionException;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Tutors\TutorEarnings;
use Illuminate\Support\Facades\DB;

/**
 * R268: an anonymised tutor who was still owed money kept their bank details (`AnonymizeUser`) so an admin could pay
 * them by hand. This is the admin recording that decision: the details are deleted for good. The audit row carries the
 * note, the amount still owed in fils and which fields held a value, never a value.
 *
 * R274: it also erases the encrypted details copy on every one of the tutor's payout lines (pending and failed ones
 * included; a pending line can then no longer be exported, so an admin marks it paid if the transfer was made by hand, failed if not). That covers a tutor paid by
 * email, who holds no bank fields at all but whose lines still carry the email copy. Amounts, references and paid
 * dates stay; no line is deleted.
 *
 * Lock order (R225(b)): the owner's `users` row first, then the profile.
 */
class ClearAnonymisedTutorBankDetails
{
    public function __construct(private RecordAuditLog $recordAuditLog, private TutorEarnings $earnings, private ErasePayoutLineDetails $erasePayoutLineDetails) {}

    public static function applies(TutorProfile $profile): bool
    {
        if (! $profile->user?->trashed()) {
            return false;
        }

        foreach (TutorProfile::BANK_FIELDS as $field) {
            if ($profile->getAttribute($field) !== null) {
                return true;
            }
        }

        return ErasePayoutLineDetails::anyLeft($profile);
    }

    public function __invoke(User $admin, TutorProfile $profile, string $note): bool
    {
        if ($admin->role !== Role::Admin) {
            throw new UserDeletionException('Only an admin can clear a tutor’s bank details.');
        }

        return DB::transaction(function () use ($admin, $profile, $note): bool {
            $owner = User::withTrashed()->whereKey($profile->user_id)->lockForUpdate()->firstOrFail();
            $locked = TutorProfile::query()->whereKey($profile->getKey())->lockForUpdate()->firstOrFail();

            if (! $owner->trashed()) {
                throw new UserDeletionException('The account is not anonymised; bank details of a live account are not cleared here.');
            }

            $held = array_values(array_filter(
                TutorProfile::BANK_FIELDS,
                fn (string $field): bool => $locked->getAttribute($field) !== null,
            ));

            $linesErased = ($this->erasePayoutLineDetails)($locked, PayoutLineStatus::cases());

            if ($held === [] && $linesErased === 0) {
                return false;
            }

            $owed = $this->earnings->buckets($locked)->owed()->toFils();

            // An unconditional UPDATE, as in AnonymizeUser: not a dirty-checked save on a model read a moment ago.
            TutorProfile::query()->whereKey($locked->getKey())->update(array_fill_keys(TutorProfile::BANK_FIELDS, null));

            ($this->recordAuditLog)($admin, 'tutor.bank_details_cleared', $locked, null, [
                'note' => $note,
                'owed_fils' => $owed,
                'fields_cleared' => $held,
                'payout_lines_erased' => $linesErased,
            ]);

            return true;
        });
    }
}
