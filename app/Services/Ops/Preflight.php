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
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
    /**
     * Email patterns no real account has (`like`, matched on the lower-cased, trimmed address): the reserved example
     * domains and their subdomains, and the reserved TLDs. A demo or factory user on production is a defect.
     */
    private const RESERVED_EMAIL_PATTERNS = ['%@example.%', '%.test', '%.localhost', '%.example', '%.example.com', '%.example.org', '%.example.net'];

    /** `.invalid` is reserved too, but an erased account's tombstone address (`AnonymizeUser`) is legitimate on production. */
    private const TOMBSTONE_EMAIL_PATTERN = 'deleted+%@tombstone.invalid';

    /** A proxy range wider than this trusts a large part of the internet; REMOTE_ADDR is the way to say "trust the caller". */
    private const MIN_PROXY_PREFIX_IPV4 = 8;

    private const MIN_PROXY_PREFIX_IPV6 = 16;

    /**
     * @return list<PreflightResult>
     */
    public function run(): array
    {
        $checks = [
            'APP_ENV is production' => fn () => $this->bool(app()->environment('production'), 'set APP_ENV=production'),
            'APP_DEBUG is off' => fn () => $this->bool(config('app.debug') === false, 'set APP_DEBUG=false'),
            'APP_URL is https' => fn () => $this->bool($this->httpsUrl(config('app.url')), 'set APP_URL to the https address'),
            'APP_KEY is set' => fn () => $this->bool($this->validKey(config('app.key'), config('app.cipher')), 'generate the application key'),
            'Session cookies are secure' => fn () => $this->bool(config('session.secure') === true && config('session.http_only') === true, 'set SESSION_SECURE_COOKIE=true'),
            'Mailer is a real transport' => fn () => $this->bool($this->realMailer(config('mail.default')), 'set MAIL_MAILER to a real transport'),
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

    private function httpsUrl(mixed $url): bool
    {
        if (! is_string($url) || ! str_starts_with(strtolower($url), 'https://')) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '';
    }

    /** Shape only: the key must decode to the length the configured cipher needs. The key itself is never returned. */
    private function validKey(mixed $key, mixed $cipher): bool
    {
        if (! is_string($key) || $key === '' || ! is_string($cipher)) {
            return false;
        }

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true);

            if ($key === false) {
                return false;
            }
        }

        return Encrypter::supported($key, $cipher);
    }

    /**
     * The mailer is judged by its transport, not its name: a mailer called `smtp` can be configured with the `log`
     * transport, and `failover` is only as real as its members.
     *
     * @param  list<string>  $seen
     */
    private function realMailer(mixed $name, array $seen = []): bool
    {
        if (! is_string($name) || $name === '' || in_array($name, $seen, true)) {
            return false;
        }

        $mailer = config("mail.mailers.{$name}");

        if (! is_array($mailer) || ! is_string($mailer['transport'] ?? null)) {
            return false;
        }

        $transport = $mailer['transport'];

        if (in_array($transport, ['log', 'array'], true)) {
            return false;
        }

        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $members = $mailer['mailers'] ?? null;

            if (! is_array($members) || $members === []) {
                return false;
            }

            foreach ($members as $member) {
                if (! $this->realMailer($member, [...$seen, $name])) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return array{0: bool, 1: string} */
    private function queue(): array
    {
        if (config('queue.default') !== 'redis') {
            return [false, 'set QUEUE_CONNECTION=redis'];
        }

        // The way Horizon picks its supervisors: the first environment key, in config order, that matches the app's.
        $supervisors = null;

        foreach ((array) config('horizon.environments', []) as $key => $candidate) {
            if (Str::is((string) $key, app()->environment())) {
                $supervisors = $candidate;
                break;
            }
        }

        if (! is_array($supervisors) || $supervisors === []) {
            return [false, 'add this environment to config/horizon.php'];
        }

        $defaults = (array) config('horizon.defaults', []);
        $running = 0;

        foreach ($supervisors as $name => $supervisor) {
            $merged = array_merge((array) ($defaults[$name] ?? []), (array) $supervisor);

            // Horizon starts no process for a supervisor with zero `maxProcesses`.
            if (! is_numeric($merged['maxProcesses'] ?? null) || (int) $merged['maxProcesses'] <= 0) {
                continue;
            }

            $running++;

            if (($merged['connection'] ?? null) !== 'redis') {
                return [false, 'every Horizon supervisor must use the redis connection'];
            }
        }

        return $running > 0 ? [true, ''] : [false, 'no Horizon supervisor would start in this environment'];
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

        // The registry's own rules for an active row, which can stop holding after an environment change (live mode
        // switched off, a key swapped): pure model reads, no driver and no provider call.
        if ($gateway->activationProblem() !== null || $gateway->keyModeProblem() !== null) {
            return [false, 'the active gateway cannot be used here: open it in the admin registry'];
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

        if ($provider->activationProblem() !== null) {
            return [false, 'the active video provider cannot be used here: open it in the admin registry'];
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
            return [true, 'trusts the caller: the origin must be reachable only through the load balancer'];
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

        $ipv6 = str_contains($address, ':');
        $max = $ipv6 ? 128 : 32;
        $min = $ipv6 ? self::MIN_PROXY_PREFIX_IPV6 : self::MIN_PROXY_PREFIX_IPV4;

        return ctype_digit($mask) && (int) $mask >= $min && (int) $mask <= $max && IpUtils::checkIp($address, $entry);
    }

    /** @return array{0: bool, 1: string} */
    private function noDemoAccounts(): array
    {
        // Soft-deleted rows count, and the users table alone is enough: tutors, learners and cards hang off a user.
        $query = User::withTrashed()->where(function ($users): void {
            foreach (self::RESERVED_EMAIL_PATTERNS as $pattern) {
                $users->orWhereRaw('lower(trim(email)) like ?', [$pattern]);
            }

            $users->orWhere(function ($invalid): void {
                $invalid->whereRaw('lower(trim(email)) like ?', ['%.invalid'])
                    ->whereRaw('lower(trim(email)) not like ?', [self::TOMBSTONE_EMAIL_PATTERN]);
            });
        });

        return $this->bool(! $query->exists(), 'remove the demo accounts');
    }

    private function storageLinked(): bool
    {
        $link = public_path('storage');
        $target = realpath(storage_path('app/public'));

        // The link must resolve to the public disk's root; a link to anywhere else serves the wrong files.
        return is_link($link) && $target !== false && realpath($link) === $target;
    }

    /** @return array{0: bool, 1: string} */
    private function ledger(): array
    {
        // The exit code only. The command's own output names lessons and amounts, and is dropped on purpose.
        // `ops:record-ledger-check` is not used: it writes the cache.
        return $this->bool(Artisan::call('ledger:verify') === 0, 'run php artisan ledger:verify and fix what it reports');
    }
}
