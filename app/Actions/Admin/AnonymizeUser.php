<?php

namespace App\Actions\Admin;

use App\Actions\Match\CloseMatchRequest;
use App\Actions\Payouts\ErasePayoutLineDetails;
use App\Actions\RecordAuditLog;
use App\Actions\Tutor\SuspendTutor;
use App\Enums\LessonStatus;
use App\Enums\MatchRequestStatus;
use App\Enums\PayoutLineStatus;
use App\Enums\Role;
use App\Enums\TutorProfileStatus;
use App\Exceptions\UserDeletionException;
use App\Models\Lesson;
use App\Models\MatchRequest;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Tutors\TutorEarnings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * R54: a user is never hard-deleted. Refused (naming the blocking lessons)
 * while a lesson reachable from this account — a parent's via their
 * learners, a tutor's via their profile — is still non-terminal, the same
 * money/obligation test `DeleteLearner` applies. Otherwise: PII tombstoned,
 * credentials cleared, passkeys and sessions revoked explicitly (neither
 * cascades under a soft delete), the row soft-deleted, and — for a tutor —
 * the profile moved out of `approved` so `bookable()` (invariant #5) stays
 * safe. `lessons`, `payments`, `ledger_entries`, `match_requests` and
 * `learners` are left intact by design: financial history and a parent's
 * other data must outlive the account (R54) — the one exception is any
 * still-open match request, closed here so `SuggestTutors` can never later
 * mail the tombstoned address.
 *
 * R274: a tutor's paid payout lines lose their encrypted `details` copy here (all lines when nothing is owed); the amount,
 * method, currency, reference and paid date stay (`ErasePayoutLineDetails`).
 *
 * Admin action, audited; enforced here because no `UserPolicy` exists yet.
 *
 * Lock order (R225(b)): the target's `users` row `FOR UPDATE` first, then any `tutor_profiles` row.
 * `RequestDisplayName` (inline), `CompleteTutorOnboarding` and the permit step of onboarding
 * (`TutorOnboardingController::storePermit`, through `ResetPermitScanOnPermitChange`; both call
 * `TutorStatusTransitions::lockOwner()`) take the same order and so cannot deadlock with this action. Any new transaction that locks a tutor's profile and then inserts a row referencing the
 * tutor's `users` row must call `lockOwner()` first, in its outermost transaction.
 */
class AnonymizeUser
{
    public function __construct(
        private RecordAuditLog $recordAuditLog,
        private SuspendTutor $suspendTutor,
        private CloseMatchRequest $closeMatchRequest,
        private TutorEarnings $earnings,
        private ErasePayoutLineDetails $erasePayoutLineDetails,
    ) {}

    public function __invoke(User $actor, User $target): void
    {
        if ($actor->role !== Role::Admin) {
            throw new UserDeletionException('Only an admin can delete a user account.');
        }

        if ($target->role === Role::Admin) {
            throw new UserDeletionException('Admin accounts are disabled (DisableAdminUser), not deleted here.');
        }

        DB::transaction(function () use ($actor, $target): void {
            $locked = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            $blocking = $this->blockingLessons($locked);

            if ($blocking->isNotEmpty()) {
                $names = $blocking->map(fn (Lesson $lesson) => "#{$lesson->id} ({$lesson->starts_at->toDateTimeString()} UTC)")->implode(', ');

                throw new UserDeletionException("Cannot delete this account: still-open lessons {$names}.");
            }

            $before = [
                'role' => $locked->role->value,
                'had_phone' => $locked->phone !== null,
            ];

            $locked->forceFill([
                'name' => 'Deleted user',
                'email' => "deleted+{$locked->id}@tombstone.invalid",
                'phone' => null,
                'password' => Str::random(60),
                'remember_token' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            // Saving fires UserObserver, which tombstones an adult student's own
            // learner name in step; a minor learner's own name is untouched.
            DB::table('passkeys')->where('user_id', $locked->id)->delete();
            DB::table('sessions')->where('user_id', $locked->id)->delete();

            $profile = $locked->tutorProfile;

            if ($profile !== null && $profile->status === TutorProfileStatus::Approved) {
                ($this->suspendTutor)($actor, $profile, 'Account deleted.');
            }

            // R195: a tutor-chosen display name (approved or pending) is personal data and goes with the account.
            // An unconditional UPDATE, not a dirty-checked save: a read of the profile here is not locked, and an
            // approval committing between that read and a save would leave the in-memory name null, issue no
            // UPDATE, and let the anonymised tutor keep the approved name.
            $bankDetails = null;
            $payoutLinesErased = null;

            if ($profile !== null) {
                $values = [
                    'display_name' => null,
                    'display_name_requested' => null,
                    'display_name_requested_at' => null,
                    // R249: a payout method and its account email are personal data and go with the account.
                    'payout_method' => null,
                    'payout_email' => null,
                    'payout_details_changed_at' => null,
                ];

                // R268: the bank details go too, unless the tutor is still owed money: then they stay, for the admin's
                // manual transfer, until `ClearAnonymisedTutorBankDetails` records that decision. Decided on the
                // locked profile, so a lesson settling at the same moment cannot slip between the read and the write.
                $lockedProfile = TutorProfile::query()->whereKey($profile->getKey())->lockForUpdate()->firstOrFail();

                if ($this->earnings->buckets($lockedProfile)->owed()->isZero()) {
                    $values += array_fill_keys(TutorProfile::BANK_FIELDS, null);
                    $bankDetails = 'cleared';
                    // R274: nothing is owed, so no line is awaiting payment (a pending line's money is `processing`, part of
                    // `owed`): every line's frozen details copy goes with the account.
                    $erase = PayoutLineStatus::cases();
                } else {
                    $bankDetails = 'kept_money_owed';
                    // R274: a paid line's copy has no use and goes now; unpaid lines keep theirs, for the admin's manual
                    // transfer, until `ClearAnonymisedTutorBankDetails` records that decision.
                    $erase = [PayoutLineStatus::Paid];
                }

                $payoutLinesErased = ($this->erasePayoutLineDetails)($lockedProfile, $erase);

                TutorProfile::query()->whereKey($profile->getKey())->update($values);
            }

            MatchRequest::query()
                ->where('account_user_id', $locked->id)
                ->where('status', '!=', MatchRequestStatus::Closed)
                ->get()
                ->each(fn (MatchRequest $request) => ($this->closeMatchRequest)($actor, $request));

            $locked->delete();

            ($this->recordAuditLog)($actor, 'user.anonymized', $locked, $before, [
                'status' => 'anonymized',
                'name' => $locked->name,
                'email' => $locked->email,
                'bank_details' => $bankDetails,
                'payout_lines_erased' => $payoutLinesErased,
            ]);
        });
    }

    /**
     * @return Collection<int, Lesson>
     */
    private function blockingLessons(User $user): Collection
    {
        $learnerIds = $user->learners()->pluck('id');
        $tutorProfileId = $user->tutorProfile?->id;

        return Lesson::query()
            ->whereNotIn('status', LessonStatus::terminalValues())
            ->where(function ($query) use ($learnerIds, $tutorProfileId): void {
                $query->whereIn('learner_id', $learnerIds);

                if ($tutorProfileId !== null) {
                    $query->orWhere('tutor_profile_id', $tutorProfileId);
                }
            })
            ->orderBy('starts_at')
            ->get(['id', 'starts_at']);
    }
}
