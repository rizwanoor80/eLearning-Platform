<?php

namespace App\Filament\Resources\PayoutMethods\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PayoutMethodsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('code')->badge(),
                IconColumn::make('is_active')->label('Offered')->boolean(),
                TextColumn::make('countries')
                    ->badge()
                    ->placeholder('All countries'),
            ])
            ->defaultSort('sort')
            ->recordActions([EditAction::make()]);
    }
}
