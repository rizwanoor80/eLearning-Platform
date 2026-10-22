<?php

namespace App\Services\Ops;

use App\Enums\PaymentGatewayCode;
use App\Enums\PaymentGatewayMode;
use App\Enums\PreflightStatus;
use App\Enums\VideoProviderCode;
use App\Models\PaymentGatewayAccount;
use App\Models\User;
use App\Models\VideoProvider;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * The go-live gate behind `php artisan ops:preflight` (R275): does this environment look like production before the
 * first real traffic? Read-only — it writes nothing, calls no provider and sends nothing.
 *
 * Every check fails closed. One that throws becomes `Unreadable` (a failure), and only the exception class is logged:
 * a message can carry a host or a DSN. Nothing here returns a value. A result is a fixed name, a status and a fixed
 * note, so the command cannot print a key, a URL, a proxy list or a ledger amount even by mistake. The one value the
 * plan allows is the active payment gateway's mode, which is the enum `test` or `live` and nothing else.
 */
final class Preflight
{
    /** Email domains no real account has; a demo or factory user on production is a defect (invariant: demo data stays out). */
    private const RESERVED_EMAIL_SUFFIXES = ['@example.test', '@example.com', '@example.org', '@example.net', '.test', '.localhost'];

    /**
     * @return list<PreflightResult>
     */
    public function run(): array
    {
        $checks = [
            'APP_ENV is production' => fn () => $this->bool(app()->environment('production'), 'set APP_ENV=production'),
            'APP_DEBUG is off' => fn () => $this->bool(config('app.debug') === false, 'set APP_DEBUG=false'),
            'APP_URL is https' => fn () => $this->bool(str_starts_with(strtolower((string) config('app.url')), 'https://'), 'set APP_URL to the https address'),
            'APP_KEY is set' => fn () => $this->bool(is_string(config('app.key')) && config('app.key') !== '', 'generate the application key'),
            'Session cookies are secure' => fn () => $this->bool(config('session.secure') === true && config('session.http_only') === true, 'set SESSION_SECURE_COOKIE=true'),
            'Mailer is a real transport' => fn () => $this->bool(! in_array(config('mail.default'), ['log', 'array', null, ''], true), 'set MAIL_MAILER to a real transport'),
            'Queue is Redis with Horizon configured' => fn () => $this->queue(),
            'Scheduler is ticking' => fn () => $this->scheduler(),
            'Payment gateway is not the fake' => fn () => $this->fakeGatewayInactive(),
            'A payment gateway is active' => fn () => $this->activeGateway(),
            'Video provider is real and active' => fn () => $this->videoProvider(),
            'Webhook secrets are present' => fn () => $this->webhookSecrets(),
            'Trusted proxies are set' => fn () => $this->trustedProxies(),
            'No demo accounts' => fn () => $this->noDemoAccounts(),
            'Storage link exists' => fn () => $this->bool($this->storageLinked(), 'run php artisan storage:link'),
            'Ledger verifies' => fn () => $this->ledger(),
            'Error tracking DSN is set' => fn () => $this->bool(is_string(config('error-tracking.dsn')) && trim(config('error-tracking.dsn')) !== '', 'set the error tracking DSN'),
        ];

        $results = [];

        foreach ($checks as $name => $check) {
            $results[] = $this->evaluate($name, $check);
        }

        return $results;
    }

    /**
     * @param  Closure(): array{0: bool, 1: string}  $check
     */
    private function evaluate(string $name, Closure $check): PreflightResult
    {
        try {
            [$passed, $note] = $check();

            return new PreflightResult($name, $passed ? PreflightStatus::Pass : PreflightStatus::Fail, $note);
        } catch (Throwable $e) {
            Log::warning('ops preflight check could not be evaluated', ['check' => $name, 'exception' => $e::class]);

            return new PreflightResult($name, PreflightStatus::Unreadable, 'the check could not be read');
        }
    }

    /**
     * A failing check carries its fix as the note; a passing one carries none.
     *
     * @return array{0: bool, 1: string}
     */
    private function bool(bool $passed, string $fix): array
    {
        return [$passed, $passed ? '' : $fix];
    }

    /** @return array{0: bool, 1: string} */
    private function queue(): array
    {
        if (config('queue.default') !== 'redis') {
            return [false, 'set QUEUE_CONNECTION=redis'];
        }

        $environments = (array) config('horizon.environments', []);
        $supervisors = $environments[app()->environment()] ?? $environments['*'] ?? null;

        if (! is_array($supervisors) || $supervisors === []) {
            return [false, 'add this environment to config/horizon.php'];
        }

        $defaults = (array) config('horizon.defaults', []);

        foreach ($supervisors as $name => $supervisor) {
            $merged = array_merge((array) ($defaults[$name] ?? []), (array) $supervisor);

            if (($merged['connection'] ?? null) !== 'redis') {
                return [false, 'every Horizon supervisor must use the redis connection'];
            }
        }

        return [true, ''];
    }

