<?php

namespace App\Filament\Resources\PriceBands\Pages;

use App\Filament\Concerns\AuditsResourceChanges;
use App\Filament\Resources\PriceBands\PriceBandResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePriceBand extends CreateRecord
{
    use AuditsResourceChanges;

    protected static string $resource = PriceBandResource::class;

    protected function afterCreate(): void
    {
        $this->auditCreated('price_band.created');
        PriceBandResource::warnIfBandsDoNotOverlap($this->getRecord());
    }
}
