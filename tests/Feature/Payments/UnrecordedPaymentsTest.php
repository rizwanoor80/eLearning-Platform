<?php

use App\Actions\Payments\ReconcileUnrecordedPayments;
use App\Actions\Payments\RefundUnverifiedCapture;
use App\Enums\LessonStatus;
use App\Enums\PaymentStatus;
use App\Events\Payments\PaymentNeedsAttention;
use App\Events\Payments\UnverifiedCaptureNeedsRefund;
use App\Exceptions\PaymentGatewayUnavailable;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentGatewayAccount;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Facades\Event;
use Tests\Support\Payments\FakeStripeHttp;

/**
 * R259 (2) and (5): payments a dead or interrupted booking request left behind are settled by
 * `payments:retry-refunds`, and a captured payment the platform never held is refunded to the card.
 */
beforeEach(function () {
    $this->http = FakeStripeHttp::install();
    PaymentGatewayAccount::factory()->active()->create();
});

afterEach(function () {
    FakeStripeHttp::uninstall();
});

function urPending(array $overrides = [], int $ageMinutes = 45, string $intent = 'pi_test_1'): Payment
{
    $lesson = Lesson::factory()->withStatus(LessonStatus::PendingPayment)->create();

    $payment = Payment::factory()->create(array_replace([
        'lesson_id' => $lesson->id,
        'gateway' => 'stripe',
        'gateway_ref' => null,
        'status' => PaymentStatus::Pending,
        'amount' => 10000,
        'raw_response' => ['checkout_intent' => $intent],
    ], $overrides));
    $payment->forceFill(['created_at' => now()->subMinutes($ageMinutes), 'updated_at' => now()->subMinutes($ageMinutes)])->saveQuietly();

    return $payment;
}

function urIntent(string $status, array $overrides = []): array
{
    return array_replace([
        'id' => 'pi_test_1',
        'object' => 'payment_intent',
        'status' => $status,
        'capture_method' => 'manual',
        'amount' => 10000,
        'amount_received' => $status === 'succeeded' ? 10000 : 0,
        'currency' => 'aed',
        'metadata' => ['kind' => 'checkout', 'account_user_id' => (string) Payment::query()->latest('id')->value('payer_user_id')],
    ], $overrides);
}

function urRefundReplies(FakeStripeHttp $http): void
{
    $http->reply('GET /v1/refunds', ['object' => 'list', 'data' => [], 'has_more' => false]);
    $http->reply('POST /v1/refunds', ['id' => 're_test_1', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 10000, 'currency' => 'aed', 'payment_intent' => 'pi_test_1']);
}

it('fails a pending payment whose intent never became a charge, and cancels an open hold', function () {
    $payment = urPending();
    $this->http
        ->reply('GET /v1/payment_intents/pi_test_1', urIntent('requires_capture'))
        ->reply('POST /v1/payment_intents/pi_test_1/cancel', urIntent('canceled'));

    expect(app(ReconcileUnrecordedPayments::class)())->toBe(1);

    $payment = $payment->fresh();
    expect($payment->status)->toBe(PaymentStatus::Failed)
        ->and($payment->failure_reason)->toBe(ReconcileUnrecordedPayments::REASON_RECONCILED)
        ->and($this->http->requestsTo('POST /v1/payment_intents/pi_test_1/cancel'))->toHaveCount(1);
});

it('marks a payment superseded when a retry already carries the captured intent', function () {
    $stranded = urPending(['status' => PaymentStatus::Failed, 'failure_reason' => PaymentGatewayUnavailable::REASON]);
    Payment::factory()->create(['gateway' => 'stripe', 'gateway_ref' => 'pi_test_1', 'attempt_no' => 2, 'lesson_id' => $stranded->lesson_id]);
    $this->http->reply('GET /v1/payment_intents/pi_test_1', urIntent('succeeded', ['metadata' => ['kind' => 'checkout', 'account_user_id' => (string) $stranded->payer_user_id]]));

    app(ReconcileUnrecordedPayments::class)();

    expect($stranded->fresh()->status)->toBe(PaymentStatus::Failed)
        ->and($stranded->fresh()->failure_reason)->toBe(ReconcileUnrecordedPayments::REASON_SUPERSEDED)
        ->and($stranded->fresh()->gateway_ref)->toBeNull();
});

it('records a captured intent nothing else carries, expires the lesson and queues the refund', function () {
    Event::fake([UnverifiedCaptureNeedsRefund::class]);
    $payment = urPending();
    $this->http->reply('GET /v1/payment_intents/pi_test_1', urIntent('succeeded'));

    app(ReconcileUnrecordedPayments::class)();

    $payment = $payment->fresh();
    expect($payment->status)->toBe(PaymentStatus::Captured)
        ->and($payment->gateway_ref)->toBe('pi_test_1')
        ->and($payment->raw_response['amount_received'])->toBe(10000)
        ->and($payment->lesson->fresh()->status)->toBe(LessonStatus::Expired);
    Event::assertDispatched(UnverifiedCaptureNeedsRefund::class, fn ($e) => $e->payment->is($payment));
});

