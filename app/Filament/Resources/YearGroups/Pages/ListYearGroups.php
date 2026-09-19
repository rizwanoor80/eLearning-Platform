<?php

namespace App\Filament\Resources\YearGroups\Pages;

use App\Filament\Resources\YearGroups\YearGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListYearGroups extends ListRecords
{
    protected static string $resource = YearGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
