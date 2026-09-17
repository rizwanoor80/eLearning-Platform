<?php

use App\Enums\SettingGroup;
use App\Models\User;
use App\Support\Facades\Settings;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('falls back to the config default when no row exists', function () {
    expect(Settings::get('commission_pct'))->toBe(25);
});

it('returns null when no row exists and no config default is set', function () {
    expect(Settings::get('does_not_exist'))->toBeNull();
});

it('returns an explicit default over the config default when given', function () {
    expect(Settings::get('does_not_exist', 'fallback'))->toBe('fallback');
});

it('persists a value and reflects it on the next read', function () {
    Settings::set('commission_pct', 30);

    expect(Settings::get('commission_pct'))->toBe(30);
    expect(DB::table('settings')->where('key', 'commission_pct')->count())->toBe(1);
});

it('serves the cached value even after the row is changed directly in the database', function () {
    Settings::set('commission_pct', 25);
    Settings::get('commission_pct'); // warm the cache

    DB::table('settings')->where('key', 'commission_pct')->update(['value' => json_encode(99)]);

    expect(Settings::get('commission_pct'))->toBe(25);
});

it('busts the cache on forget so the next read hits the database', function () {
    Settings::set('commission_pct', 25);
    Settings::get('commission_pct'); // warm the cache

    DB::table('settings')->where('key', 'commission_pct')->update(['value' => json_encode(99)]);
    Settings::forget('commission_pct');

    expect(Settings::get('commission_pct'))->toBe(99);
});

it('records who made the change', function () {
    $admin = User::factory()->admin()->create();

    Settings::set('commission_pct', 30, SettingGroup::Platform, $admin);

    expect(DB::table('settings')->where('key', 'commission_pct')->value('updated_by'))->toBe($admin->id);
});

it('stores the given group', function () {
    Settings::set('commission_pct', 30, SettingGroup::Platform);

    expect(DB::table('settings')->where('key', 'commission_pct')->value('group'))->toBe('platform');
});

it('reads a fils-valued key as Money', function () {
    Settings::set('payout_min', 20000);

    $money = Settings::money('payout_min');

    expect($money)->toBeInstanceOf(Money::class)
        ->and($money->toFils())->toBe(20000);
});

it('reads the config-default fils value as Money when no row exists', function () {
    expect(Settings::money('payout_min')->toFils())->toBe(20000);
});
