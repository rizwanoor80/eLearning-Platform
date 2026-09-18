<?php

namespace App\Filament\Resources\DocumentTypes\Pages;

use App\Filament\Concerns\AuditsResourceChanges;
use App\Filament\Resources\DocumentTypes\DocumentTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDocumentType extends CreateRecord
{
    use AuditsResourceChanges;

    protected static string $resource = DocumentTypeResource::class;

    protected function afterCreate(): void
    {
        $this->auditCreated('document_type.created');
    }
}
