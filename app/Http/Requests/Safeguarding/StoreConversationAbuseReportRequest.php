<?php

namespace App\Http\Requests\Safeguarding;

use App\Models\Conversation;

class StoreConversationAbuseReportRequest extends AbuseReportRequest
{
    public function authorize(): bool
    {
        $conversation = $this->route('conversation');

        return $conversation instanceof Conversation && ($this->user()?->can('reportAbuse', $conversation) ?? false);
    }
}
