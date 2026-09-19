<?php

namespace App\Filament\Resources\DocumentTypes\Pages;

use App\Actions\Tutor\RequireDocumentTypeFromApprovedTutors;
use App\Filament\Concerns\AuditsResourceChanges;
use App\Filament\Resources\DocumentTypes\DocumentTypeResource;
use App\Models\DocumentType;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateDocumentType extends CreateRecord
{
    use AuditsResourceChanges;

    protected static string $resource = DocumentTypeResource::class;

    protected function afterCreate(): void
    {
        $this->auditCreated('document_type.created');

        // R36 (b): a new required, active type sends approved tutors who lack it back.
        $record = $this->getRecord();

        if (! $record instanceof DocumentType) {
            return;
        }

        $result = app(RequireDocumentTypeFromApprovedTutors::class)->run(auth()->user(), $record);

        if ($result['moved'] > 0) {
            Notification::make()->title("{$result['moved']} approved tutor(s) moved to changes requested")->warning()->send();
        }

        if ($result['failed'] > 0) {
            Notification::make()->title("{$result['failed']} approved tutor(s) could not be moved")->body('The failure was reported. Those tutors are still approved; turning the required flag off and on again retries them.')->danger()->send();
        }
    }
}
