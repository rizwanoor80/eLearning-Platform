<?php

namespace App\Http\Requests\Lessons;

use App\Enums\DisputeReason;
use App\Models\Lesson;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class StoreDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lesson = $this->route('lesson');

        return $lesson instanceof Lesson && ($this->user()?->can('openDispute', $lesson) ?? false);
    }

    /**
     * A stranger, or the lesson's own tutor, is told there is no such page — never that it is
     * forbidden — matching `StoreReviewRequest`'s precedent.
     */
    protected function failedAuthorization(): never
    {
        throw new NotFoundHttpException;
    }

    /**
     * Reason enum, description required up to 2000 characters (R150).
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', new Enum(DisputeReason::class)],
            'description' => ['required', 'string', 'max:2000'],
        ];
    }
}
