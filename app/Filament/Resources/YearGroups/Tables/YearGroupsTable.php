<?php

namespace App\Filament\Resources\YearGroups\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class YearGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('curriculum.name')->sortable(),
                TextColumn::make('label')->searchable(),
                TextColumn::make('code'),
                TextColumn::make('sort')->sortable(),
                TextColumn::make('level_tier')->badge(),
            ])
            ->defaultSort('sort')
            ->filters([
                SelectFilter::make('curriculum_id')->relationship('curriculum', 'name')->label('Curriculum'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
