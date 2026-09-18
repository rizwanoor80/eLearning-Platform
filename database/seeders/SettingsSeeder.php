<?php

namespace Database\Seeders;

use App\Enums\SettingGroup;
use App\Models\Setting;
use App\Support\Facades\Settings;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /**
     * Inserts every key in config('settings.defaults') that has no row yet,
     * into its group from config('settings.groups'). It NEVER overwrites an
     * existing row (R29): once an admin edits a value in the Filament
     * settings editor, a re-seed must not clobber it — `site_name` above all.
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
                if (Setting::query()->where('key', $key)->exists()) {
                    continue;
                }

                Settings::set($key, $defaults[$key], $group);
            }
        }
    }
}
