<?php

namespace App\Filament\Resources\RecurringSlots\Tables;

use App\Enums\RecurringSlotStatus;
use App\Filament\Resources\RecurringSlots\Schemas\RecurringSlotInfolist;
use App\Models\RecurringSlot;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RecurringSlotsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('learner.display_name')->label('Learner')->searchable(),
                TextColumn::make('tutorProfile.user.name')->label('Tutor'),
                TextColumn::make('weekday')
                    ->formatStateUsing(fn (int $state): string => RecurringSlotInfolist::WEEKDAYS[$state] ?? (string) $state),
                TextColumn::make('start_time')->label('Time')->formatStateUsing(fn (string $state): string => substr($state, 0, 5)),
                TextColumn::make('timezone'),
                TextColumn::make('price')->formatStateUsing(fn (RecurringSlot $record): string => $record->price->format()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (RecurringSlotStatus $state): string => ucfirst($state->value)),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    RecurringSlotStatus::Active->value => 'Active',
                    RecurringSlotStatus::Paused->value => 'Paused',
                    RecurringSlotStatus::Ended->value => 'Ended',
                ]),
            ])
            ->recordActions([
                ViewAction::make()->label('Open'),
            ]);
    }
}
