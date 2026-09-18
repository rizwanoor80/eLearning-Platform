<?php

namespace App\Filament\Resources\PriceBands;

use App\Filament\Resources\PriceBands\Pages\CreatePriceBand;
use App\Filament\Resources\PriceBands\Pages\EditPriceBand;
use App\Filament\Resources\PriceBands\Pages\ListPriceBands;
use App\Filament\Resources\PriceBands\Schemas\PriceBandForm;
use App\Filament\Resources\PriceBands\Tables\PriceBandsTable;
use App\Models\PriceBand;
use App\Services\Pricing\PriceBandOverlapCheck;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PriceBandResource extends Resource
{
    protected static ?string $model = PriceBand::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    public static function form(Schema $schema): Schema
    {
        return PriceBandForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PriceBandsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPriceBands::route('/'),
            'create' => CreatePriceBand::route('/create'),
            'edit' => EditPriceBand::route('/{record}/edit'),
        ];
    }

    /**
     * Non-blocking (R26's data error caught at the source): saving still
     * succeeds, but if this tier's current bands no longer share a common
     * rate, warn — a tutor teaching those curricula together could not set
     * any valid hourly rate.
     */
    public static function warnIfBandsDoNotOverlap(?Model $band): void
    {
        if (! $band instanceof PriceBand) {
            return;
        }

        $conflicting = app(PriceBandOverlapCheck::class)->conflictingCurricula($band->level_tier);

        if ($conflicting === []) {
            return;
        }

        Notification::make()
            ->title('Price bands do not overlap')
            ->body(sprintf(
                'At the %s tier, %s have no rate in common. A tutor teaching them together will be unable to set any valid hourly rate.',
                $band->level_tier->value,
                implode(', ', $conflicting),
            ))
            ->warning()
            ->persistent()
            ->send();
    }
}
