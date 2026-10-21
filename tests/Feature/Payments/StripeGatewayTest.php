<?php

use App\Enums\PaymentGatewayMode;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentCapturedUnverified;
use App\Exceptions\PaymentCaptureException;
use App\Exceptions\PaymentGatewayUnavailable;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentGatewayAccount;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Payments\NoActivePaymentGateway;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\StripePaymentGateway;
use App\Support\Money;
use App\Support\Payments\CheckoutIntent;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\HttpClient\CurlClient;
use Tests\Support\Payments\FakeStripeHttp;

/**
 * 23b (R247): every `StripePaymentGateway` method against a faked HTTP client. Only Stripe's public test
 * values appear here (a `4242` last four, `pi_test_…` ids); no key is real.
 */
beforeEach(function () {
    $this->http = FakeStripeHttp::install();
    $this->gateway = new StripePaymentGateway('sk_test_example', 'whsec_example');
    $this->payer = User::factory()->create();
    $this->lesson = Lesson::factory()->create(['booked_by_user_id' => $this->payer->id]);
});

afterEach(function () {
    FakeStripeHttp::uninstall();
});

function stripeIntent(array $overrides = []): array
{
    return array_replace([
        'id' => 'pi_test_1',
        'object' => 'payment_intent',
        'status' => 'requires_capture',
        'capture_method' => 'manual',
        'amount' => 10000,
        'amount_capturable' => 10000,
        'amount_received' => 0,
        'currency' => 'aed',
        'livemode' => false,
        'latest_charge' => 'ch_test_1',
        'metadata' => stripeBinding(),
    ], $overrides);
}

/** The metadata the checkout writes for `$lesson`'s booking. */
function stripeBinding(array $overrides = []): array
{
    $lesson = test()->lesson;

    return array_replace([
        'kind' => 'checkout',
        'account_user_id' => (string) test()->payer->id,
        'learner_id' => (string) $lesson->learner_id,
        'tutor_profile_id' => (string) $lesson->tutor_profile_id,
        'starts_at' => $lesson->starts_at->clone()->utc()->format(StripePaymentGateway::STARTS_AT_FORMAT),
    ], $overrides);
}

/** What Stripe returns once the held intent is captured. */
function stripeCaptured(array $overrides = []): array
{
    return array_replace(stripeIntent(), [
        'status' => 'succeeded',
        'amount_capturable' => 0,
        'amount_received' => 10000,
        'latest_charge' => ['id' => 'ch_test_1', 'object' => 'charge', 'refunded' => false, 'disputed' => false, 'amount_refunded' => 0],
    ], $overrides);
}

function stripeChooses(string $intentId): void
{
    app(CheckoutIntent::class)->set($intentId);
}

it('bounds every Stripe call, so two calls in one queued job stay under the worker timeout (R260)', function () {
    new StripePaymentGateway('sk_test_example', 'whsec_example');

    $client = CurlClient::instance();

    expect($client->getTimeout())->toBe(StripePaymentGateway::TIMEOUT_SECONDS)
        ->and($client->getConnectTimeout())->toBe(StripePaymentGateway::CONNECT_TIMEOUT_SECONDS)
        ->and(StripePaymentGateway::TIMEOUT_SECONDS * 2)->toBeLessThan(60);
});

it('captures the held intent the parent confirmed, and keeps only a whitelisted summary', function () {
    $this->http
        ->reply('GET /v1/payment_intents/pi_test_1', stripeIntent(['customer' => 'cus_secret', 'payment_method' => 'pm_secret']))
        ->reply('POST /v1/payment_intents/pi_test_1/capture', stripeCaptured(['customer' => 'cus_secret', 'payment_method' => 'pm_secret']));
    stripeChooses('pi_test_1');

    $result = $this->gateway->capture($this->lesson, Money::fils(10000), (string) $this->lesson->id);

    expect($result->gatewayRef)->toBe('pi_test_1')
        ->and($result->rawResponse)->toBe(['driver' => 'stripe', 'id' => 'pi_test_1', 'status' => 'succeeded', 'amount' => 10000, 'amount_received' => 10000, 'currency' => 'aed', 'latest_charge' => 'ch_test_1'])
        ->and(json_encode($result->rawResponse))->not->toContain('cus_secret')->not->toContain('pm_secret')
        ->and($this->http->requests)->toHaveCount(2);
});