it('picks up a payment the gateway was unreachable for, and leaves it alone while the gateway is still down', function () {
    $payment = urPending(['status' => PaymentStatus::Failed, 'failure_reason' => PaymentGatewayUnavailable::REASON]);

    expect(app(ReconcileUnrecordedPayments::class)())->toBe(0)
        ->and($payment->fresh()->failure_reason)->toBe(PaymentGatewayUnavailable::REASON);

    $this->http->reply('GET /v1/payment_intents/pi_test_1', urIntent('canceled'));

    expect(app(ReconcileUnrecordedPayments::class)())->toBe(1)
        ->and($payment->fresh()->failure_reason)->toBe(ReconcileUnrecordedPayments::REASON_RECONCILED);
});

it('leaves a young payment, one with a young retry and one with no intent alone', function () {
    $young = urPending([], 5);
    $old = urPending([], 45, 'pi_test_2');
    urPending([], 5, 'pi_test_2');
    $plain = urPending(['raw_response' => ['driver' => 'stripe']]);

    expect(app(ReconcileUnrecordedPayments::class)())->toBe(0)
        ->and($young->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($old->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($plain->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($this->http->requests)->toHaveCount(0);
});

it('settles a payment once, so a second sweep is a no-op', function () {
    urPending();
    $this->http
        ->reply('GET /v1/payment_intents/pi_test_1', urIntent('canceled'));

    expect(app(ReconcileUnrecordedPayments::class)())->toBe(1)
        ->and(app(ReconcileUnrecordedPayments::class)())->toBe(0)
        ->and($this->http->requestsTo('GET /v1/payment_intents'))->toHaveCount(1);
});

it('refunds a captured payment the platform never held in the hourly sweep, once', function () {
    Event::fake([PaymentNeedsAttention::class]);
    $payment = urPending(['status' => PaymentStatus::Captured, 'gateway_ref' => 'pi_test_1', 'raw_response' => ['amount_received' => 10000]], 30);
    urRefundReplies($this->http);

    $this->artisan('payments:retry-refunds')->expectsOutputToContain('Made 1 gateway refund(s).')->assertSuccessful();

    $payment = $payment->fresh();
    expect($payment->status)->toBe(PaymentStatus::Refunded)
        ->and($payment->raw_response[RefundUnverifiedCapture::MARKER]['status'])->toBe('succeeded')
        ->and($this->http->idempotencyKeyOf($this->http->requestsTo('POST /v1/refunds')[0]))->toBe('unverified-pi_test_1');
    Event::assertDispatched(PaymentNeedsAttention::class, fn ($e) => $e->reason === PaymentNeedsAttention::UNVERIFIED_CAPTURE_REFUNDED);

    $this->artisan('payments:retry-refunds')->expectsOutputToContain('Made 0 gateway refund(s).')->assertSuccessful();

    expect($this->http->requestsTo('POST /v1/refunds'))->toHaveCount(1);
});

it('leaves a captured payment the ledger holds, a young one and a refused one out of the unverified sweep', function () {
    $held = Payment::factory()->create(['gateway' => 'stripe', 'gateway_ref' => 'pi_held', 'updated_at' => now()->subHour()]);
    app(LedgerService::class)->hold($held->lesson);
    urPending(['status' => PaymentStatus::Captured, 'gateway_ref' => 'pi_young'], 2);
    urPending(['status' => PaymentStatus::Captured, 'gateway_ref' => 'pi_refused', 'raw_response' => [RefundUnverifiedCapture::MARKER => ['status' => 'refused']]], 90);

    $this->artisan('payments:retry-refunds')->expectsOutputToContain('Made 0 gateway refund(s).')->assertSuccessful();

    expect($this->http->requests)->toHaveCount(0);
});

it('alerts admins once, not every hour, when the sweep cannot reach the gateway to refund', function () {
    Event::fake([PaymentNeedsAttention::class]);
    urPending(['status' => PaymentStatus::Captured, 'gateway_ref' => 'pi_test_1', 'raw_response' => ['amount_received' => 10000]], 30);

    $this->artisan('payments:retry-refunds')->assertSuccessful();
    $this->artisan('payments:retry-refunds')->assertSuccessful();

    Event::assertDispatchedTimes(PaymentNeedsAttention::class, 1);
});

it('leaves an intent that is not the payer\'s checkout hold alone', function () {
    $payment = urPending();
    $this->http->reply('GET /v1/payment_intents/pi_test_1', urIntent('requires_capture', ['metadata' => ['kind' => 'checkout', 'account_user_id' => '999999']]));

    app(ReconcileUnrecordedPayments::class)();

    expect($this->http->requestsTo('POST /v1/payment_intents/pi_test_1/cancel'))->toHaveCount(0)
        ->and($payment->fresh()->failure_reason)->toBe(ReconcileUnrecordedPayments::REASON_RECONCILED);
});

it('closes a captured payment already refunded in full without another refund', function () {
    $payment = Payment::factory()->create([
        'lesson_id' => Lesson::factory()->withStatus(LessonStatus::Expired)->create()->id,
        'gateway' => 'stripe',
        'gateway_ref' => 'pi_test_1',
        'status' => PaymentStatus::Captured,
        'amount' => 10000,
        'refunded_amount' => 10000,
        'raw_response' => ['amount_received' => 10000],
    ]);

    expect(app(RefundUnverifiedCapture::class)($payment))->toBeFalse()
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($this->http->requestsTo('POST /v1/refunds'))->toHaveCount(0);
});
