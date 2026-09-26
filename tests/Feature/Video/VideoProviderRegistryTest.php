<?php

use App\Actions\Video\ActivateVideoProvider;
use App\Actions\Video\DeactivateVideoProvider;
use App\Enums\VideoProviderCode;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\VideoProvider;
use App\Services\Video\DailyVideoProvider;
use App\Services\Video\FakeVideoProvider;
use App\Services\Video\NoActiveVideoProvider;
use App\Services\Video\VideoProviderException;
use App\Services\Video\VideoProviderManager;
use App\Services\Video\VideoRoomProvider;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function dailyRow(): VideoProvider
{
    return VideoProvider::query()->where('code', 'daily')->firstOrFail();
}

function fakeRow(): VideoProvider
{
    return VideoProvider::query()->where('code', 'fake')->firstOrFail();
}

function fillDaily(): VideoProvider
{
    $row = dailyRow();
    $row->credentials = ['api_key' => 'k-secret-daily-key', 'webhook_secret' => base64_encode('daily-webhook-secret')];
    $row->save();

    return $row;
}

function runVideoMigrationIn(string $environment): void
{
    Schema::dropIfExists('video_webhook_events');
    Schema::dropIfExists('video_providers');

    app()->detectEnvironment(fn () => $environment);

    try {
        (require glob(database_path('migrations/*_create_video_providers_table.php'))[0])->up();
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
}

it('migrates with fake active and daily inactive and empty', function () {
    expect(fakeRow()->is_active)->toBeTrue()
        ->and(dailyRow()->is_active)->toBeFalse()
        ->and(dailyRow()->credentials)->toBeNull()
        ->and(dailyRow()->hasCompleteCredentials())->toBeFalse();
});

it('leaves fake inactive when the migration runs in production', function () {
    runVideoMigrationIn('production');

    expect(fakeRow()->is_active)->toBeFalse()
        ->and(VideoProvider::query()->active()->count())->toBe(0);
});

it('refuses to activate the fake provider in production', function () {
    DB::table('video_providers')->where('code', 'fake')->update(['is_active' => false]);
    $fake = fakeRow();
    $fake->credentials = ['webhook_secret' => 'x'];
    $fake->save();

    app()->detectEnvironment(fn () => 'production');

    try {
        expect(fn () => app(ActivateVideoProvider::class)($fake, null))
            ->toThrow(LogicException::class, 'cannot be active in production');
        expect(fn () => $fake->forceFill(['is_active' => true])->save())->toThrow(LogicException::class);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }

    expect(VideoProvider::query()->active()->count())->toBe(0);
});

it('refuses to activate a code that has no driver', function (VideoProviderCode $code) {
    $row = VideoProvider::query()->create(['code' => $code->value, 'name' => $code->label(), 'is_active' => false]);

    expect(fn () => app(ActivateVideoProvider::class)($row, null))->toThrow(LogicException::class, 'no driver');
    expect(VideoProvider::query()->active()->value('code'))->toBe('fake');
})->with([VideoProviderCode::Zoom, VideoProviderCode::Meet, VideoProviderCode::Teams]);

it('refuses to activate daily while credentials are incomplete', function () {
    $row = dailyRow();
    $row->credentials = ['api_key' => 'only-the-key'];
    $row->save();

    expect(fn () => app(ActivateVideoProvider::class)($row, null))->toThrow(LogicException::class, 'missing credentials');
    expect(fakeRow()->is_active)->toBeTrue();
});

it('swaps the single active row and audits it without credentials', function () {
    $admin = User::factory()->admin()->create();
    $daily = fillDaily();

    app(ActivateVideoProvider::class)($daily, $admin);

    expect(dailyRow()->is_active)->toBeTrue()
        ->and(fakeRow()->is_active)->toBeFalse()
        ->and(VideoProvider::query()->active()->count())->toBe(1)
        ->and(dailyRow()->updated_by)->toBe($admin->id);

    $log = AuditLog::query()->where('action', 'video_provider.activated')->firstOrFail();

    expect($log->before)->toBe(['active' => 'fake'])
        ->and($log->after)->toBe(['active' => 'daily'])
        ->and(json_encode($log->toArray()))->not->toContain('k-secret-daily-key');
});

it('enforces one active row in the database itself', function () {
    fillDaily();

    expect(fn () => DB::table('video_providers')->where('code', 'daily')->update(['is_active' => true]))
        ->toThrow(QueryException::class);
});

it('guards an active row below the admin screen', function () {
    $daily = fillDaily();
    app(ActivateVideoProvider::class)($daily, null);

    $daily->credentials = ['api_key' => '', 'webhook_secret' => 'x'];

    expect(fn () => $daily->save())->toThrow(LogicException::class, 'missing credentials');
});

it('deactivates the active provider', function () {
    app(DeactivateVideoProvider::class)(fakeRow(), User::factory()->admin()->create());

    expect(VideoProvider::query()->active()->count())->toBe(0);
    expect(AuditLog::query()->where('action', 'video_provider.deactivated')->count())->toBe(1);
});

it('resolves the active driver from the registry, never hard-wired', function () {
    $fake = fakeRow();
    $fake->credentials = ['webhook_secret' => 'x'];
    $fake->save();

    expect(app(VideoRoomProvider::class))->toBeInstanceOf(FakeVideoProvider::class);

    app(ActivateVideoProvider::class)(fillDaily(), null);

    expect(app(VideoRoomProvider::class))->toBeInstanceOf(DailyVideoProvider::class);
});

it('throws NoActiveVideoProvider when no row is active', function () {
    DB::table('video_providers')->update(['is_active' => false]);

    expect(fn () => app(VideoProviderManager::class)->active())->toThrow(NoActiveVideoProvider::class);
    expect(fn () => app(VideoRoomProvider::class))->toThrow(NoActiveVideoProvider::class);
});

it('resolves a driver by code even when it is not the active one', function () {
    fillDaily();
    $fake = fakeRow();
    $fake->credentials = ['webhook_secret' => 'x'];
    $fake->save();
    $manager = app(VideoProviderManager::class);

    expect($manager->forCode('daily'))->toBeInstanceOf(DailyVideoProvider::class)
        ->and($manager->forCode('fake'))->toBeInstanceOf(FakeVideoProvider::class);
    expect(fn () => $manager->forCode('zoom'))->toThrow(VideoProviderException::class);
});

it('encrypts credentials at rest and hides them from serialisation', function () {
    $row = fillDaily();

    $raw = DB::table('video_providers')->where('id', $row->id)->value('credentials');

    expect($raw)->not->toContain('k-secret-daily-key')
        ->and($row->fresh()->toArray())->not->toHaveKey('credentials')
        ->and($row->fresh()->toJson())->not->toContain('k-secret-daily-key');
});
