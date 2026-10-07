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
                // R171: no document type is `required` any more (CV included — it's satisfied by
                // either a CV upload or a LinkedIn URL), so `hasAllRequiredDocumentsAccepted()` is
                // now vacuously true for every tutor and would show a meaningless green check here.
                // `hasCvOrLinkedin()` is the one document-adjacent fact still worth a reviewer's
                // attention, though by `pending_review` it is already guaranteed true (`complete()`
                // requires it) — kept visible for at-a-glance confirmation during review.
                IconColumn::make('has_cv_or_linkedin')
                    ->label('CV/LinkedIn')
                    ->boolean()
                    ->getStateUsing(fn ($record) => $record->hasCvOrLinkedin()),
                TextColumn::make('submitted_at')
                    ->label('Submitted')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('submitted_at')
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
