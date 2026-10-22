<?php

namespace App\Filament\Resources\Accounts;

use App\Enums\Role;
use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Accounts\Tables\AccountsTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * R273 (26a): parent and tutor accounts, with one row action, "Erase account" (`EraseAccount` → `AnonymizeUser`). The
 * Admin users resource (`UserResource`) is scoped to `role = admin` and has a create page, so it cannot host this.
 * Read-only otherwise: no create, edit, delete, restore or bulk action, and the default scope keeps erased (trashed)
 * users out, so a tombstone can never be restored or soft-deleted again from here.
 */
class AccountResource extends Resource
{
    use RequiresActiveAdmin;

    protected static ?string $model = User::class;

    protected static ?string $slug = 'accounts';

    protected static ?string $modelLabel = 'account';

    protected static ?string $navigationLabel = 'Accounts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('role', '!=', Role::Admin);
    }

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

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return AccountsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccounts::route('/'),
        ];
    }
}
