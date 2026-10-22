<?php

use App\Services\ErrorTracking\ErrorTrackingDsn;
use App\Services\ErrorTracking\EventScrubber;
use App\Services\Ops\Preflight;
use Illuminate\Database\QueryException;
use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Laravel\ServiceProvider;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\Stacktrace;
use Sentry\State\Scope;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

/*
 * R276: error tracking is inert without a DSN, silent on local and testing, and scrubs what it sends.
 */

const FAKE_DSN = 'https://publickey@o0.ingest.invalid/1';

/** A client built from the app's own Sentry config, with its transport replaced by a recorder. */
function recordingClient(): array
{
    $transport = new class implements TransportInterface
    {
        /** @var list<Event> */
        public array $events = [];

        public function send(Event $event): Result
        {
            $this->events[] = $event;

            return new Result(ResultStatus::success(), $event);
        }

        public function close(?int $timeout = null): Result
        {
            return new Result(ResultStatus::success());
        }
    };

    $options = array_intersect_key(config('sentry'), array_flip(['send_default_pii', 'max_request_body_size', 'before_send', 'before_breadcrumb', 'before_send_transaction']));
    $client = ClientBuilder::create(['dsn' => FAKE_DSN, 'default_integrations' => false] + $options)->setTransport($transport)->getClient();

    return [$client, $transport];
}

it('sends nothing without a DSN', function () {
    // Guards against the check below passing only because the package was never discovered.
    expect(app()->providerIsLoaded(ServiceProvider::class))->toBeTrue()
        ->and(config('error-tracking.dsn'))->toBeNull()
        ->and(config('sentry.dsn'))->toBeNull()
        ->and(SentrySdk::getCurrentHub()->getClient()?->getOptions()->getDsn())->toBeNull();
});

it('treats a blank DSN as unset and trims a real one', function () {
    expect(ErrorTrackingDsn::resolve(null, 'production'))->toBeNull()
        ->and(ErrorTrackingDsn::resolve('', 'production'))->toBeNull()
        ->and(ErrorTrackingDsn::resolve('   ', 'production'))->toBeNull()
        ->and(ErrorTrackingDsn::resolve(FAKE_DSN, 'production'))->toBe(FAKE_DSN)
        ->and(ErrorTrackingDsn::resolve(' '.FAKE_DSN.' ', 'rehearsal'))->toBe(FAKE_DSN);
});

it('never has a DSN on local or testing, even when one is set', function (string $environment) {
    expect(ErrorTrackingDsn::resolve(FAKE_DSN, $environment))->toBeNull();
})->with(['local', 'testing']);

it('hands config/sentry.php the same DSN and keeps tracing, logs, metrics and PII off', function () {
    $sentry = config('sentry');

    expect($sentry['dsn'])->toBe(config('error-tracking.dsn'))
        ->and($sentry['send_default_pii'])->toBeFalse()
        ->and($sentry['max_request_body_size'])->toBe('none')
        ->and($sentry['traces_sample_rate'])->toBeNull()
        ->and($sentry['profiles_sample_rate'])->toBeNull()
        ->and($sentry['enable_logs'])->toBeFalse()
        ->and($sentry['enable_metrics'])->toBeFalse()
        ->and(is_callable($sentry['before_send']))->toBeTrue()
        ->and(is_callable($sentry['before_breadcrumb']))->toBeTrue()
        ->and(is_callable($sentry['before_send_transaction']))->toBeTrue();
});

it('keeps the Sentry config serialisable for config:cache', function () {
    // var_export does not throw on a Closure; it prints one. config:cache would then write a file that cannot load.
    expect(var_export(config('sentry'), true))->not->toContain('Closure')
        ->and(config('sentry.before_send'))->toBeArray()
        ->and(config('sentry.before_breadcrumb'))->toBeArray()
        ->and(config('sentry.before_send_transaction'))->toBeArray();
});

it('has no SENTRY_*-named way to switch it on', function () {
    $previous = [$_SERVER['SENTRY_DSN'] ?? null, $_SERVER['SENTRY_SPOTLIGHT'] ?? null];
    $_SERVER['SENTRY_DSN'] = FAKE_DSN;
    $_SERVER['SENTRY_SPOTLIGHT'] = 'http://spotlight.invalid:8969/stream';

    try {
        $options = new Options(array_intersect_key(config('sentry'), array_flip(['dsn', 'spotlight'])));

        expect(config('sentry.spotlight'))->toBeFalse()
            ->and($options->getDsn())->toBeNull()
            ->and($options->isSpotlightEnabled())->toBeFalse();
    } finally {
        foreach (['SENTRY_DSN', 'SENTRY_SPOTLIGHT'] as $i => $name) {
            if ($previous[$i] === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $previous[$i];
            }
        }
    }
});

