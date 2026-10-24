<?php

use App\Actions\Tenancy\CreateTenantAdmin;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\Curriculum;
use App\Models\PriceBand;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ops\OpsStatusReport;
use App\Tenancy\DatabaseProvisioner;
use App\Tenancy\Tenant;
use App\Tenancy\TenantAdmin;
use App\Tenancy\TenantDomain;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Support\TenancyFixtures;

beforeEach(function () {
    TenancyFixtures::ensure(TenancyFixtures::base());

    $this->tenants = app(TenantManager::class);
    $this->provisioner = new DatabaseProvisioner;
});

afterEach(function () {
    $this->tenants->end();

    foreach ($this->cleanup ?? [] as $slug) {
        $tenant = Tenant::query()->where('slug', $slug)->first();
        $tenant?->delete();
        $this->provisioner->drop(config('tenancy.database_prefix').str_replace('-', '_', $slug));
    }
});

it('seeds the reference data and the first administrator, and no demo data, when a tenant is created', function () {
    $this->cleanup = ['seeded'];

    $this->artisan('tenants:create', ['slug' => 'seeded', '--host' => 'seeded.test', '--admin-email' => 'Boss@Example.test', '--admin-name' => 'Boss'])
        ->expectsOutputToContain('administrator created')
        ->assertSuccessful();

    $tenant = TenancyFixtures::tenant('seeded');

    $this->tenants->run($tenant, function () {
        expect(Curriculum::query()->count())->toBeGreaterThan(0)
            ->and(PriceBand::query()->count())->toBeGreaterThan(0)
            ->and(Setting::query()->count())->toBeGreaterThan(0)
            ->and(User::query()->count())->toBe(1)
            ->and(DB::table('tutor_profiles')->count())->toBe(0);

        $admin = User::query()->firstOrFail();

        expect($admin->email)->toBe('boss@example.test')
            ->and($admin->role)->toBe(Role::Admin)
            ->and($admin->email_verified_at)->not->toBeNull()
            ->and($admin->name)->toBe('Boss');
    });

    expect(TenantAdmin::query()->where('tenant_id', $tenant->id)->pluck('email')->all())->toBe(['boss@example.test']);
});

it('gives the first administrator a password nobody knows, set through the reset broker', function () {
    $this->cleanup = ['resettable'];
    $this->artisan('tenants:create', ['slug' => 'resettable', '--host' => 'resettable.test', '--admin-email' => 'a@example.test'])->assertSuccessful();

    $this->tenants->run(TenancyFixtures::tenant('resettable'), function () {
        $user = User::query()->where('email', 'a@example.test')->firstOrFail();

        expect(Password::broker()->createToken($user))->toBeString();
        // 64 random characters, hashed: the stored value is neither empty nor a readable word.
        expect($user->password)->not->toBeEmpty()->and(strlen($user->password))->toBeGreaterThan(40);
    });
});

it('refuses a bad administrator address before creating anything', function () {
    $this->artisan('tenants:create', ['slug' => 'badmail', '--admin-email' => 'not-an-email'])->expectsOutputToContain('not a valid email')->assertFailed();

    expect(Tenant::query()->where('slug', 'badmail')->exists())->toBeFalse();
});

it('rolls back a tenant whose reference seeding fails', function () {
    $database = config('tenancy.database_prefix').'seedfail';
    $mock = Mockery::mock(DatabaseProvisioner::class)->makePartial();
    $mock->shouldReceive('seedReference')->andThrow(new RuntimeException('seeder blew up'));
    $this->app->instance(DatabaseProvisioner::class, $mock);

    $this->artisan('tenants:create', ['slug' => 'seedfail', '--host' => 'seedfail.test', '--admin-email' => 'a@example.test'])->expectsOutputToContain('rolled back')->assertFailed();

    expect(Tenant::query()->where('slug', 'seedfail')->exists())->toBeFalse()
        ->and(TenantAdmin::query()->where('email', 'a@example.test')->exists())->toBeFalse()
        ->and((new DatabaseProvisioner)->exists($database))->toBeFalse();
});

