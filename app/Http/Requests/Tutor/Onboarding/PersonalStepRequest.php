<?php

namespace App\Http\Requests\Tutor\Onboarding;

use App\Exceptions\MessageMaskingFailedException;
use App\Support\Messaging\MessageMasker;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PersonalStepRequest extends FormRequest
{
    /**
     * Authorization is the `access-tutor-area` route middleware; this
     * request only validates its own step's input.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `phone` dropped to optional (R170 advisor ruling): submission requires only name, country,
     * timezone, CV-or-LinkedIn and agreement — phone is not in that list. `country` is new
     * (R170): a plain ISO alpha-2 code, not validated against an exhaustive country list.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone' => ['nullable', 'string', 'max:32'],
            'country' => ['required', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'timezone' => ['required', 'string', 'timezone:all'],
            // R188(a): optional; blank keeps the first-name default. Parents see it, so anything the
            // R134 masker would hide (an email, a phone number, a link) is refused rather than shown.
            'display_name' => ['nullable', 'string', 'min:2', 'max:30', $this->noContactDetails()],
        ];
    }

    /**
     * Fail-closed like the masker itself (R157(a)): if the masker cannot decide, the name is refused.
     */
    private function noContactDetails(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            try {
                $masked = app(MessageMasker::class)->mask($value)->masked;
            } catch (MessageMaskingFailedException) {
                $masked = true;
            }

            if ($masked) {
                $fail('A display name cannot contain an email address, phone number or link.');
            }
        };
    }
}