it('captures once per intent: the capture call carries an idempotency key derived from the intent', function () {
    $this->http
        ->reply('GET /v1/payment_intents/pi_test_1', stripeIntent())
        ->reply('POST /v1/payment_intents/pi_test_1/capture', stripeCaptured());
    stripeChooses('pi_test_1');

    $this->gateway->capture($this->lesson, Money::fils(10000), '1');

    $calls = $this->http->requestsTo('POST /v1/payment_intents/pi_test_1/capture');

    expect($calls)->toHaveCount(1)
        ->and($this->http->idempotencyKeyOf($calls[0]))->toBe('test:testing:capture-pi_test_1');
});

it('captures nothing when the checkout named no intent', function () {
    expect(fn () => $this->gateway->capture($this->lesson, Money::fils(10000), '1'))->toThrow(PaymentCaptureException::class, 'no_payment_intent')
        ->and($this->http->requests)->toHaveCount(0);
});

it('refuses an intent that is not the held authorisation for this booking, and never calls capture', function (array $override, string $reason) {
    $this->http->reply('GET /v1/payment_intents/pi_test_1', stripeIntent($override));
    stripeChooses('pi_test_1');

    expect(fn () => $this->gateway->capture($this->lesson, Money::fils(10000), '1'))->toThrow(PaymentCaptureException::class, $reason)
        ->and($this->http->requestsTo('POST /v1/payment_intents/pi_test_1/capture'))->toBeEmpty();
})->with([
    'not confirmed yet' => [['status' => 'requires_payment_method'], 'payment_not_completed'],
    'processing' => [['status' => 'processing'], 'payment_not_completed'],
    'captured for a different amount' => [['status' => 'succeeded', 'amount_received' => 5000], 'payment_not_completed'],
    'cancelled' => [['status' => 'canceled'], 'payment_not_completed'],
    'automatic capture' => [['capture_method' => 'automatic'], 'payment_not_completed'],
    'too little' => [['amount' => 9999, 'amount_capturable' => 9999], 'amount_mismatch'],
    'too much' => [['amount' => 10001, 'amount_capturable' => 10001], 'amount_mismatch'],
    'less held than asked' => [['amount_capturable' => 5000], 'amount_mismatch'],
    'wrong currency' => [['currency' => 'usd'], 'amount_mismatch'],
    'live intent on a test row' => [['livemode' => true], 'mode_mismatch'],
    'a saved-card charge, not a checkout' => [['metadata' => ['kind' => 'recurring', 'account_user_id' => '1']], 'not_a_checkout_intent'],
    'no purpose recorded' => [['metadata' => ['account_user_id' => '1']], 'not_a_checkout_intent'],
]);

it('refuses an authorisation made by or for someone else', function (array $binding, string $reason) {
    $this->http->reply('GET /v1/payment_intents/pi_test_1', stripeIntent(['metadata' => stripeBinding($binding)]));
    stripeChooses('pi_test_1');

    expect(fn () => $this->gateway->capture($this->lesson, Money::fils(10000), '1'))->toThrow(PaymentCaptureException::class, $reason)
        ->and($this->http->requestsTo('POST /v1/payment_intents/pi_test_1/capture'))->toBeEmpty();
})->with([
    'another parent paid' => [['account_user_id' => '999999'], 'payer_mismatch'],
    'no payer recorded' => [['account_user_id' => ''], 'payer_mismatch'],
    'another learner' => [['learner_id' => '999999'], 'booking_mismatch'],
    'no learner recorded' => [['learner_id' => ''], 'booking_mismatch'],
    'another tutor' => [['tutor_profile_id' => '999999'], 'booking_mismatch'],
    'another time' => [['starts_at' => '2001-01-01T00:00:00Z'], 'booking_mismatch'],
    'no time recorded' => [['starts_at' => ''], 'booking_mismatch'],
]);

it('does not call a capture that did not leave a clean, fully paid charge a decline: it hands back the reference', function (array $override) {
    $this->http
        ->reply('GET /v1/payment_intents/pi_test_1', stripeIntent())
        ->reply('POST /v1/payment_intents/pi_test_1/capture', stripeCaptured($override));
    stripeChooses('pi_test_1');

    try {
        $this->gateway->capture($this->lesson, Money::fils(10000), '1');
        $this->fail('Expected PaymentCapturedUnverified.');
    } catch (PaymentCapturedUnverified $e) {
        expect($e)->not->toBeInstanceOf(PaymentCaptureException::class)
            ->and($e->gatewayRef)->toBe('pi_test_1')
            ->and($e->rawResponse['id'])->toBe('pi_test_1');
    }
})->with([
    'still not succeeded' => [['status' => 'processing']],
    'not all received' => [['amount_received' => 5000]],
    'refunded' => [['latest_charge' => ['id' => 'ch_test_1', 'object' => 'charge', 'refunded' => true, 'disputed' => false, 'amount_refunded' => 10000]]],
    'disputed' => [['latest_charge' => ['id' => 'ch_test_1', 'object' => 'charge', 'refunded' => false, 'disputed' => true, 'amount_refunded' => 0]]],
    'no charge to check' => [['latest_charge' => null]],
]);

