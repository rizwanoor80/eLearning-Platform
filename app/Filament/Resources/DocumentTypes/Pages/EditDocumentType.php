<?php

namespace App\Filament\Resources\DocumentTypes\Pages;

use App\Actions\Tutor\RequireDocumentTypeFromApprovedTutors;
use App\Filament\Concerns\AuditsResourceChanges;
use App\Filament\Resources\DocumentTypes\DocumentTypeResource;
use App\Models\DocumentType;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/**
 * No delete: a type with uploaded documents is protected by a restrictOnDelete
 * foreign key. Setting it inactive is the supported way to retire one.
 */
class EditDocumentType extends EditRecord
{
    use AuditsResourceChanges;

    protected static string $resource = DocumentTypeResource::class;

    protected function afterSave(): void
    {
        $this->auditUpdated('document_type.updated');

        // R36 (b): switching a type to required-and-active sends approved tutors who lack it back.
        // Turning `required` off, or renaming, moves nobody (and moves nobody back).
        $record = $this->getRecord();

        if ($record instanceof DocumentType && $record->wasChanged(['required', 'active'])) {
            $result = app(RequireDocumentTypeFromApprovedTutors::class)->run(auth()->user(), $record);

            if ($result['moved'] > 0) {
                Notification::make()->title("{$result['moved']} approved tutor(s) moved to changes requested")->warning()->send();
            }

            if ($result['failed'] > 0) {
                Notification::make()->title("{$result['failed']} approved tutor(s) could not be moved")->body('Save the document type again to retry; the failure was reported.')->danger()->send();
            }
        }
    }
}
