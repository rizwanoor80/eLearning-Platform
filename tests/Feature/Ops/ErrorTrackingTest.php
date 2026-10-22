<?php

use App\Services\ErrorTracking\ErrorTrackingDsn;
use App\Services\ErrorTracking\EventScrubber;
use App\Services\Ops\Preflight;
use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\SentrySdk;
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
    expect(config('error-tracking.dsn'))->toBeNull()
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
    expect(fn () => var_export(config('sentry'), true))->not->toThrow(Throwable::class);
});

it('classifies sensitive keys', function (string $key) {
    expect(EventScrubber::isSensitiveKey($key))->toBeTrue();
})->with([
    'password', 'password_confirmation', 'Authorization', 'authorization', 'Cookie', 'Set-Cookie', 'X-XSRF-TOKEN', 'x-csrf-token',
    'api_key', 'apiKey', 'API-KEY', 'private_key', 'stripe_secret', 'webhook_secret', 'clientSecret', 'access_token', 'remember_token',
    'bearerToken', 'key', 'signature', 'session_id', 'card_number', 'cardNumber', 'cvc', 'cvv', 'iban', 'bank_account_number',
    'routing_number', 'swift_code', 'sort_code', 'payment_token', 'credentials',
]);

it('leaves ordinary keys alone', function (string $key) {
    expect(EventScrubber::isSensitiveKey($key))->toBeFalse();
})->with(['url', 'method', 'user_agent', 'lesson_id', 'status', 'amount_fils', 'message', 'monkey', 'accept', 'content-type', 'host']);

it('masks bearer credentials and sensitive query values inside strings', function () {
    expect(EventScrubber::string('Header Authorization: Bearer abc.DEF-123_xyz== was rejected'))
        ->toBe('Header Authorization: Bearer [redacted] was rejected')
        ->and(EventScrubber::string('https://x.test/cb?lesson=4&token=abc123&signature=ff00&page=2'))
        ->toBe('https://x.test/cb?lesson=4&token=[redacted]&signature=[redacted]&page=2')
        ->and(EventScrubber::string('plain message'))->toBe('plain message');
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
