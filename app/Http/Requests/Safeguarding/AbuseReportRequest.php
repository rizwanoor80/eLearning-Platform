<?php

namespace App\Http\Requests\Safeguarding;

use App\Enums\AbuseReportReason;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * CP7 8d (R137): shared shape for the three report-filing requests (tutor profile, lesson,
 * conversation). Each subclass supplies its own `authorize()` against the route-bound
 * subject; a non-party or a stranger is told there is no such page (404), never that it is
 * forbidden, matching the review and messaging routes' precedent.
 */
abstract class AbuseReportRequest extends FormRequest
{
    protected function failedAuthorization(): never
    {
        throw new NotFoundHttpException;
    }

    /**
     * @return array<string, array<int, ValidationRule|Enum|string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', new Enum(AbuseReportReason::class)],
            'description' => ['required', 'string', 'max:2000'],
        ];
    }
}
