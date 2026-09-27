<?php

namespace App\Filament\Resources\Safeguarding;

use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\Safeguarding\Pages\ListAbuseReports;
use App\Filament\Resources\Safeguarding\Tables\AbuseReportsTable;
use App\Models\AbuseReport;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * CP7 8d (R137/R138): the safeguarding queue. A report is only ever written by
 * `FileAbuseReport`; there is no create or edit here, and delete is never offered
 * (history preserved, per CP7's acceptance line) — the default Filament delete actions are
 * stripped by never adding them, matching `ReviewResource`'s precedent.
 */
class SafeguardingResource extends Resource
{
    use RequiresActiveAdmin;

    protected static ?string $model = AbuseReport::class;

    protected static ?string $slug = 'safeguarding';

    protected static ?string $navigationLabel = 'Safeguarding';

    protected static ?string $modelLabel = 'report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return AbuseReportsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAbuseReports::route('/'),
        ];
    }
}
