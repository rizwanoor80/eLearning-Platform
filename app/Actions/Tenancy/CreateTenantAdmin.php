<?php

namespace App\Actions\Tenancy;

use App\Enums\Role;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantAdmin;
use App\Tenancy\TenantManager;
use Illuminate\Support\Str;

/**
 * Creates a tenant's first administrator inside the tenant's database and records the contact in the registry.
 *
 * The password is random and never shown, stored or logged by this action: the administrator sets their own through
 * the ordinary "forgot password" flow on the tenant's host.
 */
class CreateTenantAdmin
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function __invoke(Tenant $tenant, string $email, string $name = 'Admin'): User
    {
        $email = strtolower(trim($email));

        $admin = $this->tenants->run($tenant, function () use ($email, $name): User {
            $user = User::query()->firstOrNew(['email' => $email]);
            $user->fill(['name' => $name, 'password' => Str::random(64)]);
            $user->forceFill(['role' => Role::Admin, 'email_verified_at' => now()])->save();

            return $user;
        });

        TenantAdmin::query()->updateOrCreate(['tenant_id' => $tenant->getKey(), 'email' => $email], ['name' => $name]);

        return $admin;
    }
}
