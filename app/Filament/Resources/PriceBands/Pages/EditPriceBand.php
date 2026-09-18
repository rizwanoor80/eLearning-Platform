<?php

namespace App\Filament\Resources\PriceBands\Pages;

use App\Filament\Concerns\AuditsResourceChanges;
use App\Filament\Resources\PriceBands\PriceBandResource;
use App\Support\Money;
use Filament\Resources\Pages\EditRecord;

/**
 * No delete: bands are versioned by `effective_from`, and deleting one
 * rewrites the basis tutors' rates were validated against. Add a new band
 * with a later effective date instead.
 */
class EditPriceBand extends EditRecord
{
    use AuditsResourceChanges;

    protected static string $resource = PriceBandResource::class;

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
        $this->auditUpdated('price_band.updated');
        PriceBandResource::warnIfBandsDoNotOverlap($this->getRecord());
    }
}
