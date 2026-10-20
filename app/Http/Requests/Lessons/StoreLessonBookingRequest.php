<?php

namespace App\Http\Requests\Lessons;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The single-booking form (R114). It checks shape only and passes on exactly five fields (plus the quote token and, with Stripe, the confirmed PaymentIntent's id): the lesson
 * `type`, the price and the policy are never read from the client (invariant 12), and every business
 * rule — the slot window, the trial decision, the band — is enforced by `BookLesson`.
 * `starts_at` is the slot's UTC instant in `Y-m-d\TH:i:s\Z` form, the form the profile page links with.
 */
class StoreLessonBookingRequest extends FormRequest
{
    public const STARTS_AT_FORMAT = 'Y-m-d\TH:i:s\Z';

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
            'curriculum_id' => ['required', 'integer'],
            'subject_id' => ['required', 'integer'],
            'starts_at' => ['required', 'string', 'date_format:'.self::STARTS_AT_FORMAT],
            'quote_token' => ['required', 'string', 'max:64'],
            // R248: the PaymentIntent the browser confirmed. Required by the controller when Stripe is the
            // active gateway, ignored otherwise; only its shape is checked here.
            'payment_intent_id' => ['nullable', 'string', 'max:255', 'regex:/^pi_[A-Za-z0-9_]+$/'],
        ];
    }
}
