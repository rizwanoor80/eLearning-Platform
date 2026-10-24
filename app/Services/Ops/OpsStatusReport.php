<?php

namespace App\Services\Ops;

use App\Models\VideoProvider;
use App\Models\VideoWebhookDelivery;
use App\Models\VideoWebhookEvent;
use App\Support\Facades\Settings;
use App\Support\Mail\MailBrand;
use App\Tenancy\TenantManager;
use Closure;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Throwable;

/**
 * The document behind `GET /ops/status` and `php artisan ops:status` (R193) — one assembler, so the
 * endpoint and the command cannot drift apart. Operator facts only: versions, counts, ages and flags.
 * No secret, no row, no user datum, no exception text; a section that cannot be read says
 * `unknown` and the rest still answers.
 *
 * Nothing here does expensive work in the request. The ledger check is *read* from the cache the
 * scheduled `ops:record-ledger-check` writes (invariant #1's sum is never computed on this path), and
 * the scheduler's pulse is the cache entry `scheduler:ping` writes every minute.
 */
final class OpsStatusReport
{
    public const SCHEMA_VERSION = 1;

    /** Cache key `scheduler:ping` writes every minute: the epoch second of the last tick. */
    public const SCHEDULER_TICK_KEY = 'ops.scheduler_tick';

    /** Cache key `ops:record-ledger-check` writes every five minutes: result, counts and epoch second. */
    public const LEDGER_CHECK_KEY = 'ops.ledger_check';

    /** The scheduler runs every minute; three missed ticks and it is called stale. */
    public const SCHEDULER_STALE_AFTER_SECONDS = 180;

