<?php

namespace App\Filament\Resources\MatchRequests;

use App\Filament\Resources\MatchRequests\Pages\ListMatchRequests;
use App\Filament\Resources\MatchRequests\Pages\ViewMatchRequest;
use App\Filament\Resources\MatchRequests\Schemas\MatchRequestInfolist;
use App\Filament\Resources\MatchRequests\Tables\MatchRequestsTable;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Models\MatchRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The admin match queue. Hidden and inaccessible while the `match_requests`
 * toggle is off — open requests stay stored and untouched until it is switched
 * back on.
 */
class MatchRequestResource extends Resource
{
    protected static ?string $model = MatchRequest::class;

    protected static ?string $navigationLabel = 'Match requests';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    public static function canAccess(): bool
    {
        return EnsureFeatureEnabled::enabled('match_requests') && parent::canAccess();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return MatchRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MatchRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMatchRequests::route('/'),
            'view' => ViewMatchRequest::route('/{record}'),
        ];
    }
}
