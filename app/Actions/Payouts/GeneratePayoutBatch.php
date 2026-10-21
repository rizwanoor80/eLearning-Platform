<?php

namespace App\Actions\Payouts;

use App\Actions\RecordAuditLog;
use App\Enums\LedgerAccount;
use App\Enums\LedgerEntryType;
use App\Enums\PayoutLineStatus;
use App\Enums\PayoutMethodCode;
use App\Exceptions\PayoutException;
use App\Models\LedgerEntry;
use App\Models\PayoutBatch;
use App\Models\PayoutBatchLine;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Payouts\GeneratedPayoutBatch;
use App\Services\Tutors\TutorEarnings;
use App\Services\Tutors\TutorStatusTransitions;
use App\Support\Facades\Settings;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 23h (R251): an admin generates a payout batch for a period. A tutor gets a line when their available money (the
 * `TutorEarnings` bucket, computed here under the tutor's row lock) is at least `payout_min` and `canBePaid()`; the line's
 * amount is that whole available amount, in the tutor's method, with the account details copied onto it.
 *
 * The period is a label for the admin's records. Available money is not cut at the period's end, but it is cut at the
 * dispute window (R258): only money released to the tutor, on a lesson that is not disputed and whose window has closed
 * (48 hours after it ended, 7 days if it was settled after a suspension), is paid. Money inside its window stays pending.
 * The period defaults to the last `payout_weekday` on or before today.
 *
 * Left out, and reported (only when the tutor had at least the minimum available): a tutor who cannot be paid yet (no
 * method, method no longer offered, or details incomplete or unsafe for a bank file) and a tutor who already has an unpaid
 * line. A suspended tutor is still paid what they earned; the rule is `canBePaid()` and nothing else.
 *
 * An anonymised (closed) account that is still owed money is never dropped silently (R263): with any positive available
 * amount, below the minimum or not, it is reported in `GeneratedPayoutBatch::$anonymisedOwed` and audited, and its money
 * stays out of every batch. What happens to it is an admin's decision, outside this action.
 *
 * Lock order (18d): for each candidate, in profile-id order, in one transaction, the tutor's `users` row first (`FOR SHARE`,
 * as `AnonymizeUser` and `SavePayoutPreference` take it), then the `tutor_profiles` row `FOR UPDATE`. Each step locks at most
 * one tutor's pair, always in that order, so nothing can wait on another in a circle. `MarkPayoutLinePaid` and
 * `LedgerService::payout()` take the profile row only. A second generator waits for the first and then sees its lines. The
 * database has the last word: one unpaid line per tutor (a partial unique index) and one line per tutor per batch.
 * The admin check comes first: this action does not rely on the page that calls it.
 */
class GeneratePayoutBatch
{
    public function __construct(private TutorEarnings $earnings, private RecordAuditLog $recordAuditLog) {}

    /**
     * @throws PayoutException
     */
    public function __invoke(User $admin, ?CarbonImmutable $periodEnd = null): GeneratedPayoutBatch
    {
        AssertPayoutAdmin::for($admin, 'generate a payout batch');

        $periodEnd = ($periodEnd ?? self::defaultPeriodEnd())->startOfDay();
        $minimum = Money::fils((int) Settings::get('payout_min'));

        return DB::transaction(function () use ($admin, $periodEnd, $minimum): GeneratedPayoutBatch {
            $candidateIds = LedgerEntry::query()
                ->where('account', LedgerAccount::Tutor->value)
                ->where('type', LedgerEntryType::ReleaseTutor->value)
                ->whereNotNull('tutor_profile_id')
                ->distinct()
                ->orderBy('tutor_profile_id')
                ->pluck('tutor_profile_id');

            $batch = null;
            $count = 0;
            $total = Money::zero();
            $skipped = [];
            $anonymisedOwed = [];

            foreach ($candidateIds as $id) {
                // `user_id` never changes, so it is read without a lock; the users row is then locked before the profile.
                $userId = TutorProfile::query()->whereKey($id)->value('user_id');

                if ($userId === null) {
                    continue;
                }

                $owner = TutorStatusTransitions::lockOwner(new TutorProfile(['user_id' => $userId]));
                $profile = TutorProfile::query()->whereKey($id)->lockForUpdate()->first();

                if ($profile === null) {
                    continue;
                }

                $profile->setRelation('user', $owner);

                $available = $this->earnings->buckets($profile)->available;

                if ($owner->trashed() && $available->compare(Money::zero()) > 0) {
                    $anonymisedOwed[] = ['tutor_profile_id' => $profile->id, 'amount_fils' => $available->toFils()];

                    ($this->recordAuditLog)($admin, 'payout_batch.owed_to_anonymised', $profile, null, ['amount_fils' => $available->toFils()]);

                    continue;
                }

                if ($available->compare($minimum) < 0) {
                    continue;
                }

                $reason = $this->reasonToSkip($profile);

                if ($reason !== null) {
                    $skipped[] = ['tutor_profile_id' => $profile->id, 'tutor' => $profile->displayName(), 'reason' => $reason];

                    continue;
                }

                $batch ??= PayoutBatch::query()->create([
                    'period_start' => PayoutBatch::query()->max('period_end'),
                    'period_end' => $periodEnd,
                    'created_by_user_id' => $admin->id,
                ]);

                $count++;
                $method = PayoutMethodCode::from((string) $profile->payout_method);

                $batch->lines()->create([
                    'tutor_profile_id' => $profile->id,
                    'payout_method' => $method->value,
                    'amount' => $available,
                    'currency' => PayoutBatchLine::CURRENCY,
                    'reference' => sprintf('TT-%05d-%03d', $batch->id, $count),
                    'details' => $method->needsBankDetails()
                        ? ['account_name' => $profile->bank_account_name, 'bank_name' => $profile->bank_name, 'iban' => $profile->bank_iban, 'swift' => $profile->bank_swift]
                        : ['email' => $profile->payout_email],
                    'status' => PayoutLineStatus::Pending,
                ]);

                $total = $total->add($available);
            }

            if ($batch !== null) {
                ($this->recordAuditLog)($admin, 'payout_batch.generated', $batch, null, [
                    'period_end' => $periodEnd->toDateString(),
                    'lines' => $count,
                    'total_fils' => $total->toFils(),
                    'skipped' => count($skipped),
                ]);
            }

            return new GeneratedPayoutBatch($batch, $skipped, $anonymisedOwed);
        });
    }

    /**
     * The most recent `payout_weekday` (Carbon's numbering, 0 = Sunday) on or before today.
     */
    public static function defaultPeriodEnd(): CarbonImmutable
    {
        $today = CarbonImmutable::now()->startOfDay();

        return $today->subDays(($today->dayOfWeek - (int) Settings::get('payout_weekday') + 7) % 7);
    }

    private function reasonToSkip(TutorProfile $profile): ?string
    {
        if ($profile->user === null || $profile->user->trashed()) {
            return 'The account has been closed.';
        }

        $problem = $profile->payoutMethodProblem();

        if ($problem !== null) {
            return $problem;
        }

        if (PayoutBatchLine::query()->where('tutor_profile_id', $profile->id)->where('status', PayoutLineStatus::Pending->value)->exists()) {
            return 'Already has a payout waiting to be marked paid or failed.';
        }

        return null;
    }
}
