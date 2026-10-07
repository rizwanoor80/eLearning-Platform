<?php

namespace App\Http\Requests\Tutor;

use App\Services\Scheduling\BookingLeadTime;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeadTimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // R179: the same admin-controlled list the onboarding Profile step uses — "0" is absent from it
        // while `allow_immediate_booking` is off.
        return [
            'min_lead_hours' => ['required', 'integer', Rule::in(app(BookingLeadTime::class)->options())],
        ];
    }
}
