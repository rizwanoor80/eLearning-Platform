<?php

namespace App\Filament\Resources\YearGroups\Pages;

use App\Filament\Concerns\AuditsResourceChanges;
use App\Filament\Resources\YearGroups\YearGroupResource;
use Filament\Resources\Pages\CreateRecord;

class CreateYearGroup extends CreateRecord
{
    use AuditsResourceChanges;

    protected static string $resource = YearGroupResource::class;

    protected function afterCreate(): void
    {
        $this->auditCreated('year_group.created');
    }
}
