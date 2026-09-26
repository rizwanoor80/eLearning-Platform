<?php

namespace App\Filament\Concerns;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Defence in depth for a resource whose actions move money or change credentials. Filament's own
 * `Authenticate` middleware is persistent, so a Livewire update over HTTP already re-runs
 * `canAccessPanel` (active admins only); this makes the resource refuse anyone else by itself, so a
 * component mounted outside that middleware — or a future panel — is not open by default.
 */
trait RequiresActiveAdmin
{
    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->role === Role::Admin && $user->status === UserStatus::Active;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
