<?php

namespace App\Filament\Resources\TutorProfiles\Tables;

use App\Enums\TutorProfileStatus;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TutorProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('Tutor')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('permit_expires_at')
                    ->date()
                    ->sortable(),
                IconColumn::make('documents_complete')
                    ->label('Docs')
                    ->boolean()
                    ->getStateUsing(fn ($record) => $record->hasAllRequiredDocumentsAccepted()),
                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at')
            ->filters([
                SelectFilter::make('status')
                    ->options(TutorProfileStatus::class)
                    ->default(TutorProfileStatus::PendingReview->value),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Review'),
            ]);
    }
}
