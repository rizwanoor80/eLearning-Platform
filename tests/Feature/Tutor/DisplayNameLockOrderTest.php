<?php

namespace Tests\Feature\Tutor;

use App\Actions\Tutor\RequestDisplayName;
use App\Models\AuditLog;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cycle 18d (R225(b)): `RequestDisplayName` takes the tutor's `users` row before the `tutor_profiles` row, the order
 * `AnonymizeUser` uses, so the two cannot deadlock.
 *
 * A deterministic two-connection proof, no sleeps. A second connection plays `AnonymizeUser`'s first step: it holds
 * `SELECT … FROM users WHERE id = ? FOR UPDATE` open. The request then runs with a short `lock_timeout`. With the order
 * "users, then profile" it stops at its first statement, the users lock, having touched nothing. With the old order
 * ("profile, then users") it would hold the profile row, write it, and only meet the lock at the audit insert's foreign
 * key (`FOR KEY SHARE` on the users row): the exception's SQL names `audit_logs`, not `users`, and this test fails.
 *
 * It needs committed rows (the second connection cannot see an open transaction), so it opts out of the wrapping
 * transaction and rebuilds the schema afterwards: it is the one test in the suite that does, hence a class of its own.
 */
class DisplayNameLockOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function tearDown(): void
    {
        if (config()->has('database.connections.probe')) {
            if (DB::connection('probe')->transactionLevel() > 0) {
                DB::connection('probe')->rollBack();
            }

            DB::purge('probe');
        }

        DB::statement('RESET lock_timeout');

        // Committed fixtures must not reach the next test: rebuild the schema (the suite's own refresh does the same once).
        Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);

        parent::tearDown();
    }

    public function test_a_request_waits_on_the_users_row_first_and_nothing_is_written_when_it_gives_up(): void
    {
        config(['database.connections.probe' => config('database.connections.'.config('database.default'))]);

        $user = User::factory()->tutor()->create(['name' => 'Amira Haddad']);
        $profile = TutorProfile::factory()->approved()->create(['user_id' => $user->id]);
        AuditLog::query()->delete();

        // AnonymizeUser's first step, held open on its own connection.
        $probe = DB::connection('probe');
        $probe->beginTransaction();
        $probe->selectOne('select id from users where id = ? for update', [$user->id]);

        DB::statement("SET lock_timeout = '1500ms'");

        $sql = null;

        try {
            app(RequestDisplayName::class)($user, $profile, 'Ms Amira');
        } catch (QueryException $e) {
            $sql = $e->getSql();

            $this->assertSame('55P03', $e->getCode(), 'expected lock_not_available');
        }

        $this->assertNotNull($sql, 'the request should have waited on the users row and timed out');
        $this->assertStringContainsString('from "users"', $sql);
        $this->assertStringNotContainsString('audit_logs', $sql);

        $probe->rollBack();
        DB::statement('RESET lock_timeout');

        $this->assertNull($profile->fresh()->display_name_requested);
        $this->assertSame(0, AuditLog::query()->count());

        // Released, the same call goes through.
        app(RequestDisplayName::class)($user, $profile, 'Ms Amira');

        $this->assertSame('Ms Amira', $profile->fresh()->display_name_requested);
        $this->assertSame(1, AuditLog::query()->where('action', 'tutor.display_name_requested')->count());
    }
}
