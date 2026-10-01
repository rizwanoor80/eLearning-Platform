<?php

namespace App\Http\Requests\Tutor\Onboarding;

use App\Models\DocumentType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DocumentStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * R171: every document type is now reachable at any time via the onboarding checklist, not
     * derived from a server-side "current step" — so the client must say which type it is
     * uploading against. Still constrained to an active type (`Rule::exists` with the `active`
     * scope), never an arbitrary id, and the controller further confirms the tutor owns the
     * profile before writing anything. The CV type additionally accepts DOC/DOCX (R171); every
     * other type keeps the original PDF/JPG/PNG set.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isCv = DocumentType::query()
            ->where('id', $this->input('document_type_id'))
            ->where('code', DocumentType::CV_CODE)
            ->exists();

        $mimes = $isCv ? 'mimes:pdf,doc,docx,jpg,jpeg,png' : 'mimes:pdf,jpg,jpeg,png';

        return [
            'document_type_id' => [
                'required',
                'integer',
                Rule::exists('document_types', 'id')->where('active', true),
            ],
            'file' => ['required', 'file', $mimes, 'max:10240'],
        ];
    }
}
