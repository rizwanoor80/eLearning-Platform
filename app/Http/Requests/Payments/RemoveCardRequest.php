<?php

namespace App\Http\Requests\Payments;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Removing the saved card; `confirm` is the parent's yes when an active weekly slot would lose its card.
 */
class RemoveCardRequest extends FormRequest
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
            'confirm' => ['nullable', 'boolean'],
            'learner' => ['nullable', 'integer'],
        ];
    }
}