    /** @return array{0: bool, 1: string} */
    private function scheduler(): array
    {
        $tick = Cache::get(OpsStatusReport::SCHEDULER_TICK_KEY);

        if (! is_numeric($tick) || now()->getTimestamp() - (int) $tick > OpsStatusReport::SCHEDULER_STALE_AFTER_SECONDS) {
            return [false, 'no recent tick: start the scheduler, wait 60 seconds and rerun'];
        }

        return [true, ''];
    }

    /** @return array{0: bool, 1: string} */
    private function fakeGatewayInactive(): array
    {
        $fakeActive = PaymentGatewayAccount::query()->active()->where('code', PaymentGatewayCode::Fake->value)->exists();

        return $this->bool(! $fakeActive, 'deactivate the fake gateway in the admin registry');
    }

    /** @return array{0: bool, 1: string} */
    private function activeGateway(): array
    {
        // Model columns only. Resolving the driver could reach the provider.
        $gateway = PaymentGatewayAccount::query()->active()->first();

        if ($gateway === null) {
            return [false, 'activate a payment gateway in the admin registry'];
        }

        if ($gateway->gatewayCode() === PaymentGatewayCode::Fake) {
            return [false, 'the active gateway is the fake'];
        }

        if (! $gateway->hasCompleteCredentials()) {
            return [false, 'the active gateway is missing credentials'];
        }

        return [true, $gateway->mode === PaymentGatewayMode::Live ? 'mode: live' : 'mode: test'];
    }

    /** @return array{0: bool, 1: string} */
    private function videoProvider(): array
    {
        $provider = VideoProvider::query()->active()->first();

        if ($provider === null) {
            return [false, 'activate a video provider in the admin registry'];
        }

        if ($provider->providerCode() === null || $provider->providerCode() === VideoProviderCode::Fake) {
            return [false, 'the active video provider must not be the fake'];
        }

        return [true, ''];
    }

    /** @return array{0: bool, 1: string} */
    private function webhookSecrets(): array
    {
        $gateway = PaymentGatewayAccount::query()->active()->first();
        $provider = VideoProvider::query()->active()->first();

        if ($gateway === null || $provider === null) {
            return [false, 'needs an active gateway and an active video provider'];
        }

        // Presence only, never the value.
        return $this->bool($gateway->hasCredential('webhook_secret') && $provider->hasCredential('webhook_secret'), 'save the webhook secret on the gateway and the video provider');
    }

    /** @return array{0: bool, 1: string} */
    private function trustedProxies(): array
    {
        $proxies = config('trustedproxy.proxies');

        if (! is_string($proxies) || trim($proxies) === '') {
            return [false, 'set TRUSTED_PROXIES to the load balancer address or REMOTE_ADDR'];
        }

        $proxies = trim($proxies);

        if ($proxies === 'REMOTE_ADDR') {
            return [true, ''];
        }

        foreach (explode(',', $proxies) as $entry) {
            $entry = trim($entry);

            // `*` and `**` trust every caller; anything that is not a plain address or CIDR is a typo.
            if ($entry === '' || ! $this->isIpOrCidr($entry)) {
                return [false, 'TRUSTED_PROXIES must be addresses, CIDR ranges or REMOTE_ADDR'];
            }
        }

        return [true, ''];
    }

    private function isIpOrCidr(string $entry): bool
    {
        [$address, $mask] = array_pad(explode('/', $entry, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($mask === null) {
            return true;
        }

        $max = str_contains($address, ':') ? 128 : 32;

        return ctype_digit($mask) && (int) $mask <= $max && IpUtils::checkIp($address, $entry);
    }

    /** @return array{0: bool, 1: string} */
    private function noDemoAccounts(): array
    {
        // Soft-deleted rows count, and the users table alone is enough: tutors, learners and cards hang off a user.
        // Erased accounts carry `@tombstone.invalid`, which is deliberately not on this list.
        $query = User::withTrashed()->where(function ($users): void {
            foreach (self::RESERVED_EMAIL_SUFFIXES as $suffix) {
                $users->orWhereRaw('lower(email) like ?', ['%'.$suffix]);
            }
        });

        return $this->bool(! $query->exists(), 'remove the demo accounts');
    }

    private function storageLinked(): bool
    {
        $link = public_path('storage');

        return is_link($link) && file_exists($link);
    }

    /** @return array{0: bool, 1: string} */
    private function ledger(): array
    {
        // The exit code only. The command's own output names lessons and amounts, and is dropped on purpose.
        // `ops:record-ledger-check` is not used: it writes the cache.
        return $this->bool(Artisan::call('ledger:verify') === 0, 'run php artisan ledger:verify and fix what it reports');
    }
}
