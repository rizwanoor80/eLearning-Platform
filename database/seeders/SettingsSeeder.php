<?php

namespace Database\Seeders;

use App\Enums\SettingGroup;
use App\Support\Facades\Settings;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /**
     * Seeds every key in config('settings.defaults') into its group from
     * config('settings.groups'), so `SettingsSeeder` and the Filament
     * settings editor's tabs agree on where each key lives without a
     * duplicated key list.
     */
    public function run(): void
    {
        /** @var array<string, mixed> $defaults */
        $defaults = config('settings.defaults', []);
        /** @var array<string, list<string>> $groups */
        $groups = config('settings.groups', []);

        foreach ($groups as $groupValue => $keys) {
            $group = SettingGroup::from($groupValue);

            foreach ($keys as $key) {
                Settings::set($key, $defaults[$key], $group);
            }
        }
    }
}
