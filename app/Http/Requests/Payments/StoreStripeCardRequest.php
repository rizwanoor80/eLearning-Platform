<?php

namespace App\Http\Requests\Payments;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Stripe add-card form: the id of the SetupIntent the browser just confirmed (never a card
 * number — there is no such field), and optionally the learner and tutor of the weekly-slot setup the
 * parent came from.
 */
class StoreStripeCardRequest extends FormRequest
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
            'setup_intent_id' => ['required', 'string', 'max:255', 'regex:/^seti_[A-Za-z0-9_]+$/'],
            'learner' => ['nullable', 'integer'],
            'tutor' => ['nullable', 'integer'],
        ];
    }
}
