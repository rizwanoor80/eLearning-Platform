<?php

namespace Tests;

use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\ParallelTesting;
use Laravel\Fortify\Features;
use Tests\Support\TenancyFixtures;

abstract class TestCase extends BaseTestCase
{
    /** Under TENANCY_SPIKE=1 the whole suite runs as tenant `a`, except tests that opt out (tests/Tenancy). */
    protected bool $bootTenant = true;

    protected function setUpTraits(): array
    {
        if ($this->bootTenant && getenv('TENANCY_SPIKE') === '1') {
            // The parallel-testing callback has already switched the default database to this process's own; the
            // tenant and central databases hang off that name. The tenant is set before RefreshDatabase runs, so its
            // migrate:fresh and the per-test transaction land on the tenant database.
            $base = (string) config('database.connections.pgsql.database');

            // Laravel only switches to the per-process database for tests that use a database trait; a plain unit test
            // would otherwise share one unsuffixed base with every other process and race them on its central database.
            $token = ParallelTesting::token();

            if ($token !== false && ! str_ends_with($base, "_test_{$token}")) {
                $base .= "_test_{$token}";
            }

            TenancyFixtures::ensure($base);
            $this->app->make(TenantManager::class)->initialize(TenancyFixtures::tenant('a'));
        }

        return parent::setUpTraits();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
