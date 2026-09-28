<?php

namespace App\Http\Requests\Safeguarding;

use App\Models\Lesson;

class StoreLessonAbuseReportRequest extends AbuseReportRequest
{
    public function authorize(): bool
    {
        $lesson = $this->route('lesson');

        return $lesson instanceof Lesson && ($this->user()?->can('reportAbuse', $lesson) ?? false);
    }
}
