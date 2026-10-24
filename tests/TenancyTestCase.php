<?php

namespace Tests;

/**
 * Base for tests/Tenancy: no tenant is initialised and no RefreshDatabase transaction is open, because these tests
 * switch tenants themselves (a switch inside a transaction is refused).
 */
abstract class TenancyTestCase extends TestCase
{
    protected bool $bootTenant = false;
}
