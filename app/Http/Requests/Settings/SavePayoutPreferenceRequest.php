<?php

namespace App\Http\Requests\Settings;

use App\Enums\PayoutMethodCode;
use App\Models\PayoutMethod;
use App\Models\TutorProfile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * R249: a tutor saves a payout method and its details. Shape only; `SavePayoutPreference` re-checks the offered set
 * and completeness in its transaction. The method must be one offered for the tutor's own country right now. The
 * profile is looked up by query, not `$user->tutorProfile`, so it is never loaded onto the request user (18d).
 */
class SavePayoutPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && TutorProfile::query()->where('user_id', $user->id)->exists();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $country = TutorProfile::query()->where('user_id', $this->user()?->id)->value('country');
        $offered = PayoutMethod::query()->offeredFor($country)->pluck('code')->all();

        return [
            'method' => ['required', 'string', Rule::in($offered), Rule::enum(PayoutMethodCode::class)],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'bank_iban' => ['nullable', 'string', 'max:34'],
            'bank_swift' => ['nullable', 'string', 'max:11'],
            'payout_email' => ['nullable', 'string', 'email:rfc', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['method.in' => 'That payout method is not available for your country.'];
    }
}
