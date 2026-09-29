<?php

namespace App\Filament\Resources\Audit;

use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\Audit\Pages\ListAuditLogs;
use App\Filament\Resources\Audit\Tables\AuditLogsTable;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * CP8 9d (R152): the audit log. Read-only by construction, not just by policy — there is no
 * create, edit, delete or bulk action anywhere on this resource (`RecordAuditLog` is the only
 * writer, matching `AuditLog`'s own append-only docblock). Mirrors `SafeguardingResource`'s
 * shape exactly: an explicit local `can*` override on top of `RequiresActiveAdmin`, one page,
 * the table split into its own class.
 */
class AuditLogResource extends Resource
{
    use RequiresActiveAdmin;

    protected static ?string $model = AuditLog::class;

    protected static ?string $slug = 'audit-log';

    protected static ?string $navigationLabel = 'Audit log';

    protected static ?string $modelLabel = 'audit entry';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

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
        return AuditLogsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
        ];
    }
}
