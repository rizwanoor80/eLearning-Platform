<?php

namespace App\Filament\Resources\YearGroups;

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

/**
 * The controlled year-group list per curriculum (R33). A year group that a
 * learner or a tutor's subject row points at cannot be deleted.
 */
class YearGroupResource extends Resource
{
    protected static ?string $model = YearGroup::class;

    protected static ?string $navigationLabel = 'Year groups';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

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
