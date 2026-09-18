<?php

namespace App\Actions\Tutor;

use App\Actions\RecordAuditLog;
use App\Enums\TutorDocumentStatus;
use App\Models\TutorDocument;
use App\Models\User;

class ReviewTutorDocument
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    public function __invoke(User $admin, TutorDocument $document, TutorDocumentStatus $status): void
    {
        $before = ['status' => $document->status->value];

        $document->forceFill([
            'status' => $status,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ])->save();

        ($this->recordAuditLog)($admin, 'tutor_document.'.$status->value, $document, $before, [
            'status' => $status->value,
        ]);
    }
}
