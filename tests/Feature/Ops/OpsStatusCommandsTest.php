<?php

use App\Models\Lesson;
use App\Models\OpsStatusToken;
use App\Services\Ops\OpsStatusReport;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Mockery\MockInterface;

/**
 * R193: `ops:status`, the scheduler pulse, and the scheduled ledger check that the status document reads.
 */
beforeEach(function () {
    foreach ([MasterSupervisorRepository::class, SupervisorRepository::class] as $repository) {
        test()->mock($repository, fn (MockInterface $mock) => $mock->shouldReceive('all')->andReturn([]));
    }
});

function opsScheduled(string $needle): Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => $event instanceof CallbackEvent ? $event->description === $needle : str_contains((string) $event->command, $needle))
        ?? test()->fail("Nothing scheduled matches {$needle}.");
}

it('prints the same document the endpoint serves', function () {
    $this->freezeTime();
    $plain = Str::random(64);
    OpsStatusToken::factory()->create(['token_hash' => OpsStatusToken::hashOf($plain)]);

    expect(Artisan::call('ops:status'))->toBe(0);

    $output = Artisan::output();
    $printed = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    $served = $this->withHeaders(['Authorization' => 'Bearer '.$plain])->getJson('/ops/status')->assertOk()->json();

    expect($printed)->toBe($served)
        ->and($output)->toContain("\n    \"schema_version\": 1");
});

it('prints JSON that holds the same sections and no token', function () {
    OpsStatusToken::factory()->create();

    expect(Artisan::call('ops:status'))->toBe(0);

    $output = Artisan::output();
    $printed = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($printed))->toBe(['schema_version', 'generated_at', 'app', 'migrations', 'horizon', 'queues', 'scheduler', 'ledger', 'video', 'mail', 'settings'])
        ->and($output)->not->toContain((string) OpsStatusToken::query()->value('token_hash'));
});

it('schedules a pulse every minute that writes the time of the tick', function () {
    $this->freezeTime();
    $event = opsScheduled('scheduler:ping');

    expect($event->expression)->toBe('* * * * *')
        ->and(Cache::get(OpsStatusReport::SCHEDULER_TICK_KEY))->toBeNull();

    $event->run(app());

    expect(Cache::get(OpsStatusReport::SCHEDULER_TICK_KEY))->toBe(now()->getTimestamp());
});

it('records an ok ledger verdict, with zero counts, and schedules the check every five minutes on one server', function () {
    $this->freezeTime();

    $this->artisan('ops:record-ledger-check')->expectsOutputToContain('Ledger check recorded: ok')->assertExitCode(0);

    expect(Cache::get(OpsStatusReport::LEDGER_CHECK_KEY))->toBe([
        'result' => 'ok',
        'checked_at' => now()->getTimestamp(),
        'unbalanced_lessons' => 0,
        'stranded_payments' => 0,
    ]);

    $event = opsScheduled('ops:record-ledger-check');
    expect($event->expression)->toBe('*/5 * * * *')
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('records a failing ledger as fail, exits 0, and the document says so', function () {
    $this->freezeTime();
    $lesson = Lesson::factory()->create();
    DB::table('ledger_entries')->insert(['lesson_id' => $lesson->id, 'account' => 'platform', 'type' => 'goodwill', 'amount' => 7, 'created_at' => now()]);

    $this->artisan('ops:record-ledger-check')->expectsOutputToContain('fail (1 unbalanced lessons')->assertExitCode(0);

    $plain = Str::random(64);
    OpsStatusToken::factory()->create(['token_hash' => OpsStatusToken::hashOf($plain)]);

    $this->withHeaders(['Authorization' => 'Bearer '.$plain])->getJson('/ops/status')
        ->assertOk()
        ->assertJsonPath('ledger.result', 'fail')
        ->assertJsonPath('ledger.unbalanced_lessons', 1);
});

it('overwrites its entry on a second run rather than adding to it', function () {
    $this->artisan('ops:record-ledger-check')->assertExitCode(0);
    $this->artisan('ops:record-ledger-check')->assertExitCode(0);

    expect(Cache::get(OpsStatusReport::LEDGER_CHECK_KEY))->toBeArray()->toHaveKey('result', 'ok');
});
