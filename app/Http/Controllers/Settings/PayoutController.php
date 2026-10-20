<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Payouts\SavePayoutPreference;
use App\Enums\PayoutMethodCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SavePayoutPreferenceRequest;
use App\Models\PayoutMethod;
use App\Models\TutorProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * R249: where a tutor says how they want to be paid. Only masked values reach the browser: the IBAN as its last four
 * and the account email with its local part cut, so a stolen session or a stray prop never carries the full detail.
 */
class PayoutController extends Controller
{
    public function edit(Request $request): Response
    {
        // 18d: a local variable, not `$user->tutorProfile`, so the profile is never loaded onto the request user.
        $profile = TutorProfile::query()->where('user_id', $request->user()->id)->firstOrFail();

        $methods = PayoutMethod::query()->offeredFor($profile->country)->orderBy('sort')->get()
            ->map(fn (PayoutMethod $method): array => [
                'code' => $method->code,
                'name' => $method->name,
                'kind' => $method->methodCode()?->needsBankDetails() ? 'bank' : 'email',
            ])->values()->all();

        $bankComplete = $profile->bank_name !== null && $profile->bank_account_name !== null && $profile->bank_iban !== null;
        $offeredCodes = array_column($methods, 'code');
        $selected = $profile->payout_method;

        // No implicit default is saved: when the onboarding bank details are complete the page pre-selects bank
        // transfer, and the tutor confirms with one click.
        if (($selected === null || ! in_array($selected, $offeredCodes, true)) && $bankComplete && in_array(PayoutMethodCode::BankTransfer->value, $offeredCodes, true)) {
            $selected = PayoutMethodCode::BankTransfer->value;
        }

        return Inertia::render('settings/Payout', [
            'methods' => $methods,
            'selected' => $selected,
            'saved_method' => $profile->payout_method,
            'problem' => $profile->payoutMethodProblem(),
            'bank' => [
                'bank_name' => $profile->bank_name,
                'bank_account_name' => $profile->bank_account_name,
                'iban_masked' => $profile->bankIbanMasked(),
                'bank_swift' => $profile->bank_swift,
            ],
            'payout_email_masked' => $profile->payoutEmailMasked(),
        ]);
    }

    public function update(SavePayoutPreferenceRequest $request, SavePayoutPreference $savePayoutPreference): RedirectResponse
    {
        $user = $request->user();
        $profile = TutorProfile::query()->where('user_id', $user->id)->firstOrFail();

        $savePayoutPreference($user, $profile, $request->validated('method'), $request->safe()->only([
            'bank_name', 'bank_account_name', 'bank_iban', 'bank_swift', 'payout_email',
        ]));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payout method saved. We emailed you a confirmation.')]);

        return to_route('payout.edit');
    }
}
