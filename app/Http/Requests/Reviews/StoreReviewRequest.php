<?php

namespace App\Http\Requests\Reviews;

use App\Models\Lesson;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class StoreReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lesson = $this->route('lesson');

        return $lesson instanceof Lesson && ($this->user()?->can('review', $lesson) ?? false);
    }

    /**
     * A stranger, or a tutor, is told there is no such page — never that it is forbidden — matching
     * the messaging and progress-report routes.
     */
    protected function failedAuthorization(): never
    {
        throw new NotFoundHttpException;
    }

    /**
     * 1–5, an optional comment up to 1000 characters (R136). `comment` is also excluded from the
     * flashed session input (`bootstrap/app.php`), so a rejected review never keeps an unmasked
     * comment in the session.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
