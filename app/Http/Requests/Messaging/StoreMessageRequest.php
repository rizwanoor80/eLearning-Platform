<?php

namespace App\Http\Requests\Messaging;

use App\Models\Conversation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $conversation = $this->route('conversation');

        return $conversation instanceof Conversation && ($this->user()?->can('send', $conversation) ?? false);
    }

    /**
     * A stranger is told there is no such conversation, not that it is forbidden.
     */
    protected function failedAuthorization(): never
    {
        throw new NotFoundHttpException;
    }

    /**
     * Plain text, at most 2000 characters. The body is also excluded from the flashed session input
     * (`bootstrap/app.php`), so a rejected message is never kept unmasked in the session.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:2000'],
        ];
    }
}
