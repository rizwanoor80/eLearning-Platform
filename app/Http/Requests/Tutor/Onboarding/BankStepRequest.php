<?php

namespace App\Http\Requests\Tutor\Onboarding;

use App\Rules\NotSpreadsheetFormula;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BankStepRequest extends FormRequest
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
        return [
            'bank_name' => ['required', 'string', 'max:255', new NotSpreadsheetFormula],
            'bank_account_name' => ['required', 'string', 'max:255', new NotSpreadsheetFormula],
            'bank_iban' => ['required', 'string', 'max:34', new NotSpreadsheetFormula],
            'bank_swift' => ['nullable', 'string', 'max:11', new NotSpreadsheetFormula],
        ];
    }
}
