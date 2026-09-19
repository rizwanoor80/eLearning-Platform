<?php

namespace App\Http\Requests\Tutor\Onboarding;

use App\Models\Page;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AgreementStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The form echoes the version it displayed. If the admin published a new
     * one while the tutor was reading, or the field is missing, the acceptance
     * is refused — the version recorded on the profile is always one the
     * tutor was actually shown (R30, sub-cycle 2c).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'accepted' => ['required', 'accepted'],
            'version' => ['required', 'integer', function (string $attribute, mixed $value, Closure $fail): void {
                $current = Page::query()->where('slug', 'tutor_agreement')->value('version');

                if ($current === null || (int) $value !== (int) $current) {
                    $fail('The agreement was updated while you were reading it. Please read the new version and accept again.');
                }
            }],
        ];
    }
}
