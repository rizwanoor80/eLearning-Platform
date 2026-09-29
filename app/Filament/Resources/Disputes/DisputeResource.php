<?php

namespace App\Filament\Resources\Disputes;

use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\Disputes\Pages\ListDisputes;
use App\Filament\Resources\Disputes\Tables\DisputesTable;
use App\Models\Dispute;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * CP8 (R150): the dispute queue. A dispute is only ever opened by `OpenDispute` (the account
 * holder, over HTTP) and only ever resolved by `ResolveDispute` (below, admin-only) — there is no
 * create or edit here, and delete is never offered (append-only ledger history depends on the
 * dispute row surviving), matching `SafeguardingResource`'s precedent.
 */
class DisputeResource extends Resource
{
    use RequiresActiveAdmin;

    protected static ?string $model = Dispute::class;

    protected static ?string $slug = 'disputes';

    protected static ?string $navigationLabel = 'Disputes';

    protected static ?string $modelLabel = 'dispute';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

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
        return DisputesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDisputes::route('/'),
        ];
    }
}