it('turns a capture Stripe will no longer take into a decline, not a crash', function () {
    $this->http
        ->reply('GET /v1/payment_intents/pi_test_1', stripeIntent())
        ->reply('POST /v1/payment_intents/pi_test_1/capture', ['error' => ['type' => 'invalid_request_error', 'code' => 'payment_intent_unexpected_state', 'message' => 'canceled']], 400);
    stripeChooses('pi_test_1');

    expect(fn () => $this->gateway->capture($this->lesson, Money::fils(10000), '1'))->toThrow(PaymentCaptureException::class, 'payment_not_completed');
});

it('refuses an intent that already pays for another lesson', function () {
    Payment::factory()->create(['gateway' => 'stripe', 'gateway_ref' => 'pi_test_1']);
    $this->http->reply('GET /v1/payment_intents/pi_test_1', stripeIntent());
    stripeChooses('pi_test_1');

    expect(fn () => $this->gateway->capture($this->lesson, Money::fils(10000), '1'))->toThrow(PaymentCaptureException::class, 'payment_already_used')
        ->and($this->http->requestsTo('POST /v1/payment_intents/pi_test_1/capture'))->toBeEmpty();
});

it('turns an unknown intent id into a decline, not a crash', function () {
    $this->http->reply('GET /v1/payment_intents/pi_nope', ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such payment_intent']], 404);
    stripeChooses('pi_nope');

    expect(fn () => $this->gateway->capture($this->lesson, Money::fils(10000), '1'))->toThrow(PaymentCaptureException::class, 'payment_not_found');
});

it('keeps its keys out of a dump and refuses to be serialised', function () {
    $gateway = new StripePaymentGateway('sk_test_dump_probe', 'whsec_dump_probe');

    ob_start();
    var_dump($gateway);
    $dump = (string) ob_get_clean();

    expect($dump)->not->toContain('dump_probe')
        ->and(fn () => serialize($gateway))->toThrow(LogicException::class);
});

it('reports an unreachable gateway as unavailable, never as a decline', function () {
    stripeChooses('pi_test_1');

    try {
        $this->gateway->capture($this->lesson, Money::fils(10000), '1');
        $this->fail('Expected PaymentGatewayUnavailable');
    } catch (PaymentGatewayUnavailable $e) {
        expect($e->intentId)->toBe('pi_test_1')->and($e->getPrevious())->toBeInstanceOf(ApiConnectionException::class);
    }
});

it('retries a capture after a dropped connection with one intent and one capture (R259 (1))', function () {
    // First attempt: the capture call fails, and the look-up that follows has no answer either.
    $this->http
        ->reply('GET /v1/payment_intents/pi_test_1', stripeIntent())
        ->reply('POST /v1/payment_intents/pi_test_1/capture', ['error' => ['type' => 'api_error', 'message' => 'x']], 500);
    stripeChooses('pi_test_1');

    expect(fn () => $this->gateway->capture($this->lesson, Money::fils(10000), '1'))->toThrow(PaymentGatewayUnavailable::class);

    // Second attempt, same hold: it goes through.
    $this->http
        ->reply('GET /v1/payment_intents/pi_test_1', stripeIntent())
        ->reply('POST /v1/payment_intents/pi_test_1/capture', stripeCaptured());

    $result = $this->gateway->capture($this->lesson, Money::fils(10000), '1');

    expect($result->gatewayRef)->toBe('pi_test_1')
        ->and($this->http->requestsTo('POST /v1/payment_intents/pi_test_1/capture'))->toHaveCount(2)
        ->and(collect($this->http->requestsTo('POST /v1/payment_intents/pi_test_1/capture'))->map(fn ($r) => $this->http->idempotencyKeyOf($r))->unique()->all())->toBe(['test:testing:capture-pi_test_1'])
        ->and($this->http->requestsTo('POST /v1/payment_intents'))->toHaveCount(2);
});

it('treats a rate limit and a lock timeout as retryable, not as a refusal', function (array $error, int $status) {
    $this->http
        ->reply('GET /v1/payment_intents/pi_test_1', stripeIntent())
        ->reply('POST /v1/payment_intents/pi_test_1/capture', ['error' => $error], $status)
        ->reply('GET /v1/payment_intents/pi_test_1', stripeIntent());
    stripeChooses('pi_test_1');

    expect(fn () => $this->gateway->capture($this->lesson, Money::fils(10000), '1'))->toThrow(PaymentGatewayUnavailable::class);
})->with([
    'rate limit' => [['type' => 'invalid_request_error', 'code' => 'rate_limit', 'message' => 'slow down'], 429],
    'lock timeout' => [['type' => 'invalid_request_error', 'code' => 'lock_timeout', 'message' => 'busy'], 409],
]);

it('finds out what happened when the capture answer is lost: taken means the booking carries on', function () {
    $this->http
        ->reply('GET /v1/payment_intents/pi_test_1', stripeIntent())
        ->reply('POST /v1/payment_intents/pi_test_1/capture', ['error' => ['type' => 'api_error', 'message' => 'x']], 500)
        ->reply('GET /v1/payment_intents/pi_test_1', stripeCaptured());
    stripeChooses('pi_test_1');

    $result = $this->gateway->capture($this->lesson, Money::fils(10000), '1');

    expect($result->gatewayRef)->toBe('pi_test_1')
        ->and($this->http->requestsTo('POST /v1/payment_intents/pi_test_1/capture'))->toHaveCount(1);
});

it('resumes an intent a first request already captured, without capturing again', function () {
    $this->http->reply('GET /v1/payment_intents/pi_test_1', stripeCaptured());
    stripeChooses('pi_test_1');

    $result = $this->gateway->capture($this->lesson, Money::fils(10000), '1');

    expect($result->gatewayRef)->toBe('pi_test_1')
        ->and($this->http->requestsTo('POST /v1/payment_intents/pi_test_1/capture'))->toBeEmpty();
});

it('does not resume a captured intent another payment already carries', function () {
    Payment::factory()->create(['gateway' => 'stripe', 'gateway_ref' => 'pi_test_1']);
    $this->http->reply('GET /v1/payment_intents/pi_test_1', stripeCaptured());
    stripeChooses('pi_test_1');

    expect(fn () => $this->gateway->capture($this->lesson, Money::fils(10000), '1'))->toThrow(PaymentCaptureException::class, 'payment_already_used');
});

it('saves a card and returns the token and display details only', function () {
    $this->http
        ->reply('GET /v1/setup_intents/seti_test_1', [
            'id' => 'seti_test_1', 'object' => 'setup_intent', 'status' => 'succeeded', 'customer' => 'cus_test_1',
            'metadata' => ['account_user_id' => (string) $this->payer->id],
            'payment_method' => ['id' => 'pm_test_1', 'object' => 'payment_method', 'customer' => null, 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2034]],
        ])
        ->reply('POST /v1/payment_methods/pm_test_1/attach', [
            'id' => 'pm_test_1', 'object' => 'payment_method', 'customer' => 'cus_test_1',
            'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2034],
        ]);

    $card = $this->gateway->saveCard($this->payer, 'seti_test_1');

    expect($card->gatewayCustomerRef)->toBe('cus_test_1')
        ->and($card->gatewayToken)->toBe('pm_test_1')
        ->and($card->brand)->toBe('Visa')
        ->and($card->last4)->toBe('4242')
        ->and([$card->expMonth, $card->expYear])->toBe([12, 2034]);
});

