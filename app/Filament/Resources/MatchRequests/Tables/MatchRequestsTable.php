<?php

namespace App\Filament\Resources\MatchRequests\Tables;

use App\Enums\MatchRequestStatus;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MatchRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Received')->dateTime()->sortable(),
                TextColumn::make('account.name')->label('Parent')->searchable(),
                TextColumn::make('learner.display_name')->label('Learner'),
                TextColumn::make('curriculum.name')->label('Curriculum'),
                TextColumn::make('subject.name')->label('Subject'),
                TextColumn::make('year_group'),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('created_at')
            ->filters([
                SelectFilter::make('status')
                    ->options(MatchRequestStatus::class)
                    ->default(MatchRequestStatus::Open->value),
            ])
            ->recordActions([
                ViewAction::make()->label('Open'),
            ]);
    }
}
