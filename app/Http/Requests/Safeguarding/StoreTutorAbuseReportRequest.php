<?php

namespace App\Http\Requests\Safeguarding;

use App\Models\TutorProfile;

class StoreTutorAbuseReportRequest extends AbuseReportRequest
{
    public function authorize(): bool
    {
        $tutor = $this->route('tutor');

        return $tutor instanceof TutorProfile && ($this->user()?->can('reportAbuse', $tutor) ?? false);
    }
}
