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

        $moved = app(RequireDocumentTypeFromApprovedTutors::class)(auth()->user(), $record);

        if ($moved > 0) {
            Notification::make()->title("$moved approved tutor(s) moved to changes requested")->warning()->send();
        }
    }
}