it('adopts the current database as a tenant without changing it, and unregisters it again', function () {
    // A migrated, seeded database that is not (or no longer) a tenant stands in for the pre-tenancy one.
    $this->artisan('tenants:create', ['slug' => 'legacy', '--host' => 'legacy.test', '--admin-email' => 'owner@example.test'])->assertSuccessful();
    $legacy = TenancyFixtures::tenant('legacy');
    $database = $legacy->database;
    $this->artisan('tenants:unregister', ['slug' => 'legacy', '--yes' => true])->expectsOutputToContain('untouched')->assertSuccessful();
    $this->cleanup = ['legacy', 'adopted'];

    $count = fn (Tenant $tenant) => $this->tenants->run($tenant, fn () => [User::query()->count(), DB::table('settings')->count(), DB::table('migrations')->count()]);
    $probe = Tenant::query()->create(['slug' => 'probe', 'name' => 'Probe', 'database' => $database, 'status' => TenantStatus::Active]);
    $before = $count($probe);
    $probe->delete();

    config()->set('tenancy.current_database', $database);

    $this->artisan('tenants:adopt', ['slug' => 'adopted', '--from' => 'current', '--host' => 'Adopted.test'])
        ->expectsOutputToContain('now answers on [adopted.test]')
        ->expectsOutputToContain('differs from the host in APP_URL')
        ->assertSuccessful();

    $adopted = TenancyFixtures::tenant('adopted');

    expect($adopted->isActive())->toBeTrue()
        ->and($adopted->database)->toBe($database)
        ->and($adopted->domains()->where('is_primary', true)->value('host'))->toBe('adopted.test')
        ->and(TenantAdmin::query()->where('tenant_id', $adopted->id)->pluck('email')->all())->toBe(['owner@example.test']);

    // Adopting registered the database and changed nothing in it; the whole tenant path works on it.
    $this->tenants->run($adopted, function () {
        expect(Artisan::call('ledger:verify'))->toBe(0);
    });
    $this->tenants->end();
    $this->get('http://adopted.test/login')->assertOk();
    $this->tenants->end();
    expect($count($adopted))->toBe($before);

    $this->artisan('tenants:unregister', ['slug' => 'adopted'])->expectsOutputToContain('--yes')->assertFailed();
    expect(Tenant::query()->where('slug', 'adopted')->exists())->toBeTrue();

    $this->artisan('tenants:unregister', ['slug' => 'adopted', '--yes' => true])->assertSuccessful();
    expect(Tenant::query()->where('slug', 'adopted')->exists())->toBeFalse()
        ->and(TenantDomain::query()->where('host', 'adopted.test')->exists())->toBeFalse()
        ->and($this->provisioner->exists($database))->toBeTrue();
    $this->get('http://adopted.test/login')->assertNotFound();
});

it('refuses to adopt anything but the current database, a registered one, the central registry or a stranger', function () {
    $this->artisan('tenants:adopt', ['slug' => 'nope', '--from' => 'other', '--host' => 'n.test'])->expectsOutputToContain('--from=current')->assertFailed();
    $this->artisan('tenants:adopt', ['slug' => 'nope', '--from' => 'current'])->expectsOutputToContain('--host is required')->assertFailed();
    $this->artisan('tenants:adopt', ['slug' => 'Bad Slug', '--from' => 'current', '--host' => 'n.test'])->expectsOutputToContain('slug')->assertFailed();

    config()->set('tenancy.current_database', TenancyFixtures::tenant('x')->database);
    $this->artisan('tenants:adopt', ['slug' => 'nope', '--from' => 'current', '--host' => 'n.test'])->expectsOutputToContain('already registered')->assertFailed();

    config()->set('tenancy.current_database', (string) config('database.connections.central.database'));
    $this->artisan('tenants:adopt', ['slug' => 'nope', '--from' => 'current', '--host' => 'n.test'])->expectsOutputToContain('central registry')->assertFailed();

    config()->set('tenancy.current_database', 'no_such_database_here');
    $this->artisan('tenants:adopt', ['slug' => 'nope', '--from' => 'current', '--host' => 'n.test'])->expectsOutputToContain('does not exist')->assertFailed();

    // A database that exists but is not a TrusTutor one is undone and left alone.
    $stranger = config('tenancy.database_prefix').'stranger';
    $this->provisioner->create($stranger);

    try {
        config()->set('tenancy.current_database', $stranger);
        $this->artisan('tenants:adopt', ['slug' => 'stranger', '--from' => 'current', '--host' => 'stranger.test'])->expectsOutputToContain('not a TrusTutor database')->assertFailed();

        expect(Tenant::query()->where('slug', 'stranger')->exists())->toBeFalse()
            ->and($this->provisioner->exists($stranger))->toBeTrue();
    } finally {
        $this->provisioner->drop($stranger);
    }

    $this->artisan('tenants:adopt', ['slug' => 'x2', '--from' => 'current', '--host' => 'x.test'])->assertFailed();
});

it('does not serve a host that is not verified', function () {
    $domain = TenantDomain::query()->where('host', 'x.test')->firstOrFail();
    $domain->update(['verified_at' => null]);

    try {
        $this->get('http://x.test/login')->assertNotFound();
    } finally {
        $domain->update(['verified_at' => now()]);
    }

    $this->get('http://x.test/login')->assertOk();
});

