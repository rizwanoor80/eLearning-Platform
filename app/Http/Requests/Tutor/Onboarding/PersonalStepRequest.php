<?php

namespace App\Http\Requests\Tutor\Onboarding;

use App\Exceptions\MessageMaskingFailedException;
use App\Support\Messaging\MessageMasker;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PersonalStepRequest extends FormRequest
{
    private const NAME_PATTERN = "/^\p{L}[\p{L}\p{M}\x{200C}\x{200D} .'’-]*\z/u";

    /**
     * Characters the allow-list admits as letters or marks but that either draw as punctuation or are
     * invisible, so the text on screen could differ from the text the masker read (review 14b round 2):
     * enclosing marks (one on an "a" draws as "@"), invisible variation selectors and fillers, the
     * dot-shaped Lisu tone letters and U+A78F, and a stack of four or more combining marks.
     */
    private const LOOKALIKE_PATTERN = '/\p{Me}|[\x{034F}\x{180B}-\x{180F}\x{FE00}-\x{FE0F}\x{16FE4}\x{E0100}-\x{E01EF}\x{A4F8}-\x{A4FD}\x{A78F}]|\p{M}{4}/u';

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

            // A name is words: letters and marks, with a space, full stop, apostrophe or hyphen between
            // them (and a joiner inside, as many Persian and Indic names need). Anything else — digits,
            // symbols, an @, a direction override, an invisible character — is refused, so the text on
            // screen is the text the masker checked. At least two letters, so it can never read as blank.
            if (
                preg_match(self::NAME_PATTERN, $value) !== 1
                || preg_match(self::LOOKALIKE_PATTERN, $value) !== 0
                || preg_match_all('/\p{L}/u', $value) < 2
            ) {
                $fail('A display name can only use letters, spaces, full stops, apostrophes and hyphens.');

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
