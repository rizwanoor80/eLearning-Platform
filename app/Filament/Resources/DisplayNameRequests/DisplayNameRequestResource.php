<?php

namespace App\Filament\Resources\DisplayNameRequests;

use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\DisplayNameRequests\Pages\ListDisplayNameRequests;
use App\Filament\Resources\DisplayNameRequests\Tables\DisplayNameRequestsTable;
use App\Models\TutorProfile;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * R195 (15c): the queue of display names tutors have asked for. A request is "pending" while
 * `display_name_requested` is set; approving or rejecting it clears the column, so a row leaves the queue
 * by being decided. Read-only apart from the two actions on each row (`ApproveDisplayName`,
 * `RejectDisplayName`, both audited): no create, edit, delete or bulk action. The names in the queue have
 * already passed `DisplayNameRule`, so what the admin judges is taste and impersonation, not characters.
 */
class DisplayNameRequestResource extends Resource
{
    use RequiresActiveAdmin;

    protected static ?string $model = TutorProfile::class;

    protected static ?string $slug = 'display-name-requests';

    protected static ?string $navigationLabel = 'Display names';

    protected static ?string $modelLabel = 'display-name request';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereNotNull('display_name_requested')
            // R217: an anonymised tutor is never in the queue. Checked against `users.deleted_at` directly, as
            // `TutorProfile::scopeBookable()` does, because the `user()` relation includes trashed rows.
            ->whereExists(fn ($query) => $query->select(DB::raw(1))
                ->from('users')
                ->whereColumn('users.id', 'tutor_profiles.user_id')
                ->whereNull('users.deleted_at'))
            ->with('user');
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }

        $count = static::getEloquentQuery()->count();

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
        return DisplayNameRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDisplayNameRequests::route('/'),
        ];
    }
}
