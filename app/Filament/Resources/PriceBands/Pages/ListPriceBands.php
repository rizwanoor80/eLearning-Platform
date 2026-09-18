<?php

namespace App\Filament\Resources\PriceBands\Pages;

use App\Filament\Resources\PriceBands\PriceBandResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPriceBands extends ListRecords
{
    protected static string $resource = PriceBandResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