it('keeps the tenants apart: one tenant\'s user cannot sign in on another host', function () {
    $this->tenants->run(TenancyFixtures::tenant('x'), fn () => User::factory()->create(['email' => 'only-x@example.test', 'password' => 'secret-pass-1']));
    $this->tenants->end();

    $this->postJson('http://y.test/login', ['email' => 'only-x@example.test', 'password' => 'secret-pass-1'])->assertUnprocessable();
    $this->assertGuest();

    $this->tenants->run(TenancyFixtures::tenant('y'), fn () => expect(User::query()->where('email', 'only-x@example.test')->exists())->toBeFalse());
    $this->tenants->end();

    $this->post('http://x.test/login', ['email' => 'only-x@example.test', 'password' => 'secret-pass-1'])->assertRedirect();
    $this->assertAuthenticated();
});

it('refuses on another tenant a signed URL made for the first', function () {
    Route::middleware(['web', 'signed'])->get('/tenancy-signed', fn () => response()->json(['tenant' => app(TenantManager::class)->current()?->slug]))->name('tenancy.signed');
    app('router')->getRoutes()->refreshNameLookups();

    $this->tenants->initialize(TenancyFixtures::tenant('x'));
    $signed = URL::signedRoute('tenancy.signed');
    $temporary = URL::temporarySignedRoute('tenancy.signed', now()->addMinutes(5));
    $this->tenants->end();

    expect($signed)->toMatch('#^https?://x\.test/#');

    $this->getJson($signed)->assertOk()->assertJsonPath('tenant', 'x');
    $this->getJson($temporary)->assertOk();

    foreach ([$signed, $temporary] as $url) {
        $onY = preg_replace('#//x\.test/#', '//y.test/', $url);
        $this->getJson($onY)->assertForbidden();
    }
});

it('points APP_URL at the tenant and puts it back', function () {
    $base = config('app.url');

    $this->tenants->initialize(TenancyFixtures::tenant('x'));
    expect(config('app.url'))->toEndWith('://x.test');

    $this->tenants->initialize(TenancyFixtures::tenant('y'));
    expect(config('app.url'))->toEndWith('://y.test');

    $this->tenants->end();
    expect(config('app.url'))->toBe($base);
});

it('reports the tenant and the central migrations in the status document', function () {
    $this->tenants->initialize(TenancyFixtures::tenant('x'));
    $report = (new OpsStatusReport)->build();

    expect($report['app']['tenant'])->toBe('x')
        ->and($report['migrations']['central_pending'])->toBe(0)
        ->and($report['migrations']['pending'])->toBe(0);

    DB::connection('central')->table('migrations')->where('migration', '2026_10_25_090000_extend_tenancy_registry')->delete();

    try {
        expect((new OpsStatusReport)->build()['migrations']['central_pending'])->toBe(1);
    } finally {
        DB::connection('central')->table('migrations')->insert(['migration' => '2026_10_25_090000_extend_tenancy_registry', 'batch' => 99]);
    }
});

it('counts failed jobs from the central table, where tenancy records them', function () {
    $this->tenants->initialize(TenancyFixtures::tenant('x'));
    $before = (new OpsStatusReport)->build()['queues']['failed_jobs'];

    $id = DB::connection('central')->table('failed_jobs')->insertGetId(['uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);

    try {
        expect((new OpsStatusReport)->build()['queues']['failed_jobs'])->toBe($before + 1);
    } finally {
        DB::connection('central')->table('failed_jobs')->where('id', $id)->delete();
    }
});

it('records a tenant administrator once however often it is asked', function () {
    $x = TenancyFixtures::tenant('x');
    $create = app(CreateTenantAdmin::class);

    $this->tenants->run($x, function () {
        User::query()->where('email', 'twice@example.test')->delete();
    });

    $create($x, 'Twice@Example.test', 'One');
    $create($x, 'twice@example.test', 'Two');

    expect(TenantAdmin::query()->where('tenant_id', $x->id)->where('email', 'twice@example.test')->count())->toBe(1)
        ->and(TenantAdmin::query()->where('email', 'twice@example.test')->value('name'))->toBe('Two');

    $this->tenants->run($x, fn () => expect(User::query()->where('email', 'twice@example.test')->count())->toBe(1));
    TenantAdmin::query()->where('email', 'twice@example.test')->delete();
    $this->tenants->run($x, fn () => User::query()->where('email', 'twice@example.test')->delete());
});

it('lets a tenant leave a transaction whose connection has died', function () {
    $this->tenants->initialize(TenancyFixtures::tenant('x'));
    DB::beginTransaction();
    $pid = DB::selectOne('select pg_backend_pid() as pid')->pid;

    // The server drops the session from under the open transaction: a rollback now fails, and restore() must still get out.
    DB::connection('central')->select('select pg_terminate_backend(?)', [$pid]);

    $this->tenants->restore(null);

    expect($this->tenants->current())->toBeNull()
        ->and(DB::transactionLevel())->toBe(0);
});
