<?php

namespace App\Filament\Resources\RecurringSlots\Schemas;

use App\Enums\RecurringSlotPauseReason;
use App\Enums\RecurringSlotStatus;
use App\Models\RecurringSlot;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class RecurringSlotInfolist
{
    /**
     * Carbon's `dayOfWeek` numbering, the one `recurring_slots.weekday` uses.
     */
    public const WEEKDAYS = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('learner.display_name')->label('Learner'),
                TextEntry::make('tutorProfile.user.name')->label('Tutor'),
                TextEntry::make('status')->badge()->formatStateUsing(fn (RecurringSlotStatus $state): string => ucfirst($state->value)),
                TextEntry::make('paused_reason')
                    ->placeholder('—')
                    ->formatStateUsing(fn (RecurringSlotPauseReason $state): string => str_replace('_', ' ', ucfirst($state->value))),
                TextEntry::make('curriculum.name')->label('Curriculum'),
                TextEntry::make('subject.name')->label('Subject'),
                TextEntry::make('weekday')->formatStateUsing(fn (int $state): string => self::WEEKDAYS[$state] ?? (string) $state),
                TextEntry::make('start_time')->label('Time (tutor local)')->formatStateUsing(fn (string $state): string => substr($state, 0, 5)),
                TextEntry::make('timezone'),
                TextEntry::make('price')->label('Price per lesson')->formatStateUsing(fn (RecurringSlot $record): string => $record->price->format()),
                TextEntry::make('starts_on')->date(),
                TextEntry::make('ends_on')->date()->placeholder('Open-ended'),
                TextEntry::make('end_effective_on')->label('Tutor notice ends')->date()->placeholder('—'),
                TextEntry::make('generated_until')->date(),
                TextEntry::make('consecutive_charge_failures')->label('Consecutive charge failures'),
                TextEntry::make('createdBy.name')->label('Set up by'),
            ])
            ->columns(2);
    }
}
