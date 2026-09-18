<?php

namespace App\Filament\Resources\PriceBands\Schemas;

use App\Enums\LevelTier;
use App\Support\Money;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PriceBandForm
{
    /**
     * Rates are integer fils (invariant #3) — entered and stored as whole
     * fils, never as a decimal/float.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('curriculum_id')
                    ->relationship('curriculum', 'name')
                    ->required(),
                Select::make('level_tier')
                    ->options(LevelTier::class)
                    ->required(),
                TextInput::make('min_rate')
                    ->label('Minimum rate (fils)')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->required()
                    ->formatStateUsing(fn ($state) => $state instanceof Money ? $state->toFils() : $state),
                TextInput::make('max_rate')
                    ->label('Maximum rate (fils)')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->required()
                    ->gte('min_rate')
                    ->formatStateUsing(fn ($state) => $state instanceof Money ? $state->toFils() : $state),
                DatePicker::make('effective_from')
                    ->required(),
            ]);
    }
}
