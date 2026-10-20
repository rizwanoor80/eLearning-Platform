<?php

namespace App\Filament\Resources\TutorProfiles\Tables;

use App\Enums\PayoutMethodCode;
use App\Enums\TutorProfileStatus;
use App\Models\TutorProfile;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                // R249: built with ->state(), never on the raw columns (the bank and email columns are encrypted).
                TextColumn::make('payout')
                    ->label('Payout')
                    ->state(fn (TutorProfile $record): string => $record->canBePaid()
                        ? (PayoutMethodCode::tryFrom((string) $record->payout_method)?->label() ?? '')
                        : 'Cannot be paid yet')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Cannot be paid yet' ? 'warning' : 'success'),
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
                TernaryFilter::make('cannot_be_paid')
                    ->label('Cannot be paid yet')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->scopes(['cannotBePaid']),
                        false: fn (Builder $query): Builder => $query->whereNot(fn (Builder $q) => $q->scopes(['cannotBePaid'])),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Review'),
            ]);
    }
}
