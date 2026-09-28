<?php

namespace App\Filament\Resources\TutorProfiles;

use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\TutorProfiles\Pages\ListTutorProfiles;
use App\Filament\Resources\TutorProfiles\Pages\ViewTutorProfile;
use App\Filament\Resources\TutorProfiles\RelationManagers\TutorDocumentsRelationManager;
use App\Filament\Resources\TutorProfiles\Schemas\TutorProfileInfolist;
use App\Filament\Resources\TutorProfiles\Tables\TutorProfilesTable;
use App\Models\TutorProfile;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

// R149(f): `App\Policies\TutorProfilePolicy` exists (it covers `reportAbuse` only) but has no
// viewAny/view/update methods, so Filament's no-policy-method default was allow — this trait is
// this resource's only guard against a disabled admin, not a belt-and-suspenders addition.
class TutorProfileResource extends Resource
{
    use RequiresActiveAdmin;

    protected static ?string $model = TutorProfile::class;

    protected static ?string $navigationLabel = 'Tutor approvals';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function infolist(Schema $schema): Schema
    {
        return TutorProfileInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TutorProfilesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            TutorDocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTutorProfiles::route('/'),
            'view' => ViewTutorProfile::route('/{record}'),
        ];
    }
}
