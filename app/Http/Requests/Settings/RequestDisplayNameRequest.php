<?php

namespace App\Http\Requests\Settings;

use App\Rules\DisplayNameRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * R195: a tutor asks for a display name. Only someone with a tutor profile may; the name is folded to NFKC
 * before the R192 rule reads it (the rule refuses a name that is not already in that form).
 */
class RequestDisplayNameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->tutorProfile !== null;
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('display_name');

        if (is_string($name)) {
            $this->merge(['display_name' => DisplayNameRule::normalise($name)]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', new DisplayNameRule],
        ];
    }
}
