<?php

namespace App\Filament\Resources\DocumentTypes\Pages;

use App\Filament\Concerns\AuditsResourceChanges;
use App\Filament\Resources\DocumentTypes\DocumentTypeResource;
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
    }
}