    /** The ledger check runs every five minutes; three missed runs and an `ok` is called stale. */
    public const LEDGER_STALE_AFTER_SECONDS = 900;

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => now()->utc()->toIso8601String(),
            'app' => $this->section('app', fn () => $this->app()),
            'migrations' => $this->section('migrations', fn () => $this->migrations()),
            'horizon' => $this->section('horizon', fn () => $this->horizon()),
            'queues' => $this->section('queues', fn () => $this->queues()),
            'scheduler' => $this->section('scheduler', fn () => $this->scheduler()),
            'ledger' => $this->section('ledger', fn () => $this->ledger()),
            'video' => $this->section('video', fn () => $this->video()),
            'mail' => $this->section('mail', fn () => $this->mail()),
            'settings' => $this->section('settings', fn () => $this->settings()),
        ];
    }

    /**
     * The commit a deploy is on, read from the checkout's own files — no shell, no `exec`. Null when it
     * cannot be told (a release directory with no `.git`). Public and static so a test can aim it at a
     * throwaway directory.
     */
    public static function gitShaFromDirectory(string $gitDirectory): ?string
    {
        $head = @file_get_contents($gitDirectory.'/HEAD');

        if (! is_string($head)) {
            return null;
        }

        $head = trim($head);

        if (preg_match('/^[0-9a-f]{40}$/i', $head) === 1) {
            return strtolower($head);
        }

        if (preg_match('#^ref: (refs/[A-Za-z0-9._/-]+)$#', $head, $match) !== 1 || str_contains($match[1], '..')) {
            return null;
        }

        $ref = $match[1];
        $loose = @file_get_contents($gitDirectory.'/'.$ref);

        if (is_string($loose) && preg_match('/^[0-9a-f]{40}$/i', trim($loose)) === 1) {
            return strtolower(trim($loose));
        }

        $packed = @file($gitDirectory.'/packed-refs', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach (is_array($packed) ? $packed : [] as $line) {
            if (preg_match('/^([0-9a-f]{40}) '.preg_quote($ref, '/').'$/i', $line, $found) === 1) {
                return strtolower($found[1]);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function app(): array
    {
        return [
            'environment' => (string) config('app.env'),
            'git_sha' => $this->gitSha(),
            'release' => $this->release(),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'debug' => (bool) config('app.debug'),
            'tenant' => app(TenantManager::class)->current()?->slug,
        ];
    }

    private function gitSha(): ?string
    {
        $configured = config('app.git_sha');

        if (is_string($configured) && preg_match('/^[0-9a-f]{7,40}$/i', trim($configured)) === 1) {
            return strtolower(trim($configured));
        }

        return self::gitShaFromDirectory(base_path('.git'));
    }

    /** The deploy's release directory name when the app runs from `<site>/releases/<name>` (zero-downtime deploys). */
    private function release(): ?string
    {
        $real = realpath(base_path());

        if ($real === false || basename(dirname($real)) !== 'releases') {
            return null;
        }

        return basename($real);
    }

    /**
     * @return array<string, mixed>
     */
    private function migrations(): array
    {
        $migrator = app('migrator');

        if (! $migrator->repositoryExists()) {
            return ['pending' => null, 'central_pending' => $this->centralPending($migrator)];
        }

        $files = $migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')]));
        $ran = $migrator->getRepository()->getRan();

        return ['pending' => count(array_diff(array_keys($files), $ran)), 'central_pending' => $this->centralPending($migrator)];
    }

    /** Registry migrations not yet run on the central database; null while tenancy is off (there is no registry). */
    private function centralPending(Migrator $migrator): ?int
    {
        if (! app(TenantManager::class)->enabled()) {
            return null;
        }

        $central = DB::connection(config('tenancy.central_connection'));

        if (! $central->getSchemaBuilder()->hasTable('migrations')) {
            return null;
        }

        $files = $migrator->getMigrationFiles(database_path('migrations/central'));

        return count(array_diff(array_keys($files), $central->table('migrations')->pluck('migration')->all()));
    }

    /**
     * @return array<string, mixed>
     */
    private function horizon(): array
    {
        $masters = collect(app(MasterSupervisorRepository::class)->all());

        if ($masters->isEmpty()) {
            return ['status' => 'inactive', 'master_pid' => null, 'supervisors' => []];
        }

        $supervisors = collect(app(SupervisorRepository::class)->all())
            ->map(function ($supervisor): array {
                $processes = collect((array) ($supervisor->processes ?? []))
                    ->map(fn ($count) => is_numeric($count) ? (int) $count : 0);

                return [
                    // The record's name is "<master>:<supervisor>" and the master part carries the host name.
                    'name' => Str::after((string) $supervisor->name, ':'),
                    'status' => (string) $supervisor->status,
                    'process_count' => $processes->sum(),
                    'processes' => $processes->all(),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();

        return [
            'status' => $masters->contains(fn ($master) => $master->status === 'paused') ? 'paused' : 'running',
            'master_pid' => (int) $masters->first()->pid,
            'supervisors' => $supervisors,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function queues(): array
    {
        return [
            'default_size' => $this->attempt(fn () => Queue::size('default')),
            'failed_jobs' => $this->attempt(fn () => DB::connection(config('queue.failed.database'))->table((string) config('queue.failed.table', 'failed_jobs'))->count()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduler(): array
    {
        $tick = Cache::get(self::SCHEDULER_TICK_KEY);

        if (! is_numeric($tick)) {
            return ['status' => 'unknown', 'last_tick_age_seconds' => null];
        }

        $age = max(0, now()->getTimestamp() - (int) $tick);

        return [
            'status' => $age <= self::SCHEDULER_STALE_AFTER_SECONDS ? 'ok' : 'stale',
            'last_tick_age_seconds' => $age,
        ];
    }

    /**
     * Read from the cache only — `ops:record-ledger-check` does the sum (see the class note).
     *
     * @return array<string, mixed>
     */
    private function ledger(): array
    {
        $check = Cache::get(self::LEDGER_CHECK_KEY);

        if (! is_array($check) || ! is_numeric($check['checked_at'] ?? null) || ! in_array($check['result'] ?? null, ['ok', 'fail'], true)) {
            return ['result' => 'unknown', 'checked_at' => null, 'age_seconds' => null, 'unbalanced_lessons' => null, 'stranded_payments' => null];
        }

        $age = max(0, now()->getTimestamp() - (int) $check['checked_at']);

        return [
            'result' => $check['result'] === 'ok' && $age > self::LEDGER_STALE_AFTER_SECONDS ? 'stale' : $check['result'],
            'checked_at' => Carbon::createFromTimestampUTC((int) $check['checked_at'])->toIso8601String(),
            'age_seconds' => $age,
            'unbalanced_lessons' => is_int($check['unbalanced_lessons'] ?? null) ? $check['unbalanced_lessons'] : null,
            'stranded_payments' => is_int($check['stranded_payments'] ?? null) ? $check['stranded_payments'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function video(): array
    {
        $since = now()->subDay();

        $deliveries = VideoWebhookDelivery::query()
            ->where('received_at', '>=', $since)
            ->selectRaw('http_status, count(*) as total')
            ->groupBy('http_status')
            ->orderBy('http_status')
            ->get()
            ->mapWithKeys(fn (VideoWebhookDelivery $row) => [($row->http_status === null ? 'none' : (string) $row->http_status) => (int) $row->getAttribute('total')])
            ->all();

        return [
            'active_provider' => VideoProvider::query()->active()->value('code'),
            'deliveries_24h' => (object) $deliveries,
            'events_24h' => VideoWebhookEvent::query()->where('received_at', '>=', $since)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mail(): array
    {
        return [
            'mailer' => (string) config('mail.default'),
            'from_name' => MailBrand::senderName(),
            'from_address' => (string) (Settings::get('from_address') ?: config('mail.from.address')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        return [
            'allow_immediate_booking' => (bool) Settings::get('allow_immediate_booking'),
            'features' => [
                'match_requests' => (bool) Settings::get('match_requests'),
                'reviews' => (bool) Settings::get('reviews'),
                'messaging' => (bool) Settings::get('messaging'),
            ],
        ];
    }

    /**
     * One section, failing soft: the answer is `unknown` and the log names the section and the
     * exception class — never its message, which can carry a host or a connection detail.
     *
     * @param  Closure(): array<string, mixed>  $read
     * @return array<string, mixed>
     */
    private function section(string $name, Closure $read): array
    {
        try {
            return $read();
        } catch (Throwable $e) {
            Log::warning('ops status section could not be read', ['section' => $name, 'exception' => $e::class]);

            return ['status' => 'unknown'];
        }
    }

    /** One value inside a section; null when it cannot be read. */
    private function attempt(Closure $read): int|float|string|null
    {
        try {
            return $read();
        } catch (Throwable $e) {
            Log::warning('ops status value could not be read', ['exception' => $e::class]);

            return null;
        }
    }
}