it('does not forward log lines as breadcrumbs', function () {
    expect(config('sentry.breadcrumbs.logs'))->toBeFalse();
});

it('classifies sensitive keys', function (string $key) {
    expect(EventScrubber::isSensitiveKey($key))->toBeTrue();
})->with([
    'password', 'password_confirmation', 'Authorization', 'authorization', 'Cookie', 'Set-Cookie', 'X-XSRF-TOKEN', 'x-csrf-token',
    'api_key', 'apiKey', 'API-KEY', 'private_key', 'stripe_secret', 'webhook_secret', 'clientSecret', 'access_token', 'remember_token',
    'bearerToken', 'key', 'signature', 'session_id', 'card_number', 'cardNumber', 'cvc', 'cvv', 'iban', 'bank_account_number',
    'routing_number', 'swift_code', 'sort_code', 'payment_token', 'credentials', 'PHP_AUTH_PW', 'two_factor_recovery_codes', 'otp', 'pin',
    'passcode', 'mfa_code', 'jwt', 'dsn', 'bic', 'email', 'phone', 'body',
]);

it('leaves ordinary keys alone', function (string $key) {
    expect(EventScrubber::isSensitiveKey($key))->toBeFalse();
})->with(['url', 'method', 'user_agent', 'lesson_id', 'status', 'amount_fils', 'message', 'monkey', 'accept', 'content-type', 'host', 'shipping', 'spinner', 'opinion']);

it('masks bearer credentials and sensitive query values inside strings', function () {
    expect(EventScrubber::string('Gateway rejected Bearer abc.DEF-123_xyz== again'))
        ->toBe('Gateway rejected Bearer [redacted] again')
        ->and(EventScrubber::string('https://x.test/cb?lesson=4&token=abc123&signature=ff00&page=2'))
        ->toBe('https://x.test/cb?lesson=4&token=[redacted]&signature=[redacted]&page=2')
        ->and(EventScrubber::string('plain message'))->toBe('plain message');
});

it('masks secrets that appear anywhere in a message', function (string $input, string $secret) {
    expect(EventScrubber::string($input))->not->toContain($secret)->toContain(EventScrubber::REDACTED);
})->with([
    'console option' => ['ops:x --token=abc123SECRET run', 'abc123SECRET'],
    'prose pair' => ['login failed password=hunter2SECRET for user', 'hunter2SECRET'],
    'json body' => ['{"user":"a","password":"hunter2SECRET","api_key":"k"}', 'hunter2SECRET'],
    'header line' => ['Authorization: sk_live_ABCDEFSECRET', 'ABCDEFSECRET'],
    'provider key' => ['used sk_live_ABCDEFSECRET to charge', 'ABCDEFSECRET'],
    'card number' => ['declined 4242 4242 4242 4242 today', '4242 4242'],
    'reset link' => ['GET https://x.test/reset-password/TOKENSECRET123?email=a', 'TOKENSECRET123'],
]);

it('keeps ordinary messages readable', function () {
    expect(EventScrubber::string('Lesson 41 failed at 2026-10-22 12:00:00 (status=failed)'))->toBe('Lesson 41 failed at 2026-10-22 12:00:00 (status=failed)');
});

it('never sends the message of an exception that carries bound values', function (Throwable $exception) {
    [$client, $transport] = recordingClient();

    $client->captureException($exception);

    $json = json_encode(array_map(fn ($e) => [$e->getType(), $e->getValue()], $transport->events[0]->getExceptions()));

    expect($json)->not->toContain('Aisha')->not->toContain('0501234567')->toContain(EventScrubber::REDACTED);
})->with([
    'query' => fn () => new QueryException('pgsql', 'insert into messages (body) values (?)', ['Call 0501234567, my daughter Aisha is 9'], new PDOException('deadlock')),
    'pdo' => fn () => new PDOException('DETAIL: Key (email)=(aisha@example.test) already exists. 0501234567 Aisha'),
]);

it('empties the recorded arguments of every stack frame', function () {
    [$client, $transport] = recordingClient();

    $event = Event::createEvent();
    $event->setExceptions([new ExceptionDataBag(new RuntimeException('boom'), new Stacktrace([new Frame('charge', 'app/Pay.php', 10, null, null, ['cardToken' => 'pm_SECRETCARD', 'password' => 'hunter2PW'])]))]);

    $client->captureEvent($event);

    $frames = $transport->events[0]->getExceptions()[0]->getStacktrace()->getFrames();

    expect($frames[0]->getVars())->toBe([])
        ->and(json_encode($transport->events[0]->getExceptions()))->not->toContain('pm_SECRETCARD')->not->toContain('hunter2PW');
});

