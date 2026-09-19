<?php

namespace App\Filament\Resources\SitePages\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SitePagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('slug'),
                TextColumn::make('version')->label('Published version'),
                TextColumn::make('published_at')->dateTime()->sortable(),
            ])
            ->defaultSort('slug')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
