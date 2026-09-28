<?php

use App\Enums\UserStatus;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * R149(f). Every Filament resource must refuse a disabled admin, either through
 * `App\Filament\Concerns\RequiresActiveAdmin` or (for the two resources it composes with a
 * still-tighter policy — `MatchRequestPolicy`, `RecurringSlotPolicy`) an equivalent active-admin
 * check.
 *
 * `canAccessPanel()` already blocks a suspended admin's full page loads — Filament's own
 * `Authenticate` middleware calls it on every panel route — so a test built on an HTTP request
 * would pass whether or not a resource has its own guard, the same vacuity the design consult
 * flagged for R149(d)'s trusted-proxies test. This app has no published config/livewire.php, so
 * Livewire's own `/livewire/update` endpoint runs Livewire's default `web`-only middleware group,
 * not the panel's `authMiddleware` — an admin already viewing a resource when an admin suspends
 * them can still fire Livewire actions against it unless the resource's own can*() methods refuse
 * them. This file calls those methods directly, bypassing HTTP and `canAccessPanel()` entirely,
 * so it actually proves the resource-level gate rather than the panel-level one.
 *
 * The dataset is sourced from a filesystem glob of app/Filament/Resources, not
 * `Filament::getPanel('admin')->getResources()`, because Pest evaluates a `with()` dataset
 * closure at collection time, before Laravel boots — both the `Filament` facade and the
 * `app_path()` helper throw there (confirmed empirically: "A facade root has not been set." /
 * "Call to undefined method Container::path()"). The test right below this one proves the glob
 * and the live panel registry list exactly the same classes, so a resource added later for 9b's
 * disputes or 9d's audit log is covered automatically — no edit to this file.
 */
function discoveredFilamentResourceClasses(): array
{
    $paths = glob(__DIR__.'/../../../app/Filament/Resources/*/*Resource.php');

    return collect($paths)
        ->map(function (string $path): string {
            $directory = basename(dirname($path));
            $class = basename($path, '.php');

            return "App\\Filament\\Resources\\{$directory}\\{$class}";
        })
        ->sort()
        ->values()
        ->all();
}

it('discovers exactly the resources the admin panel itself has registered (R149(f))', function () {
    $glob = discoveredFilamentResourceClasses();
    $registered = collect(Filament::getPanel('admin')->getResources())->sort()->values()->all();

    expect($glob)->toBe($registered);
});

it('refuses a disabled admin every ability on a Filament resource, and keeps them for an active one (R149(f))', function (string $resourceClass) {
    expect(class_exists($resourceClass))->toBeTrue();

    $activeAdmin = User::factory()->admin()->create(['status' => UserStatus::Active]);
    $disabledAdmin = User::factory()->admin()->create(['status' => UserStatus::Suspended]);

    $modelClass = $resourceClass::getModel();
    $record = new $modelClass;

    // Positive control: without this, a resource whose methods always return false would pass
    // vacuously.
    test()->actingAs($activeAdmin);
    expect($resourceClass::canViewAny())->toBeTrue();

    test()->actingAs($disabledAdmin);
    expect($resourceClass::canViewAny())->toBeFalse()
        ->and($resourceClass::canAccess())->toBeFalse()
        ->and($resourceClass::canView($record))->toBeFalse()
        ->and($resourceClass::canEdit($record))->toBeFalse();

    // canCreate() is not part of RequiresActiveAdmin, so it is only meaningful — and only
    // asserted — where the resource actually registers a create page.
    if (array_key_exists('create', $resourceClass::getPages())) {
        expect($resourceClass::canCreate())->toBeFalse();
    }
})->with('filamentResources');

dataset('filamentResources', fn () => discoveredFilamentResourceClasses());
