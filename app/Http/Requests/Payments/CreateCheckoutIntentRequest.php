<?php

namespace App\Http\Requests\Payments;

use App\Http\Requests\Lessons\StoreLessonBookingRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * R248: shape only. The amount is never a field: it is the server's quote for this learner and tutor.
 */
class CreateCheckoutIntentRequest extends FormRequest
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
            'learner_id' => ['required', 'integer'],
            'tutor_id' => ['required', 'integer'],
            'starts_at' => ['required', 'string', 'date_format:'.StoreLessonBookingRequest::STARTS_AT_FORMAT],
            'quote_token' => ['required', 'string', 'max:64'],
            // R259 (3): the browser's own id for this payment attempt; it makes a repeated submit one hold.
            'attempt_id' => ['required', 'uuid'],
        ];
    }
}
