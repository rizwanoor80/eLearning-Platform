<?php

namespace App\Http\Requests\Payments;

use App\Enums\TestCard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The fake add-card form: one of two named test cards, and optionally the learner and tutor of the
 * weekly-slot setup the parent came from, so they land back on it. No card number field exists.
 */
class StoreTestCardRequest extends FormRequest
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
            'card' => ['required', Rule::enum(TestCard::class)],
            'learner' => ['nullable', 'integer'],
            'tutor' => ['nullable', 'integer'],
        ];
    }
}
