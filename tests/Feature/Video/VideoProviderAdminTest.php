<?php

use App\Filament\Resources\VideoProviders\Pages\EditVideoProvider;
use App\Filament\Resources\VideoProviders\Pages\ListVideoProviders;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\VideoProvider;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->daily = VideoProvider::query()->where('code', 'daily')->firstOrFail();
});

it('lists the registry for an admin', function () {
    Livewire::actingAs($this->admin)
        ->test(ListVideoProviders::class)
        ->assertCanSeeTableRecords(VideoProvider::query()->get());
});

it('refuses a non-admin', function () {
    $tutor = User::factory()->tutor()->create();

    test()->actingAs($tutor)->get(ListVideoProviders::getUrl())->assertForbidden();
});

it('saves credentials write-only and never puts them in the page or component state', function () {
    $component = Livewire::actingAs($this->admin)
        ->test(EditVideoProvider::class, ['record' => $this->daily->getRouteKey()])
        ->fillForm(['secret_api_key' => 'k-typed-secret-key', 'secret_webhook_secret' => 'c2VjcmV0'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->daily->fresh()->credentials)->toBe(['api_key' => 'k-typed-secret-key', 'webhook_secret' => 'c2VjcmV0'])
        ->and($this->daily->fresh()->updated_by)->toBe($this->admin->id);

    // The typed value is cleared from state after saving, so it is not in the next snapshot or render.
    expect(json_encode($component->instance()->data))->not->toContain('k-typed-secret-key')
        ->and($component->html())->not->toContain('k-typed-secret-key')
        ->and($component->html())->not->toContain('c2VjcmV0');

    // A fresh load of the edit page does not carry the stored value either.
    $fresh = Livewire::actingAs($this->admin)->test(EditVideoProvider::class, ['record' => $this->daily->getRouteKey()]);

    expect($fresh->html())->not->toContain('k-typed-secret-key')
        ->and(json_encode($fresh->instance()->data))->not->toContain('k-typed-secret-key')
        ->and($fresh->html())->toContain('Set — leave blank to keep');
});

it('keeps a stored credential when its input is left blank', function () {
    $this->daily->credentials = ['api_key' => 'k-keep-me', 'webhook_secret' => 'keep-me-too'];
    $this->daily->save();

    Livewire::actingAs($this->admin)
        ->test(EditVideoProvider::class, ['record' => $this->daily->getRouteKey()])
        ->fillForm(['name' => 'Daily.co', 'secret_api_key' => '', 'secret_webhook_secret' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->daily->fresh()->credentials)->toBe(['api_key' => 'k-keep-me', 'webhook_secret' => 'keep-me-too'])
        ->and($this->daily->fresh()->name)->toBe('Daily.co');
});

it('audits the names of changed credentials and never a value', function () {
    Livewire::actingAs($this->admin)
        ->test(EditVideoProvider::class, ['record' => $this->daily->getRouteKey()])
        ->fillForm(['secret_api_key' => 'k-audit-secret-key'])
        ->call('save');

    $log = AuditLog::query()->where('action', 'video_provider.updated')->sole();

    expect($log->after)->toBe(['credentials_changed' => ['api_key']])
        ->and(json_encode($log->toArray()))->not->toContain('k-audit-secret-key');
});

it('lets an admin set the first credential on a fake row', function () {
    $fake = VideoProvider::query()->where('code', 'fake')->firstOrFail();

    Livewire::actingAs($this->admin)
        ->test(EditVideoProvider::class, ['record' => $fake->getRouteKey()])
        ->fillForm(['secret_webhook_secret' => 'first-secret'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($fake->fresh()->hasCredential('webhook_secret'))->toBeTrue();
});

it('activates and deactivates from the table and refuses an unconfigured provider', function () {
    Livewire::actingAs($this->admin)
        ->test(ListVideoProviders::class)
        ->callTableAction('activate', $this->daily);

    expect($this->daily->fresh()->is_active)->toBeFalse();
    expect(VideoProvider::query()->where('code', 'fake')->value('is_active'))->toBeTrue();

    $this->daily->credentials = ['api_key' => 'k', 'webhook_secret' => base64_encode('s')];
    $this->daily->save();

    Livewire::actingAs($this->admin)
        ->test(ListVideoProviders::class)
        ->callTableAction('activate', $this->daily->fresh());

    expect($this->daily->fresh()->is_active)->toBeTrue()
        ->and(VideoProvider::query()->active()->count())->toBe(1);

    Livewire::actingAs($this->admin)
        ->test(ListVideoProviders::class)
        ->callTableAction('deactivate', $this->daily->fresh());

    expect(VideoProvider::query()->active()->count())->toBe(0);
});
