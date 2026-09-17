<?php

namespace App\Support\Facades;

use App\Enums\SettingGroup;
use App\Models\User;
use App\Services\Settings\SettingsService;
use App\Support\Money;
use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed get(string $key, mixed $default = null)
 * @method static Money money(string $key)
 * @method static void set(string $key, mixed $value, SettingGroup $group = SettingGroup::Platform, ?User $by = null)
 * @method static void forget(string $key)
 *
 * @see SettingsService
 */
final class Settings extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SettingsService::class;
    }
}
