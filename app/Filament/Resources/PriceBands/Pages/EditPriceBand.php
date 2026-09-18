<?php

namespace App\Filament\Resources\PriceBands\Pages;

use App\Filament\Resources\PriceBands\PriceBandResource;
use App\Support\Money;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPriceBand extends EditRecord
{
    protected static string $resource = PriceBandResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * The model casts min/max to Money; the form edits whole fils, so hand
     * it integers rather than Money objects.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        foreach (['min_rate', 'max_rate'] as $key) {
            if ($data[$key] instanceof Money) {
                $data[$key] = $data[$key]->toFils();
            }
        }

        return $data;
    }

    protected function afterSave(): void
    {
        PriceBandResource::warnIfBandsDoNotOverlap($this->getRecord());
    }
}
