<?php

namespace App\Services\Settings;

use App\Enums\SettingGroup;
use App\Models\Setting;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;

final class SettingsService
{
    public function get(string $key, mixed $default = null): mixed
    {
        $cached = Cache::rememberForever($this->cacheKey($key), function () use ($key) {
            $setting = Setting::query()->where('key', $key)->first();

            return ['exists' => $setting !== null, 'value' => $setting?->value];
        });

        if (! $cached['exists']) {
            return $default ?? config("settings.defaults.{$key}");
        }

        return $cached['value'];
    }

    public function money(string $key): Money
    {
        return Money::fils((int) $this->get($key));
    }

    public function set(string $key, mixed $value, SettingGroup $group = SettingGroup::Platform, ?User $by = null): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $key],
            [
                'group' => $group,
                'value' => $value,
                'updated_by' => $by?->id,
                'updated_at' => now(),
            ],
        );

        Cache::forget($this->cacheKey($key));
    }

    public function forget(string $key): void
    {
        Cache::forget($this->cacheKey($key));
    }

    private function cacheKey(string $key): string
    {
        return "settings.{$key}";
    }
}
