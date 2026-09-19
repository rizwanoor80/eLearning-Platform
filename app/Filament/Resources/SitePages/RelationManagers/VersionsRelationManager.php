<?php

namespace App\Filament\Resources\SitePages\RelationManagers;

use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Every published version stays readable here (read-only).
 */
class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Published versions';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('title'),
            TextEntry::make('version'),
            TextEntry::make('body')->columnSpanFull()->markdown(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('version')->sortable(),
                TextColumn::make('title'),
                TextColumn::make('published_at')->dateTime(),
                TextColumn::make('publishedBy.name')->label('Published by')->placeholder('—'),
            ])
            ->defaultSort('version', 'desc')
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
