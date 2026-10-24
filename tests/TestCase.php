<?php

namespace Tests;

use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use Tests\Support\TenancyFixtures;

abstract class TestCase extends BaseTestCase
{
    /** Under TENANCY_SPIKE=1 the whole suite runs as tenant `a`, except tests that opt out (tests/Tenancy). */
    protected bool $bootTenant = true;

    protected function setUpTraits(): array
    {
        if ($this->bootTenant && getenv('TENANCY_SPIKE') === '1') {
            // The tenant and central databases hang off this process's own database name. The tenant is set before
            // RefreshDatabase runs, so its migrate:fresh and the per-test transaction land on the tenant database.
            TenancyFixtures::ensure(TenancyFixtures::base());
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
