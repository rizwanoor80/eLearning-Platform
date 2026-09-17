<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    /**
     * R10: credentials come from ADMIN_EMAIL/ADMIN_PASSWORD (via
     * config/seeding.php, never a raw env() call here so config:cache keeps
     * working). No default password — an empty value is a hard stop.
     */
    public function run(): void
    {
        $email = config('seeding.admin.email');
        $password = config('seeding.admin.password');

        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            throw new RuntimeException('ADMIN_EMAIL and ADMIN_PASSWORD must both be set before seeding the admin user.');
        }

        $admin = User::query()->updateOrCreate(
            ['email' => $email],
            ['name' => 'Admin', 'password' => $password],
        );

        $admin->forceFill([
            'role' => Role::Admin,
            'email_verified_at' => now(),
        ])->save();
    }
}
