<?php

namespace App\Http\Requests\Tutor\Onboarding;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DocumentStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * No document_type_id here — the controller derives which document type
     * is currently due from the same server-side step logic show() uses,
     * never from client input.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }
}