it('refuses to save a card from a setup that is not complete or not this account\'s', function (array $override, string $reason) {
    $this->http->reply('GET /v1/setup_intents/seti_test_1', array_replace([
        'id' => 'seti_test_1', 'object' => 'setup_intent', 'status' => 'succeeded', 'customer' => 'cus_test_1',
        'metadata' => ['account_user_id' => (string) test()->payer->id],
        'payment_method' => ['id' => 'pm_test_1', 'object' => 'payment_method', 'customer' => 'cus_test_1', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 1, 'exp_year' => 2034]],
    ], $override));

    expect(fn () => $this->gateway->saveCard($this->payer, 'seti_test_1'))->toThrow(PaymentCaptureException::class, $reason);
})->with([
    'not succeeded' => [['status' => 'requires_action'], 'setup_not_completed'],
    'no customer' => [['customer' => null], 'setup_not_completed'],
    'another account' => [['metadata' => ['account_user_id' => '999999']], 'payer_mismatch'],
]);

it('charges a saved card off session with the caller\'s idempotency key', function () {
    $method = PaymentMethod::factory()->create(['account_user_id' => $this->payer->id, 'gateway' => 'stripe', 'gateway_customer_ref' => 'cus_test_1', 'gateway_token' => 'pm_test_1']);
    $this->http->reply('POST /v1/payment_intents', stripeCaptured(['id' => 'pi_test_2']));

    $result = $this->gateway->chargeSavedCard($this->lesson, Money::fils(10000), $method, 'lesson:7:attempt:1');
    [$request] = $this->http->requestsTo('POST /v1/payment_intents');

    expect($result->gatewayRef)->toBe('pi_test_2')
        ->and($this->http->idempotencyKeyOf($request))->toBe('test:testing:lesson:7:attempt:1')
        ->and($request['params'])->toMatchArray(['amount' => 10000, 'currency' => 'aed', 'customer' => 'cus_test_1', 'payment_method' => 'pm_test_1', 'off_session' => true, 'confirm' => true]);
});

