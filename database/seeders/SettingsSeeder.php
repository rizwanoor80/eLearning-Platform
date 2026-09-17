<?php

namespace Database\Seeders;

use App\Support\Facades\Settings;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /**
     * Platform-group keys only, per CP0 scope — site/mail/features keys and
     * the Filament editor land in CP1. Reads the same list SettingsService
     * falls back to, so there is one source of truth for these defaults.
     */
    public function run(): void
    {
        /** @var array<string, mixed> $defaults */
        $defaults = config('settings.defaults', []);

        foreach ($defaults as $key => $value) {
            Settings::set($key, $value);
        }
    }
}
