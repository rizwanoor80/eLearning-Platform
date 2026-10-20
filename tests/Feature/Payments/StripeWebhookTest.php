<?php

use App\Enums\PaymentStatus;
use App\Events\Payments\PaymentNeedsAttention;
use App\Listeners\Payments\SendAdminPaymentAttentionMail;
use App\Mail\Admin\AdminPaymentAttentionMail;
use App\Models\Payment;
use App\Models\PaymentGatewayAccount;
use App\Models\PaymentWebhookEvent;
use App\Models\User;
use App\Support\Money;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

/**
 * 23b (R247): `POST webhooks/payments/stripe`. Signatures are made the way Stripe makes them, with the
 * test secret below; nothing calls Stripe.
 */
const STRIPE_TEST_WEBHOOK_SECRET = 'whsec_test_webhook_example';

beforeEach(function () {
    PaymentGatewayAccount::factory()->create(['credentials' => ['publishable_key' => 'pk_test_example', 'secret_key' => 'sk_test_example', 'webhook_secret' => STRIPE_TEST_WEBHOOK_SECRET]]);
    $this->payment = Payment::factory()->create(['gateway' => 'stripe', 'gateway_ref' => 'pi_test_1', 'amount' => 10000]);
    holdFor($this->payment);
});

/** The escrow hold a booking writes through `LedgerService`; a test may not use the service for a webhook that must not touch it. */
function holdFor(Payment $payment): void
{
    DB::table('ledger_entries')->insert(['lesson_id' => $payment->lesson_id, 'account' => 'escrow', 'type' => 'hold', 'amount' => 10000, 'created_at' => now()]);
}

function stripeEvent(string $type, array $object, string $id = 'evt_test_1', array $overrides = []): string
{
    return json_encode(array_replace([
        'id' => $id,
        'object' => 'event',
        'type' => $type,
        'created' => time(),
        'livemode' => false,
        'data' => ['object' => $object],
    ], $overrides));
}

