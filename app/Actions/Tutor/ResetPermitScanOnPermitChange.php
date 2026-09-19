<?php

namespace App\Actions\Tutor;

use App\Actions\RecordAuditLog;
use App\Enums\TutorDocumentStatus;
use App\Models\DocumentType;
use App\Models\TutorDocument;
use App\Models\TutorProfile;

class ResetPermitScanOnPermitChange
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    /**
     * R36 (g): an accepted permit scan vouches for the permit number and date
     * it was reviewed against. When the tutor changes either, the scan goes
     * back to `pending` (reviewer cleared) so the admin looks at it again —
     * otherwise a new expiry date would ride on an old approval. A rejected or
     * already pending scan is left alone, and so is a profile with no permit
     * document type or scan. Returns whether a scan was reset.
     */
    public function __invoke(TutorProfile $profile): bool
    {
        $type = DocumentType::query()->where('code', DocumentType::PERMIT_CODE)->first();

        if ($type === null) {
            return false;
        }

        $scan = $profile->tutorDocuments()
            ->where('document_type_id', $type->id)
            ->where('status', TutorDocumentStatus::Accepted)
            ->first();

        if (! $scan instanceof TutorDocument) {
            return false;
        }

        $scan->forceFill([
            'status' => TutorDocumentStatus::Pending,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ])->save();

        ($this->recordAuditLog)($profile->user, 'tutor_document.reset_to_pending', $scan,
            ['status' => TutorDocumentStatus::Accepted->value],
            ['status' => TutorDocumentStatus::Pending->value, 'reason' => 'permit_details_changed'],
        );

        return true;
    }
}
