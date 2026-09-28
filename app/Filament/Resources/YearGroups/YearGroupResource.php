<?php

namespace App\Filament\Resources\YearGroups;

use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\YearGroups\Pages\CreateYearGroup;
use App\Filament\Resources\YearGroups\Pages\EditYearGroup;
use App\Filament\Resources\YearGroups\Pages\ListYearGroups;
use App\Filament\Resources\YearGroups\Schemas\YearGroupForm;
use App\Filament\Resources\YearGroups\Tables\YearGroupsTable;
use App\Models\YearGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The controlled year-group list per curriculum (R33). A year group that a
 * learner or a tutor's subject row points at cannot be deleted.
 */
class YearGroupResource extends Resource
{
    use RequiresActiveAdmin;

    protected static ?string $model = YearGroup::class;

    protected static ?string $navigationLabel = 'Year groups';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    // RequiresActiveAdmin does not cover create (R149(f)): this resource has a create page and,
    // without a YearGroup policy, Filament's no-policy default is allow, so a disabled admin
    // would otherwise still be able to create one through an already-open Livewire tab.
    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    // The trait hard-codes delete to false, which would silently remove EditYearGroup's real,
    // guarded delete feature (only offered while nothing references the row, audited). Restoring
    // it here for an active admin only, so R149(f) gates a disabled admin without also removing a
    // feature (DECISION, CYCLE-LOG 9a item (f)).
    public static function canDelete(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDeleteAny(): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return YearGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return YearGroupsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListYearGroups::route('/'),
            'create' => CreateYearGroup::route('/create'),
            'edit' => EditYearGroup::route('/{record}/edit'),
        ];
    }
}