function stripeSignature(string $body, ?int $timestamp = null, string $secret = STRIPE_TEST_WEBHOOK_SECRET): array
{
    $timestamp ??= time();

    return ['Stripe-Signature' => "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret)];
}

function postStripe(string $body, ?array $headers = null, string $code = 'stripe')
{
    $server = [];

    foreach ($headers ?? stripeSignature($body) as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return test()->call('POST', "/webhooks/payments/{$code}", [], [], [], $server + ['CONTENT_TYPE' => 'application/json'], $body);
}

function refundedCharge(int $amountRefunded, string $intent = 'pi_test_1'): array
{
    return ['id' => 'ch_test_1', 'object' => 'charge', 'payment_intent' => $intent, 'amount' => 10000, 'amount_refunded' => $amountRefunded, 'currency' => 'aed'];
}

it('stores a correctly signed event once and answers a replay with a no-op', function () {
    Event::fake([PaymentNeedsAttention::class]);
    $body = stripeEvent('charge.refunded', refundedCharge(2500));

    postStripe($body)->assertOk()->assertJson(['status' => 'received']);
    postStripe($body)->assertOk()->assertJson(['status' => 'duplicate']);

    expect(PaymentWebhookEvent::query()->count())->toBe(1)
        ->and(PaymentWebhookEvent::query()->sole()->object_ref)->toBe('pi_test_1');
    Event::assertDispatchedTimes(PaymentNeedsAttention::class, 1);
});

it('rejects a wrong, missing, stale or tampered signature without storing anything', function () {
    $body = stripeEvent('charge.refunded', refundedCharge(2500));

    postStripe($body, stripeSignature($body, null, 'whsec_other'))->assertStatus(401);
    postStripe($body, [])->assertStatus(401);
    postStripe($body, stripeSignature($body, time() - 3600))->assertStatus(401);
    postStripe($body.' ', stripeSignature($body))->assertStatus(401);

    expect(PaymentWebhookEvent::query()->count())->toBe(0)
        ->and($this->payment->fresh()->refunded_amount)->toBeNull();
});

it('is a 404 for an unknown code, the fake gateway and a row with no webhook secret', function () {
    $body = stripeEvent('charge.refunded', refundedCharge(2500));

    postStripe($body, null, 'nope')->assertStatus(404);
    postStripe($body, null, 'fake')->assertStatus(404);

    PaymentGatewayAccount::query()->where('code', 'stripe')->update(['credentials' => null]);

    postStripe($body)->assertStatus(404);
    expect(PaymentWebhookEvent::query()->count())->toBe(0);
});

it('is a 404, not a 500, for a row with a webhook secret but no secret key', function () {
    $row = PaymentGatewayAccount::query()->where('code', 'stripe')->firstOrFail();
    $row->credentials = ['webhook_secret' => STRIPE_TEST_WEBHOOK_SECRET];
    $row->save();

    postStripe(stripeEvent('charge.refunded', refundedCharge(2500)))->assertStatus(404);
    expect(PaymentWebhookEvent::query()->count())->toBe(0);
});

it('is a 404 for a live-mode row while the owner-only flag is off', function () {
    PaymentGatewayAccount::query()->where('code', 'stripe')->update(['mode' => 'live']);

    postStripe(stripeEvent('charge.refunded', refundedCharge(2500)))->assertStatus(404);
});

it('refuses an oversized body', function () {
    $body = stripeEvent('charge.refunded', refundedCharge(2500), 'evt_big', ['padding' => str_repeat('x', 70000)]);

    postStripe($body)->assertStatus(413);
    expect(PaymentWebhookEvent::query()->count())->toBe(0);
});

it('acknowledges and ignores an event type it does not act on, and an event of the other mode', function () {
    postStripe(stripeEvent('customer.created', ['id' => 'cus_1']))->assertOk()->assertJson(['status' => 'ignored']);
    postStripe(stripeEvent('charge.refunded', refundedCharge(2500), 'evt_live', ['livemode' => true]))->assertOk()->assertJson(['status' => 'ignored']);
    postStripe('{"not":"an event"}')->assertOk()->assertJson(['status' => 'ignored']);

    expect(PaymentWebhookEvent::query()->count())->toBe(0)
        ->and($this->payment->fresh()->refunded_amount)->toBeNull();
});

it('records a succeeded or failed intent and changes nothing', function (string $type) {
    Event::fake([PaymentNeedsAttention::class]);

    postStripe(stripeEvent($type, ['id' => 'pi_test_1', 'object' => 'payment_intent']))->assertJson(['status' => 'received']);

    $payment = $this->payment->fresh();

    expect(PaymentWebhookEvent::query()->sole()->type)->toBe($type)
        ->and($payment->status)->toBe(PaymentStatus::Captured)
        ->and($payment->refunded_amount)->toBeNull();
    Event::assertNotDispatched(PaymentNeedsAttention::class);
})->with(['payment_intent.succeeded', 'payment_intent.payment_failed']);

it('records a partial refund as an absolute amount and tells the admins', function () {
    Event::fake([PaymentNeedsAttention::class]);

    postStripe(stripeEvent('charge.refunded', refundedCharge(2500)))->assertJson(['status' => 'received']);

    $payment = $this->payment->fresh();

    expect($payment->refunded_amount->toFils())->toBe(2500)
        ->and($payment->status)->toBe(PaymentStatus::PartiallyRefunded);
    Event::assertDispatched(PaymentNeedsAttention::class, fn ($e) => $e->reason === PaymentNeedsAttention::REFUNDED_AT_GATEWAY && $e->payment->is($payment));
});

it('records a refund on a payment with no hold but leaves it captured, so ledger:verify still flags it', function () {
    $unheld = Payment::factory()->create(['gateway' => 'stripe', 'gateway_ref' => 'pi_test_unheld', 'amount' => 10000, 'status' => PaymentStatus::Captured]);

    postStripe(stripeEvent('charge.refunded', refundedCharge(10000, 'pi_test_unheld')))->assertJson(['status' => 'received']);

    expect($unheld->fresh()->status)->toBe(PaymentStatus::Captured)
        ->and($unheld->fresh()->refunded_amount->toFils())->toBe(10000);
});

it('serialises its payment for the queue', function () {
    $event = new PaymentNeedsAttention($this->payment, PaymentNeedsAttention::DISPUTED_AT_GATEWAY);

    expect(unserialize(serialize($event))->payment->is($this->payment))->toBeTrue();
});

it('records a full refund as refunded', function () {
    postStripe(stripeEvent('charge.refunded', refundedCharge(10000)))->assertJson(['status' => 'received']);

    expect($this->payment->fresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($this->payment->fresh()->refunded_amount->toFils())->toBe(10000);
});

it('never lowers a refunded amount, so a late older event cannot undo a newer one', function () {
    Event::fake([PaymentNeedsAttention::class]);

    postStripe(stripeEvent('charge.refunded', refundedCharge(6000), 'evt_new'))->assertJson(['status' => 'received']);
    postStripe(stripeEvent('charge.refunded', refundedCharge(2500), 'evt_old'))->assertJson(['status' => 'received']);

    expect($this->payment->fresh()->refunded_amount->toFils())->toBe(6000)
        ->and(PaymentWebhookEvent::query()->count())->toBe(2);
    Event::assertDispatchedTimes(PaymentNeedsAttention::class, 1);
});

it('says nothing about a refund the platform already recorded', function () {
    Event::fake([PaymentNeedsAttention::class]);
    $this->payment->forceFill(['refunded_amount' => Money::fils(4000), 'status' => PaymentStatus::PartiallyRefunded])->save();

    postStripe(stripeEvent('charge.refunded', refundedCharge(4000)))->assertJson(['status' => 'received']);

    Event::assertNotDispatched(PaymentNeedsAttention::class);
});

it('caps a refund at the payment amount', function () {
    postStripe(stripeEvent('charge.refunded', refundedCharge(99999)))->assertJson(['status' => 'received']);

    expect($this->payment->fresh()->refunded_amount->toFils())->toBe(10000);
});

it('stores an event for an unknown payment and changes nothing', function () {
    Event::fake([PaymentNeedsAttention::class]);

    postStripe(stripeEvent('charge.refunded', refundedCharge(2500, 'pi_unknown')))->assertJson(['status' => 'received']);

    expect(PaymentWebhookEvent::query()->count())->toBe(1)
        ->and($this->payment->fresh()->refunded_amount)->toBeNull();
    Event::assertNotDispatched(PaymentNeedsAttention::class);
});

it('alerts admins of a dispute and changes no payment, lesson or ledger state', function () {
    Event::fake([PaymentNeedsAttention::class]);
    $lessonStatus = $this->payment->lesson->status;
    $ledger = DB::table('ledger_entries')->count();

    postStripe(stripeEvent('charge.dispute.created', ['id' => 'dp_test_1', 'object' => 'dispute', 'payment_intent' => 'pi_test_1', 'amount' => 10000]))
        ->assertJson(['status' => 'received']);

    $payment = $this->payment->fresh();

    expect($payment->status)->toBe(PaymentStatus::Captured)
        ->and($payment->refunded_amount)->toBeNull()
        ->and($payment->lesson->fresh()->status)->toBe($lessonStatus)
        ->and(DB::table('ledger_entries')->count())->toBe($ledger);
    Event::assertDispatched(PaymentNeedsAttention::class, fn ($e) => $e->reason === PaymentNeedsAttention::DISPUTED_AT_GATEWAY);
});

it('emails the admins in a queued mail that names ids and an amount and no person or card', function () {
    Mail::fake();
    User::factory()->admin()->create(['email' => 'admin@example.test']);

    postStripe(stripeEvent('charge.refunded', refundedCharge(2500)))->assertOk();

    (new SendAdminPaymentAttentionMail)->handle(new PaymentNeedsAttention($this->payment->fresh(), PaymentNeedsAttention::REFUNDED_AT_GATEWAY));

    Mail::assertQueued(AdminPaymentAttentionMail::class, function (AdminPaymentAttentionMail $mail) {
        $html = $mail->render();

        return $mail->hasTo('admin@example.test')
            && str_contains($html, 'payment '.$this->payment->id)
            && ! str_contains($html, $this->payment->payer->email)
            && ! str_contains($html, $this->payment->payer->name);
    });
});

it('rolls the event back when applying it fails, so the gateway\'s retry is not answered duplicate', function () {
    Event::listen(PaymentNeedsAttention::class, fn () => throw new RuntimeException('queue down'));
    $body = stripeEvent('charge.refunded', refundedCharge(2500));

    $this->withoutExceptionHandling();

    expect(fn () => postStripe($body))->toThrow(RuntimeException::class)
        ->and(PaymentWebhookEvent::query()->count())->toBe(0);
});

it('keeps the body, headers and secrets out of the stored event and the audit trail', function () {
    postStripe(stripeEvent('charge.refunded', refundedCharge(2500)))->assertOk();

    $stored = json_encode(PaymentWebhookEvent::query()->get()->toArray());

    expect($stored)->not->toContain(STRIPE_TEST_WEBHOOK_SECRET)->not->toContain('whsec_')->not->toContain('v1=')->not->toContain('amount_refunded');
});

it('has its own throttle counter, separate from the video webhooks', function () {
    $limiter = app(RateLimiter::class);

    expect(Route::getRoutes()->getByName('webhooks.payments')->gatherMiddleware())->toContain('throttle:payment-webhooks')
        ->and($limiter->limiter('payment-webhooks'))->not->toBeNull();
});
