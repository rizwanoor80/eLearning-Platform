<?php

namespace App\Http\Requests\Payments;

use App\Http\Requests\Lessons\StoreLessonBookingRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * R269: shape only. The learner and the amount are never fields: the server reads them from the hold itself.
 */
class ConfirmCheckoutHoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'payment_intent_id' => ['required', 'string', 'regex:/^pi_[A-Za-z0-9_]+$/', 'max:100'],
            'tutor_id' => ['required', 'integer'],
            'starts_at' => ['required', 'string', 'date_format:'.StoreLessonBookingRequest::STARTS_AT_FORMAT],
        ];
    }
}
