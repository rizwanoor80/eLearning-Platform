<?php

namespace App\Filament\Resources\AccountDeletionRequests;

use App\Enums\AccountDeletionRequestStatus;
use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\AccountDeletionRequests\Pages\ListAccountDeletionRequests;
use App\Filament\Resources\AccountDeletionRequests\Tables\AccountDeletionRequestsTable;
use App\Models\AccountDeletionRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * R273 (26a): the queue of "delete my account" requests from parents and tutors. Open requests by default; the
 * status filter shows the handled ones as history. Read-only apart from the two row actions, "Erase account"
 * (`EraseAccount`) and "Decline" (`DeclineAccountDeletionRequest`, with a note mailed to the user).
 */
class AccountDeletionRequestResource extends Resource
{
    use RequiresActiveAdmin;

    protected static ?string $model = AccountDeletionRequest::class;

    protected static ?string $slug = 'account-deletion-requests';

    protected static ?string $navigationLabel = 'Deletion requests';

    protected static ?string $modelLabel = 'deletion request';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrash;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'handler']);
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }

        $count = AccountDeletionRequest::query()->where('status', AccountDeletionRequestStatus::Open)->count();

        return $count > 0 ? (string) $count : null;
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

    public static function table(Table $table): Table
    {
        return AccountDeletionRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccountDeletionRequests::route('/'),
        ];
    }
}
