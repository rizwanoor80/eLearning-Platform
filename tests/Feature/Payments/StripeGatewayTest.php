<?php

use App\Enums\PaymentGatewayMode;
use App\Exceptions\PaymentCaptureException;
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
        'status' => 'succeeded',
        'amount' => 10000,
        'amount_received' => 10000,
        'currency' => 'aed',
        'livemode' => false,
        'latest_charge' => ['id' => 'ch_test_1', 'object' => 'charge', 'refunded' => false, 'disputed' => false, 'amount_refunded' => 0],
        'metadata' => ['kind' => 'checkout', 'account_user_id' => (string) test()->payer->id],
    ], $overrides);
}

function stripeChooses(string $intentId): void
{
    app(CheckoutIntent::class)->set($intentId);
}

it('captures by verifying the intent the parent confirmed, and keeps only a whitelisted summary', function () {
    $this->http->reply('GET /v1/payment_intents/pi_test_1', stripeIntent(['customer' => 'cus_secret', 'payment_method' => 'pm_secret']));
    stripeChooses('pi_test_1');

    $result = $this->gateway->capture($this->lesson, Money::fils(10000), (string) $this->lesson->id);

    expect($result->gatewayRef)->toBe('pi_test_1')
        ->and($result->rawResponse)->toBe(['driver' => 'stripe', 'id' => 'pi_test_1', 'status' => 'succeeded', 'amount' => 10000, 'currency' => 'aed', 'latest_charge' => 'ch_test_1'])
        ->and(json_encode($result->rawResponse))->not->toContain('cus_secret')->not->toContain('pm_secret')
        ->and($this->http->requests)->toHaveCount(1);
});

it('captures nothing when the checkout named no intent', function () {
    expect(fn () => $this->gateway->capture($this->lesson, Money::fils(10000), '1'))->toThrow(PaymentCaptureException::class, 'no_payment_intent')
        ->and($this->http->requests)->toHaveCount(0);
});

it('refuses an intent that is not what was booked', function (array $override, string $reason) {
    $this->http->reply('GET /v1/payment_intents/pi_test_1', stripeIntent($override));
    stripeChooses('pi_test_1');

    expect(fn () => $this->gateway->capture($this->lesson, Money::fils(10000), '1'))->toThrow(PaymentCaptureException::class, $reason);
})->with([
    'not completed' => [['status' => 'requires_payment_method'], 'payment_not_completed'],
    'processing' => [['status' => 'processing'], 'payment_not_completed'],
    'too little' => [['amount' => 9999, 'amount_received' => 9999], 'amount_mismatch'],
    'too much' => [['amount' => 10001, 'amount_received' => 10001], 'amount_mismatch'],
    'not all received' => [['amount_received' => 5000], 'amount_mismatch'],
    'wrong currency' => [['currency' => 'usd'], 'amount_mismatch'],
    'live intent on a test row' => [['livemode' => true], 'mode_mismatch'],
    'someone else paid' => [['metadata' => ['kind' => 'checkout', 'account_user_id' => '999999']], 'payer_mismatch'],
    'no payer recorded' => [['metadata' => ['kind' => 'checkout']], 'payer_mismatch'],
    'a saved-card charge, not a checkout' => [['metadata' => ['kind' => 'recurring', 'account_user_id' => '1']], 'not_a_checkout_intent'],
    'no purpose recorded' => [['metadata' => ['account_user_id' => '1']], 'not_a_checkout_intent'],
    'refunded afterwards' => [['latest_charge' => ['id' => 'ch_test_1', 'object' => 'charge', 'refunded' => true, 'disputed' => false, 'amount_refunded' => 10000]], 'payment_not_completed'],
    'partly refunded' => [['latest_charge' => ['id' => 'ch_test_1', 'object' => 'charge', 'refunded' => false, 'disputed' => false, 'amount_refunded' => 100]], 'payment_not_completed'],
    'disputed' => [['latest_charge' => ['id' => 'ch_test_1', 'object' => 'charge', 'refunded' => false, 'disputed' => true, 'amount_refunded' => 0]], 'payment_not_completed'],
    'no charge to check' => [['latest_charge' => null], 'payment_not_completed'],
]);

