<?php

namespace App\Filament\Resources\PriceBands\Pages;

use App\Filament\Resources\PriceBands\PriceBandResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePriceBand extends CreateRecord
{
    protected static string $resource = PriceBandResource::class;

    protected function afterCreate(): void
    {
        PriceBandResource::warnIfBandsDoNotOverlap($this->getRecord());
    }
}