it('treats a decline and a card that needs the cardholder as ordinary failed attempts', function () {
    $method = PaymentMethod::factory()->create(['account_user_id' => $this->payer->id, 'gateway' => 'stripe']);

    $this->http->reply('POST /v1/payment_intents', ['error' => ['type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'insufficient_funds', 'message' => 'Declined']], 402);

    expect(fn () => $this->gateway->chargeSavedCard($this->lesson, Money::fils(10000), $method, 'k1'))->toThrow(PaymentCaptureException::class, 'insufficient_funds');

    $this->http->reply('POST /v1/payment_intents', ['error' => ['type' => 'card_error', 'code' => 'authentication_required', 'message' => 'Needs the cardholder', 'payment_intent' => ['id' => 'pi_test_3', 'object' => 'payment_intent', 'status' => 'requires_payment_method']]], 402);

    expect(fn () => $this->gateway->chargeSavedCard($this->lesson, Money::fils(10000), $method, 'k2'))->toThrow(PaymentCaptureException::class, 'authentication_required');

    $this->http
        ->reply('POST /v1/payment_intents', stripeCaptured(['id' => 'pi_test_4', 'status' => 'requires_action']))
        ->reply('POST /v1/payment_intents/pi_test_4/cancel', stripeCaptured(['id' => 'pi_test_4', 'status' => 'canceled']));

    expect(fn () => $this->gateway->chargeSavedCard($this->lesson, Money::fils(10000), $method, 'k3'))->toThrow(PaymentCaptureException::class, 'authentication_required')
        ->and($this->http->requestsTo('POST /v1/payment_intents/pi_test_4/cancel'))->toHaveCount(1);
});

it('lets a network failure on a saved-card charge propagate so the same key can be retried', function () {
    $method = PaymentMethod::factory()->create(['account_user_id' => $this->payer->id, 'gateway' => 'stripe']);

    expect(fn () => $this->gateway->chargeSavedCard($this->lesson, Money::fils(10000), $method, 'k1'))->toThrow(ApiConnectionException::class);
});

it('refuses a saved card that belongs to another gateway without calling Stripe', function () {
    $method = PaymentMethod::factory()->create(['account_user_id' => $this->payer->id, 'gateway' => 'fake']);

    expect(fn () => $this->gateway->chargeSavedCard($this->lesson, Money::fils(10000), $method, 'k1'))->toThrow(PaymentCaptureException::class, 'payment_method_not_stripe')
        ->and($this->http->requests)->toHaveCount(0);
});

it('turns a card Stripe no longer knows into a failed attempt, so the slot is not stuck pending', function (int $status, array $error) {
    $method = PaymentMethod::factory()->create(['account_user_id' => $this->payer->id, 'gateway' => 'stripe']);
    $this->http->reply('POST /v1/payment_intents', ['error' => $error], $status);

    expect(fn () => $this->gateway->chargeSavedCard($this->lesson, Money::fils(10000), $method, 'k1'))->toThrow(PaymentCaptureException::class, 'card_unusable');
})->with([
    'unknown payment method' => [400, ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such PaymentMethod']],
]);

it('does not call a rate limit or a lock timeout on a saved-card charge a dead card', function (array $error, int $status) {
    $method = PaymentMethod::factory()->create(['account_user_id' => $this->payer->id, 'gateway' => 'stripe']);
    $this->http->reply('POST /v1/payment_intents', ['error' => $error], $status);

    expect(fn () => $this->gateway->chargeSavedCard($this->lesson, Money::fils(10000), $method, 'k1'))->toThrow(ApiErrorException::class);
})->with([
    'rate limit' => [['type' => 'invalid_request_error', 'code' => 'rate_limit', 'message' => 'slow down'], 429],
    'lock timeout' => [['type' => 'invalid_request_error', 'code' => 'lock_timeout', 'message' => 'busy'], 409],
]);

it('answers a key reused with other parameters by finding the attempt\'s intent, including one made before the metadata key', function () {
    $method = PaymentMethod::factory()->create(['account_user_id' => $this->payer->id, 'gateway' => 'stripe', 'gateway_customer_ref' => 'cus_test_1']);
    Payment::factory()->create(['lesson_id' => $this->lesson->id, 'attempt_no' => 1, 'gateway_ref' => null, 'status' => PaymentStatus::Pending]);
    $this->http
        ->reply('POST /v1/payment_intents', ['error' => ['type' => 'idempotency_error', 'message' => 'Keys must be used with the same parameters']], 400)
        ->reply('GET /v1/payment_intents', ['object' => 'list', 'has_more' => false, 'data' => [
            stripeCaptured(['id' => 'pi_legacy', 'metadata' => ['kind' => 'recurring', 'lesson_id' => (string) $this->lesson->id, 'attempt' => '1']]),
        ]]);

    expect($this->gateway->chargeSavedCard($this->lesson, Money::fils(10000), $method, 'k-legacy')->gatewayRef)->toBe('pi_legacy');
});

it('leaves the attempt pending, never a failed one, when a reused key finds no intent', function () {
    $method = PaymentMethod::factory()->create(['account_user_id' => $this->payer->id, 'gateway' => 'stripe', 'gateway_customer_ref' => 'cus_test_1']);
    $this->http
        ->reply('POST /v1/payment_intents', ['error' => ['type' => 'idempotency_error', 'message' => 'Keys must be used with the same parameters']], 400)
        ->reply('GET /v1/payment_intents', ['object' => 'list', 'has_more' => false, 'data' => []]);

    expect(fn () => $this->gateway->chargeSavedCard($this->lesson, Money::fils(10000), $method, 'k-gone'))->toThrow(RuntimeException::class);
});

it('leaves another account\'s intent alone when settling a stranded one', function () {
    $this->http->reply('GET /v1/payment_intents/pi_theirs', stripeIntent(['id' => 'pi_theirs', 'metadata' => ['kind' => 'checkout', 'account_user_id' => '999999']]));

    expect($this->gateway->settleStrandedIntent('pi_theirs', (string) $this->payer->id)['status'])->toBe('missing')
        ->and($this->http->requestsTo('POST /v1/payment_intents/pi_theirs/cancel'))->toHaveCount(0);
});

it('lets a still-processing saved-card charge propagate and does not cancel it', function () {
    $method = PaymentMethod::factory()->create(['account_user_id' => $this->payer->id, 'gateway' => 'stripe']);
    $this->http->reply('POST /v1/payment_intents', stripeCaptured(['id' => 'pi_test_5', 'status' => 'processing']));

    expect(fn () => $this->gateway->chargeSavedCard($this->lesson, Money::fils(10000), $method, 'k1'))->toThrow(RuntimeException::class)
        ->and($this->http->requestsTo('POST /v1/payment_intents/pi_test_5/cancel'))->toHaveCount(0);
});

it('turns an unknown setup intent into a decline, not a crash', function () {
    $this->http->reply('GET /v1/setup_intents/seti_missing', ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such setupintent']], 404);

    expect(fn () => $this->gateway->saveCard($this->payer, 'seti_missing'))->toThrow(PaymentCaptureException::class, 'setup_not_found');
});

it('refunds a Stripe payment by its intent with the given idempotency key, and tags the refund with it', function () {
    $payment = Payment::factory()->create(['gateway' => 'stripe', 'gateway_ref' => 'pi_test_1', 'amount' => 10000]);
    $this->http->reply('GET /v1/refunds', ['object' => 'list', 'data' => [], 'has_more' => false]);
    $this->http->reply('POST /v1/refunds', ['id' => 're_test_1', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 4000, 'currency' => 'aed', 'payment_intent' => 'pi_test_1']);

    $result = $this->gateway->refund($payment, Money::fils(4000), 'refund-pi_test_1-55');
    [$request] = $this->http->requestsTo('POST /v1/refunds');

    expect($result->gatewayRef)->toBe('re_test_1')
        ->and($request['params'])->toMatchArray(['payment_intent' => 'pi_test_1', 'amount' => 4000])
        ->and($request['params']['metadata'])->toMatchArray(['idempotency_key' => 'refund-pi_test_1-55'])
        ->and($this->http->idempotencyKeyOf($request))->toBe('test:testing:refund-pi_test_1-55');
});

it('returns the refund Stripe already made for a key instead of creating a second one, even after Stripe forgot the key', function () {
    $payment = Payment::factory()->create(['gateway' => 'stripe', 'gateway_ref' => 'pi_test_1', 'amount' => 10000]);
    $this->http->reply('GET /v1/refunds', ['object' => 'list', 'has_more' => false, 'data' => [
        ['id' => 're_other', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 1000, 'currency' => 'aed', 'metadata' => ['idempotency_key' => 'refund-pi_test_1-54']],
        ['id' => 're_made', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 4000, 'currency' => 'aed', 'metadata' => ['idempotency_key' => 'refund-pi_test_1-55']],
    ]]);

    $result = $this->gateway->refund($payment, Money::fils(4000), 'refund-pi_test_1-55');

    expect($result->gatewayRef)->toBe('re_made')
        ->and($this->http->requestsTo('POST /v1/refunds'))->toHaveCount(0);
});

it('turns a refusal to refund into a declined result carrying Stripe\'s code', function () {
    $payment = Payment::factory()->create(['gateway' => 'stripe', 'gateway_ref' => 'pi_test_1', 'amount' => 10000]);
    $this->http->reply('GET /v1/refunds', ['object' => 'list', 'data' => [], 'has_more' => false]);
    $this->http->reply('POST /v1/refunds', ['error' => ['type' => 'invalid_request_error', 'code' => 'charge_already_refunded', 'message' => 'Charge has already been refunded.']], 400);

    expect(fn () => $this->gateway->refund($payment, Money::fils(4000), 'refund-pi_test_1-55'))->toThrow(PaymentCaptureException::class, 'charge_already_refunded');
});

it('lets a rate limit or a lock timeout on a refund through for a retry instead of calling it a refusal', function (int $status, string $code, string $type) {
    $payment = Payment::factory()->create(['gateway' => 'stripe', 'gateway_ref' => 'pi_test_1', 'amount' => 10000]);
    $this->http->reply('GET /v1/refunds', ['object' => 'list', 'data' => [], 'has_more' => false]);
    $this->http->reply('POST /v1/refunds', ['error' => ['type' => $type, 'code' => $code, 'message' => 'Try again.']], $status);

    expect(fn () => $this->gateway->refund($payment, Money::fils(4000), 'refund-pi_test_1-55'))->toThrow(InvalidRequestException::class);
})->with([
    'rate limit' => [429, 'rate_limit', 'invalid_request_error'],
    'lock timeout' => [400, 'lock_timeout', 'invalid_request_error'],
]);

it('refuses a refund that Stripe reports as failed or canceled, also one found by its key', function () {
    $payment = Payment::factory()->create(['gateway' => 'stripe', 'gateway_ref' => 'pi_test_1', 'amount' => 10000]);
    $this->http->reply('GET /v1/refunds', ['object' => 'list', 'has_more' => false, 'data' => [
        ['id' => 're_made', 'object' => 'refund', 'status' => 'canceled', 'amount' => 4000, 'currency' => 'aed', 'metadata' => ['idempotency_key' => 'refund-pi_test_1-55']],
    ]]);

    expect(fn () => $this->gateway->refund($payment, Money::fils(4000), 'refund-pi_test_1-55'))->toThrow(PaymentCaptureException::class, 'refund_canceled');
});

it('refuses to refund a payment that is not a Stripe payment', function () {
    $payment = Payment::factory()->create(['gateway' => 'fake']);

    expect(fn () => $this->gateway->refund($payment, Money::fils(100), 'k'))->toThrow(PaymentCaptureException::class, 'not_a_stripe_payment')
        ->and($this->http->requests)->toHaveCount(0);
});

it('verifies a webhook signature the way Stripe signs it, and nothing else', function () {
    $body = '{"id":"evt_1"}';
    $sign = fn (string $secret, int $t): string => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", $secret);

    expect($this->gateway->verifyWebhook($body, ['stripe-signature' => $sign('whsec_example', time())]))->toBeTrue()
        ->and($this->gateway->verifyWebhook($body, ['stripe-signature' => $sign('whsec_other', time())]))->toBeFalse()
        ->and($this->gateway->verifyWebhook($body, ['stripe-signature' => $sign('whsec_example', time() - 3600)]))->toBeFalse()
        ->and($this->gateway->verifyWebhook($body.' ', ['stripe-signature' => $sign('whsec_example', time())]))->toBeFalse()
        ->and($this->gateway->verifyWebhook($body, []))->toBeFalse()
        ->and((new StripePaymentGateway('sk_test_example', ''))->verifyWebhook($body, ['stripe-signature' => $sign('', time())]))->toBeFalse()
        ->and($this->http->requests)->toHaveCount(0);
});

it('is built by the manager from the row\'s credentials, in the row\'s mode', function () {
    $test = app(PaymentGatewayManager::class)->forRow(PaymentGatewayAccount::factory()->make());

    expect($test)->toBeInstanceOf(StripePaymentGateway::class)
        ->and($test->driver())->toBe('stripe')
        ->and($test->__debugInfo())->toBe(['driver' => 'stripe', 'live' => false])
        ->and(fn () => app(PaymentGatewayManager::class)->forRow(PaymentGatewayAccount::factory()->make(['mode' => PaymentGatewayMode::Live])))->toThrow(NoActivePaymentGateway::class);
});

it('never puts a key or a webhook secret in an exception message or a request body', function () {
    $this->http->reply('GET /v1/payment_intents/pi_test_1', stripeIntent(['status' => 'requires_payment_method']));
    stripeChooses('pi_test_1');

    try {
        $this->gateway->capture($this->lesson, Money::fils(10000), '1');
    } catch (PaymentCaptureException $e) {
        expect($e->getMessage())->not->toContain('sk_test_example')->not->toContain('whsec_example');
    }

    expect(json_encode($this->http->requests))->not->toContain('whsec_example');
});

it('looks up the intent an aged attempt already made before creating another (R259 (6))', function () {
    $method = PaymentMethod::factory()->create(['account_user_id' => $this->payer->id, 'gateway' => 'stripe', 'gateway_customer_ref' => 'cus_test_1']);
    Payment::factory()->create(['lesson_id' => $this->lesson->id, 'attempt_no' => 1, 'gateway_ref' => null, 'status' => PaymentStatus::Pending, 'created_at' => now()->subHours(13)]);
    $this->http->reply('GET /v1/payment_intents', ['object' => 'list', 'has_more' => false, 'data' => [
        stripeCaptured(['id' => 'pi_other', 'metadata' => ['idempotency_key' => 'someone-else']]),
        stripeCaptured(['id' => 'pi_made_earlier', 'metadata' => ['idempotency_key' => 'k-aged']]),
    ]]);

    $result = $this->gateway->chargeSavedCard($this->lesson, Money::fils(10000), $method, 'k-aged');
    [$list] = $this->http->requestsTo('GET /v1/payment_intents');

    expect($result->gatewayRef)->toBe('pi_made_earlier')
        ->and($list['params']['customer'])->toBe('cus_test_1')
        ->and($this->http->requestsTo('POST /v1/payment_intents'))->toHaveCount(0);
});

it('creates the intent as normal when an aged attempt left none, and never lists for a young one', function () {
    $method = PaymentMethod::factory()->create(['account_user_id' => $this->payer->id, 'gateway' => 'stripe', 'gateway_customer_ref' => 'cus_test_1']);
    Payment::factory()->create(['lesson_id' => $this->lesson->id, 'attempt_no' => 1, 'gateway_ref' => null, 'status' => PaymentStatus::Pending, 'created_at' => now()->subHours(13)]);
    $this->http
        ->reply('GET /v1/payment_intents', ['object' => 'list', 'has_more' => false, 'data' => []])
        ->reply('POST /v1/payment_intents', stripeCaptured(['id' => 'pi_new']));

    expect($this->gateway->chargeSavedCard($this->lesson, Money::fils(10000), $method, 'k-aged')->gatewayRef)->toBe('pi_new')
        ->and($this->http->requestsTo('POST /v1/payment_intents')[0]['params']['metadata']['idempotency_key'])->toBe('k-aged');

    $young = Lesson::factory()->create(['booked_by_user_id' => $this->payer->id]);
    Payment::factory()->create(['lesson_id' => $young->id, 'attempt_no' => 1, 'gateway_ref' => null, 'status' => PaymentStatus::Pending]);
    $this->http->reply('POST /v1/payment_intents', stripeCaptured(['id' => 'pi_young']));
    $before = count($this->http->requestsTo('GET /v1/payment_intents'));

    $this->gateway->chargeSavedCard($young, Money::fils(10000), $method, 'k-young');

    expect(count($this->http->requestsTo('GET /v1/payment_intents')))->toBe($before);
});
