<?php

namespace App\Actions\Payouts;

use App\Actions\RecordAuditLog;
use App\Enums\PayoutMethodCode;
use App\Events\Payouts\PayoutDetailsChanged;
use App\Models\PayoutMethod;
use App\Models\TutorProfile;
use App\Models\User;
use App\Rules\NotSpreadsheetFormula;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * R249: a tutor picks a payout method and gives the details it needs. The Form Request checks the shape; this
 * action is the authority on which methods are offered (re-read from the registry inside the transaction, so a
 * method an admin has just disabled cannot be saved) and on what the chosen method needs.
 *
 * Details are saved on the profile's encrypted columns. A blank value is saved as null (an encrypted column cannot
 * be searched or constrained, so "blank" must be one thing). The IBAN and the account email are write-only: left blank they
 * keep the stored value, so the page never has to send them back to the browser. The other bank fields are shown
 * pre-filled, so a blank there is a deliberate clear (the required ones are then refused). A change of bank details clears
 * `bank_verified_at`: an admin verified those exact values.
 *
 * The audit row carries the method code before and after and whether the details changed, never a value. Returns
 * whether anything changed (and so whether the tutor was emailed).
 *
 * Lock order (as `RequestDisplayName`, R225(b)): the tutor's `users` row first, then the `tutor_profiles` row.
 *
 * @phpstan-type Details array{bank_name?: ?string, bank_account_name?: ?string, bank_iban?: ?string, bank_swift?: ?string, payout_email?: ?string}
 */
class SavePayoutPreference
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    /**
     * @param  Details  $details
     */
    public function __invoke(User $tutor, TutorProfile $profile, string $methodCode, array $details): bool
    {
        if ($profile->user_id !== $tutor->id) {
            throw ValidationException::withMessages(['method' => 'You can only change your own payout method.']);
        }

        $code = PayoutMethodCode::tryFrom($methodCode);

        if ($code === null) {
            throw ValidationException::withMessages(['method' => 'Choose one of the payout methods offered.']);
        }

        $label = null;

        DB::transaction(function () use ($tutor, $profile, $code, $details, &$label): void {
            $owner = User::withTrashed()->whereKey($tutor->getKey())->sharedLock()->firstOrFail();

            $locked = TutorProfile::query()->whereKey($profile->getKey())->lockForUpdate()->firstOrFail();

            if ($owner->trashed()) {
                throw ValidationException::withMessages(['method' => 'This account has been closed, so payout details cannot be changed.']);
            }

            $method = PayoutMethod::query()->offeredFor($locked->country)->where('code', $code->value)->first();

            if ($method === null) {
                throw ValidationException::withMessages(['method' => 'That payout method is not available for your country.']);
            }

            $label = $method->name;
            $before = ['payout_method' => $locked->payout_method];
            $changed = $locked->payout_method !== $code->value;
            $detailsChanged = false;
            $attributes = ['payout_method' => $code->value];

            if ($code->needsBankDetails()) {
                $bank = [];

                foreach (['bank_name', 'bank_account_name', 'bank_iban', 'bank_swift'] as $field) {
                    $given = $this->clean($details[$field] ?? null, $field);

                    // Only the IBAN is write-only (the page never shows it back), so only a blank IBAN keeps the
                    // stored value; the other fields are shown pre-filled, so a blank is a deliberate clear.
                    $keeps = $field === 'bank_iban' || ! array_key_exists($field, $details);
                    $bank[$field] = $keeps ? ($given ?? $locked->{$field}) : $given;
                }

                foreach (['bank_name', 'bank_account_name', 'bank_iban'] as $field) {
                    if ($bank[$field] === null) {
                        throw ValidationException::withMessages([$field => 'This is required for a bank transfer.']);
                    }
                }

                foreach ($bank as $field => $value) {
                    if ($value !== $locked->{$field}) {
                        $changed = true;
                        $detailsChanged = true;
                        $attributes['bank_verified_at'] = null;
                    }
                }

                $attributes += $bank;
            } else {
                $email = $this->clean($details['payout_email'] ?? null, 'payout_email') ?? $locked->payout_email;

                if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    throw ValidationException::withMessages(['payout_email' => 'Enter the email address of your '.$method->name.' account.']);
                }

                $detailsChanged = $email !== $locked->payout_email;
                $changed = $changed || $detailsChanged;
                $attributes['payout_email'] = $email;
            }

            if (! $changed) {
                $label = null;

                return;
            }

            $attributes['payout_details_changed_at'] = now();
            $locked->forceFill($attributes)->save();

            ($this->recordAuditLog)($tutor, 'tutor.payout_method_saved', $locked, $before, [
                'payout_method' => $code->value,
                'details_changed' => $detailsChanged,
            ]);
        });

        if ($label === null) {
            return false;
        }

        $profile->refresh();

        PayoutDetailsChanged::dispatch($profile, $label);

        return true;
    }

    /**
     * Trimmed, blank as null. A value a spreadsheet would run as a formula, or that carries a control character or invalid UTF-8, is refused here too (R263): the Form Request
     * says so first, but this action is the authority, whoever calls it.
     */
    private function clean(?string $value, string $field): ?string
    {
        $value = trim((string) $value);

        if (NotSpreadsheetFormula::isUnsafe($value)) {
            throw ValidationException::withMessages([$field => NotSpreadsheetFormula::MESSAGE]);
        }

        return $value === '' ? null : $value;
    }
}