it('scrubs a captured event end to end', function () {
    [$client, $transport] = recordingClient();

    $event = Event::createEvent();
    $event->setRequest([
        'url' => 'https://rehearsal.example/pay?token=SECRETVALUE&lesson=4',
        'method' => 'POST',
        'query_string' => 'token=SECRETVALUE&lesson=4',
        'data' => ['card_number' => '4242424242424242', 'body' => 'a parent message'],
        'cookies' => ['laravel_session' => 'SESSIONVALUE'],
        'headers' => ['Authorization' => ['Bearer SECRETVALUE'], 'Cookie' => ['laravel_session=SESSIONVALUE'], 'User-Agent' => ['Pest'], 'X-Api-Key' => ['SECRETVALUE']],
    ]);
    $event->setExtra(['stripe_secret' => 'SECRETVALUE', 'iban' => 'AE070331234567890123456', 'lesson_id' => 7, 'nested' => ['access_token' => 'SECRETVALUE', 'ok' => 'fine'], 'note' => 'sent Bearer SECRETVALUE once']);
    $event->setTags(['webhook_secret' => 'SECRETVALUE', 'route' => 'pay.show']);
    $event->setContext('payload', ['password' => 'SECRETVALUE', 'amount_fils' => 6000]);
    $event->setMessage('Charge failed for ?token=SECRETVALUE');

    $scope = new Scope;
    $scope->addBreadcrumb(new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'log', 'Bearer SECRETVALUE', ['card_number' => '4242424242424242', 'lesson' => 7]));

    $client->captureEvent($event, EventHint::fromArray([]), $scope);

    expect($transport->events)->toHaveCount(1);

    $sent = $transport->events[0];
    $json = json_encode([
        $sent->getRequest(), $sent->getExtra(), $sent->getTags(), $sent->getContexts(), $sent->getMessage(),
        array_map(fn (Breadcrumb $b) => [$b->getMessage(), $b->getMetadata()], $sent->getBreadcrumbs()),
    ]);

    expect($json)->not->toContain('SECRETVALUE')
        ->and($json)->not->toContain('SESSIONVALUE')
        ->and($json)->not->toContain('4242424242424242')
        ->and($json)->not->toContain('AE0703312345')
        ->and($json)->not->toContain('a parent message');

    expect($sent->getRequest())->not->toHaveKeys(['data', 'cookies'])
        ->and($sent->getRequest()['method'])->toBe('POST')
        ->and($sent->getRequest()['headers']['User-Agent'])->toBe(['Pest'])
        ->and($sent->getRequest()['headers']['Authorization'])->toBe(EventScrubber::REDACTED)
        ->and($sent->getRequest()['query_string'])->toBe('token=[redacted]&lesson=4')
        ->and($sent->getExtra()['lesson_id'])->toBe(7)
        ->and($sent->getExtra()['nested'])->toBe(['access_token' => EventScrubber::REDACTED, 'ok' => 'fine'])
        ->and($sent->getTags())->toBe(['webhook_secret' => EventScrubber::REDACTED, 'route' => 'pay.show'])
        ->and($sent->getContexts()['payload'])->toBe(['password' => EventScrubber::REDACTED, 'amount_fils' => 6000])
        ->and($sent->getUser())->toBeNull();
});

it('scrubs an exception message', function () {
    [$client, $transport] = recordingClient();

    $client->captureException(new RuntimeException('Gateway said: Bearer SECRETVALUE is invalid'));

    $values = array_map(fn ($e) => $e->getValue(), $transport->events[0]->getExceptions());

    expect($values)->toBe(['Gateway said: Bearer [redacted] is invalid']);
});

it('drops every transaction', function () {
    $event = Event::createTransaction();

    expect(EventScrubber::dropTransaction($event))->toBeNull();
});

it('bounds how deep it walks', function () {
    $deep = ['ok' => 'x'];

    for ($i = 0; $i < 20; $i++) {
        $deep = ['level' => $deep];
    }

    $event = Event::createEvent();
    $event->setExtra($deep);

    expect(json_encode(EventScrubber::beforeSend($event)->getExtra()))->toContain(EventScrubber::REDACTED)->not->toContain('"ok"');
});

it('is what ops:preflight reads for the DSN check', function () {
    $find = fn () => collect(app(Preflight::class)->run())->firstWhere('name', 'Error tracking DSN is set');

    config(['error-tracking.dsn' => null]);
    expect($find()->passed())->toBeFalse();

    config(['error-tracking.dsn' => FAKE_DSN]);
    expect($find()->passed())->toBeTrue()->and(json_encode($find()))->not->toContain('ingest.invalid');
});
