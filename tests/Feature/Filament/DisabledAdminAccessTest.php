<?php

use App\Enums\UserStatus;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * R149(f). Every Filament resource must refuse a disabled admin, either through
 * `App\Filament\Concerns\RequiresActiveAdmin` or (for the two resources it composes with a
 * still-tighter policy — `MatchRequestPolicy`, `RecurringSlotPolicy`) an equivalent active-admin
 * check.
 *
 * `canAccessPanel()` already blocks a suspended admin's full page loads — Filament's own
 * `Authenticate` middleware calls it on every panel route — so a test built on an HTTP request
 * would pass whether or not a resource has its own guard, the same vacuity the design consult
 * flagged for R149(d)'s trusted-proxies test. `Authenticate` also re-runs on a Livewire component
 * update, not just the initial page load — `FilamentServiceProvider::boot()` registers it via
 * `Livewire::addPersistentMiddleware()` — so an admin already viewing a resource when they are
 * suspended cannot fire a further Livewire action either; there is no live gap there. What stays
 * vacuous is testing through `Livewire::test()` itself: that helper mounts the component
 * in-process and never dispatches an HTTP request, so no middleware — persistent or otherwise,
 * `canAccessPanel()` included — ever runs against it. This file calls the resource's own can*()
 * methods directly and drives its Livewire assertions through `Livewire::test()`, so both bypass
 * `canAccessPanel()` entirely and the resulting 403 can only be the resource-level gate, never
 * the panel-level one.
 *
 * The dataset is sourced from a filesystem glob of app/Filament/Resources, not
 * `Filament::getPanel('admin')->getResources()`, because Pest evaluates a `with()` dataset
 * closure at collection time, before Laravel boots — both the `Filament` facade and the
 * `app_path()` helper throw there (confirmed empirically: "A facade root has not been set." /
 * "Call to undefined method Container::path()"). The test right below this one proves the glob
 * and the live panel registry list exactly the same classes, so a resource added later for 9b's
 * disputes or 9d's audit log is covered automatically — no edit to this file.
 *
 * R149(f)'s literal wording is "a disabled admin gets 403". The can*() assertions above prove the
 * guard is wired correctly on every ability, but none of them is itself an HTTP status code, so a
 * second dataset test below mounts each resource's own index page directly via `Livewire::test()`
 * and asserts a literal `assertForbidden()` (403). This is deliberately not `->get($url)`: an HTTP
 * request would run through the panel's route middleware first, and `canAccessPanel()` already
 * refuses a disabled admin there regardless of the resource's own guard — the exact vacuity this
 * whole file exists to avoid (see above). `Livewire::test()` mounts the page's Livewire component
 * directly, skipping route middleware entirely, so the 403 can only come from
 * `CanAuthorizeResourceAccess::authorizeAccess()` calling `abort_unless(static::getResource()
 * ::canAccess(), 403)` inside the page's own `mount()` — confirmed non-vacuous by a throwaway test
 * (removing the trait from one resource made this exact assertion fail with 200, not 403; restored
 * before committing).
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

it('returns a literal 403 to a disabled admin mounting a resource\'s index page (R149(f))', function (string $resourceClass) {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $indexPage = $resourceClass::getPages()['index']->getPage();

    $activeAdmin = User::factory()->admin()->create(['status' => UserStatus::Active]);
    test()->actingAs($activeAdmin);
    Livewire::test($indexPage)->assertOk();

    $disabledAdmin = User::factory()->admin()->create(['status' => UserStatus::Suspended]);
    test()->actingAs($disabledAdmin);
    Livewire::test($indexPage)->assertForbidden();
})->with('filamentResources');

dataset('filamentResources', fn () => discoveredFilamentResourceClasses());
