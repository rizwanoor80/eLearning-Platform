<?php

namespace App\Filament\Resources\RecurringSlots;

use App\Filament\Resources\RecurringSlots\Pages\ListRecurringSlots;
use App\Filament\Resources\RecurringSlots\Pages\ViewRecurringSlot;
use App\Filament\Resources\RecurringSlots\Schemas\RecurringSlotInfolist;
use App\Filament\Resources\RecurringSlots\Tables\RecurringSlotsTable;
use App\Models\RecurringSlot;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The admin view of weekly slots: list, view, pause, resume, end, and set up a slot with the
 * trial override (R96). Every action goes through the slot Actions, which audit themselves.
 * There is no create or edit page: a slot is set up from the list's header action and is never
 * edited, only paused, resumed or ended.
 */
class RecurringSlotResource extends Resource
{
    protected static ?string $model = RecurringSlot::class;

    protected static ?string $navigationLabel = 'Weekly slots';

    protected static ?string $modelLabel = 'weekly slot';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return RecurringSlotInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecurringSlotsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecurringSlots::route('/'),
            'view' => ViewRecurringSlot::route('/{record}'),
        ];
    }
}