it('refuses an intent that already pays for another lesson', function () {
    Payment::factory()->create(['gateway' => 'stripe', 'gateway_ref' => 'pi_test_1']);
    $this->http->reply('GET /v1/payment_intents/pi_test_1', stripeIntent());
    stripeChooses('pi_test_1');

    expect(fn () => $this->gateway->capture($this->lesson, Money::fils(10000), '1'))->toThrow(PaymentCaptureException::class, 'payment_already_used');
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

it('lets an ambiguous failure propagate instead of calling it a decline', function () {
    stripeChooses('pi_test_1');

    expect(fn () => $this->gateway->capture($this->lesson, Money::fils(10000), '1'))->toThrow(ApiConnectionException::class);
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
    $this->http->reply('POST /v1/payment_intents', stripeIntent(['id' => 'pi_test_2']));

    $result = $this->gateway->chargeSavedCard($this->lesson, Money::fils(10000), $method, 'lesson:7:attempt:1');
    [$request] = $this->http->requestsTo('POST /v1/payment_intents');

    expect($result->gatewayRef)->toBe('pi_test_2')
        ->and($this->http->idempotencyKeyOf($request))->toBe('lesson:7:attempt:1')
        ->and($request['params'])->toMatchArray(['amount' => 10000, 'currency' => 'aed', 'customer' => 'cus_test_1', 'payment_method' => 'pm_test_1', 'off_session' => true, 'confirm' => true]);
});

it('treats a decline and a card that needs the cardholder as ordinary failed attempts', function () {
    $method = PaymentMethod::factory()->create(['account_user_id' => $this->payer->id, 'gateway' => 'stripe']);

    $this->http->reply('POST /v1/payment_intents', ['error' => ['type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'insufficient_funds', 'message' => 'Declined']], 402);

    expect(fn () => $this->gateway->chargeSavedCard($this->lesson, Money::fils(10000), $method, 'k1'))->toThrow(PaymentCaptureException::class, 'insufficient_funds');

    $this->http->reply('POST /v1/payment_intents', ['error' => ['type' => 'card_error', 'code' => 'authentication_required', 'message' => 'Needs the cardholder', 'payment_intent' => ['id' => 'pi_test_3', 'object' => 'payment_intent', 'status' => 'requires_payment_method']]], 402);

    expect(fn () => $this->gateway->chargeSavedCard($this->lesson, Money::fils(10000), $method, 'k2'))->toThrow(PaymentCaptureException::class, 'authentication_required');

    $this->http
        ->reply('POST /v1/payment_intents', stripeIntent(['id' => 'pi_test_4', 'status' => 'requires_action']))
        ->reply('POST /v1/payment_intents/pi_test_4/cancel', stripeIntent(['id' => 'pi_test_4', 'status' => 'canceled']));

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
    'key reused with other parameters' => [400, ['type' => 'idempotency_error', 'message' => 'Keys must be used with the same parameters']],
]);

it('lets a still-processing saved-card charge propagate and does not cancel it', function () {
    $method = PaymentMethod::factory()->create(['account_user_id' => $this->payer->id, 'gateway' => 'stripe']);
    $this->http->reply('POST /v1/payment_intents', stripeIntent(['id' => 'pi_test_5', 'status' => 'processing']));

    expect(fn () => $this->gateway->chargeSavedCard($this->lesson, Money::fils(10000), $method, 'k1'))->toThrow(RuntimeException::class)
        ->and($this->http->requestsTo('POST /v1/payment_intents/pi_test_5/cancel'))->toHaveCount(0);
});

it('turns an unknown setup intent into a decline, not a crash', function () {
    $this->http->reply('GET /v1/setup_intents/seti_missing', ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such setupintent']], 404);

    expect(fn () => $this->gateway->saveCard($this->payer, 'seti_missing'))->toThrow(PaymentCaptureException::class, 'setup_not_found');
});

it('refunds a Stripe payment by its intent with the given idempotency key', function () {
    $payment = Payment::factory()->create(['gateway' => 'stripe', 'gateway_ref' => 'pi_test_1', 'amount' => 10000]);
    $this->http->reply('POST /v1/refunds', ['id' => 're_test_1', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 4000, 'currency' => 'aed', 'payment_intent' => 'pi_test_1']);

    $result = $this->gateway->refund($payment, Money::fils(4000), 'ledger:55');
    [$request] = $this->http->requestsTo('POST /v1/refunds');

    expect($result->gatewayRef)->toBe('re_test_1')
        ->and($request['params'])->toMatchArray(['payment_intent' => 'pi_test_1', 'amount' => 4000])
        ->and($this->http->idempotencyKeyOf($request))->toBe('ledger:55');
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
