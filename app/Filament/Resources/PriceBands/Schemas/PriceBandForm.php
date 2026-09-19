<?php

namespace App\Filament\Resources\PriceBands\Schemas;

use App\Enums\LevelTier;
use App\Models\PriceBand;
use App\Support\Money;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

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
                    ->required()
                    ->rules(fn (Get $get, ?PriceBand $record): array => [
                        Rule::unique('price_bands', 'effective_from')
                            ->where('curriculum_id', $get('curriculum_id'))
                            ->where('level_tier', $get('level_tier'))
                            ->ignore($record?->id),
                    ])
                    ->validationMessages(['unique' => 'A band for this curriculum and level already starts on that date. Edit that band, or choose another date.']),
            ]);
    }
}
