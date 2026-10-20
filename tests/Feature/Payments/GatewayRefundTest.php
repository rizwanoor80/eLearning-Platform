<?php

use App\Actions\Lessons\ResolveDispute;
use App\Actions\Payments\ApplyPaymentWebhook;
use App\Enums\LedgerAccount;
use App\Enums\LessonStatus;
use App\Enums\PaymentStatus;
use App\Events\Lessons\LessonStatusChanged;
use App\Events\Payments\PaymentNeedsAttention;
use App\Listeners\Payments\RefundParentAtGatewayListener;
use App\Models\Dispute;
use App\Models\LedgerEntry;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentGatewayAccount;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Lessons\LessonSettlement;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Stripe\Exception\ApiErrorException;
use Tests\Support\Payments\FakeStripeHttp;

/**
 * 23d (R256): money the ledger returns to a parent is returned to the parent's card. The ledger is the
 * source of truth and is never touched by the gateway side; Stripe is the faked HTTP client throughout.
 */
beforeEach(function () {
    $this->http = FakeStripeHttp::install();
    PaymentGatewayAccount::factory()->active()->create();
});

afterEach(function () {
    FakeStripeHttp::uninstall();
});

/**
 * A disputed lesson (price 10000) whose escrow is held and whose payment is a captured Stripe intent.
 *
 * @return array{lesson: Lesson, payment: Payment, dispute: Dispute, admin: User}
 */
function grDisputed(string $gateway = 'stripe'): array
{
    $lesson = Lesson::factory()->withStatus(LessonStatus::Disputed)->create(['price' => 10000, 'commission_pct' => 25, 'commission_amount' => 2500, 'tutor_amount' => 7500]);
    app(LedgerService::class)->hold($lesson);
    $payment = Payment::factory()->create(['lesson_id' => $lesson->id, 'gateway' => $gateway, 'gateway_ref' => 'pi_test_1', 'amount' => 10000]);

    return ['lesson' => $lesson->fresh(), 'payment' => $payment, 'dispute' => Dispute::factory()->for($lesson)->create(), 'admin' => User::factory()->admin()->create()];
}

function grStripeWillRefund(FakeStripeHttp $http, int $amount, string $id = 're_test_1'): void
{
    $http->reply('GET /v1/refunds', ['object' => 'list', 'data' => [], 'has_more' => false]);
    $http->reply('POST /v1/refunds', ['id' => $id, 'object' => 'refund', 'status' => 'succeeded', 'amount' => $amount, 'currency' => 'aed', 'payment_intent' => 'pi_test_1']);
}

function grRefundEntry(Lesson $lesson): LedgerEntry
{
    return LedgerEntry::query()->where('lesson_id', $lesson->id)->where('account', LedgerAccount::Refund->value)->sole();
}

function grEvent(Lesson $lesson): LessonStatusChanged
{
    return new LessonStatusChanged($lesson->fresh(), LessonStatus::Disputed, LessonStatus::Settled);
}

it('refunds the parent share of a dispute to the card for that amount, keyed on the ledger entry', function () {
    ['lesson' => $lesson, 'payment' => $payment, 'dispute' => $dispute, 'admin' => $admin] = grDisputed();
    grStripeWillRefund($this->http, 3700);

    app(ResolveDispute::class)($admin, $dispute, 37, 63, 'Split.');

    $entry = grRefundEntry($lesson);
    [$request] = $this->http->requestsTo('POST /v1/refunds');
    $payment = $payment->fresh();

    expect($request['params'])->toMatchArray(['payment_intent' => 'pi_test_1', 'amount' => 3700])
        ->and($this->http->idempotencyKeyOf($request))->toBe("refund-pi_test_1-{$entry->id}")
        ->and($payment->status)->toBe(PaymentStatus::PartiallyRefunded)
        ->and($payment->refunded_amount->toFils())->toBe(3700)
        ->and($payment->raw_response['refunds'][$entry->id])->toMatchArray(['id' => 're_test_1', 'amount_fils' => 3700]);

    expect(app(LedgerService::class)->sum($lesson))->toBe(0)
        ->and(Artisan::call('ledger:verify'))->toBe(0);
});

it('refunds a whole dispute refund and marks the payment refunded', function () {
    ['lesson' => $lesson, 'payment' => $payment, 'dispute' => $dispute, 'admin' => $admin] = grDisputed();
    grStripeWillRefund($this->http, 10000);

    app(ResolveDispute::class)($admin, $dispute, 100, 0, 'Full refund.');

    $payment = $payment->fresh();

    expect($this->http->requestsTo('POST /v1/refunds')[0]['params']['amount'])->toBe(10000)
        ->and($payment->status)->toBe(PaymentStatus::Refunded)
        ->and($payment->refunded_amount->toFils())->toBe(10000)
        ->and(Artisan::call('ledger:verify'))->toBe(0);
});

it('refunds the full price when the ledger refunds a lesson to the parent', function () {
    ['lesson' => $lesson, 'payment' => $payment] = grDisputed();
    grStripeWillRefund($this->http, 10000);

    // The path cancellation takes: the ledger refund, then the payment row marked eagerly.
    app(LessonSettlement::class)->refundParent($lesson, null);
    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded);

    app(RefundParentAtGatewayListener::class)->handle(grEvent($lesson));

    $entry = grRefundEntry($lesson);
    expect($this->http->requestsTo('POST /v1/refunds'))->toHaveCount(1)
        ->and($payment->fresh()->raw_response['refunds'][$entry->id]['amount_fils'])->toBe(10000);
});

it('refunds nothing for a lesson with no refund in the ledger, or no gateway payment', function () {
    ['lesson' => $lesson] = grDisputed();
    app(RefundParentAtGatewayListener::class)->handle(grEvent($lesson));

    $bare = Lesson::factory()->withStatus(LessonStatus::CancelledByParent)->create();
    app(LedgerService::class)->hold($bare);
    app(LedgerService::class)->refund($bare);
    app(RefundParentAtGatewayListener::class)->handle(grEvent($bare));

    expect($this->http->requests)->toBe([]);
});

it('does not refund the same ledger entry twice, however often the job runs', function () {
    ['lesson' => $lesson, 'dispute' => $dispute, 'admin' => $admin] = grDisputed();
    grStripeWillRefund($this->http, 3700);

    app(ResolveDispute::class)($admin, $dispute, 37, 63, 'Split.');
    app(RefundParentAtGatewayListener::class)->handle(grEvent($lesson));
    app(RefundParentAtGatewayListener::class)->handle(grEvent($lesson));

    expect($this->http->requestsTo('POST /v1/refunds'))->toHaveCount(1);
});

it('keeps a failed gateway refund for the retry, and makes it on the second attempt without touching the ledger', function () {
    ['lesson' => $lesson, 'payment' => $payment] = grDisputed();
    Event::fake([PaymentNeedsAttention::class]);
    app(LedgerService::class)->refund($lesson);
    $before = LedgerEntry::query()->where('lesson_id', $lesson->id)->count();

    $this->http->reply('GET /v1/refunds', ['error' => ['type' => 'api_error', 'message' => 'Stripe is down']], 500);
    expect(fn () => app(RefundParentAtGatewayListener::class)->handle(grEvent($lesson)))->toThrow(ApiErrorException::class);
    expect($payment->fresh()->raw_response)->not->toHaveKey('refunds')
        ->and($payment->fresh()->raw_response)->not->toHaveKey('refund_failures');

    grStripeWillRefund($this->http, 10000);
    app(RefundParentAtGatewayListener::class)->handle(grEvent($lesson));

    $entry = grRefundEntry($lesson);
    expect($payment->fresh()->raw_response['refunds'])->toHaveKey((string) $entry->id)
        ->and(LedgerEntry::query()->where('lesson_id', $lesson->id)->count())->toBe($before)
        ->and(app(LedgerService::class)->sum($lesson))->toBe(0);
    Event::assertNotDispatched(PaymentNeedsAttention::class);
});

it('tells admins when the queue gives up, and the sweep makes the refund later', function () {
    ['lesson' => $lesson, 'payment' => $payment] = grDisputed();
    Event::fake([PaymentNeedsAttention::class]);
    app(LedgerService::class)->refund($lesson);

    app(RefundParentAtGatewayListener::class)->failed(grEvent($lesson), new RuntimeException('exhausted'));
    Event::assertDispatched(PaymentNeedsAttention::class, fn (PaymentNeedsAttention $e) => $e->reason === PaymentNeedsAttention::REFUND_FAILED && $e->payment->is($payment));

    grStripeWillRefund($this->http, 10000);
    $this->travel(11)->minutes();
    $this->artisan('payments:retry-refunds')->expectsOutput('Made 1 gateway refund(s).')->assertSuccessful();

    expect($payment->fresh()->raw_response['refunds'])->toHaveKey((string) grRefundEntry($lesson)->id);
});

it('records a refusal, tells admins once, and never asks again', function () {
    ['lesson' => $lesson, 'payment' => $payment] = grDisputed();
    Event::fake([PaymentNeedsAttention::class]);
    app(LedgerService::class)->refund($lesson);

    $this->http->reply('GET /v1/refunds', ['object' => 'list', 'data' => [], 'has_more' => false]);
    $this->http->reply('POST /v1/refunds', ['error' => ['type' => 'invalid_request_error', 'code' => 'charge_already_refunded', 'message' => 'Charge has already been refunded.']], 400);

    app(RefundParentAtGatewayListener::class)->handle(grEvent($lesson));
    $requests = count($this->http->requests);
    app(RefundParentAtGatewayListener::class)->handle(grEvent($lesson));
    $this->travel(11)->minutes();
    $this->artisan('payments:retry-refunds')->expectsOutput('Made 0 gateway refund(s).')->assertSuccessful();

    $entry = grRefundEntry($lesson);
    expect($payment->fresh()->raw_response['refund_failures'][$entry->id]['code'])->toBe('charge_already_refunded')
        ->and($payment->fresh()->raw_response)->not->toHaveKey('refunds')
        ->and($this->http->requests)->toHaveCount($requests)
        ->and(app(LedgerService::class)->balance($lesson, LedgerAccount::Refund))->toBe(10000);
    Event::assertDispatchedTimes(PaymentNeedsAttention::class, 1);

    $this->artisan('payments:retry-refunds', ['--list' => true])->expectsOutputToContain('refused: charge_already_refunded')->assertSuccessful();
});

it('lets the sweep find a refund the queue never ran, once, and leaves a young one to the queue', function () {
    ['lesson' => $lesson, 'payment' => $payment] = grDisputed();
    app(LedgerService::class)->refund($lesson);
    grStripeWillRefund($this->http, 10000);

    $this->artisan('payments:retry-refunds')->expectsOutput('Made 0 gateway refund(s).')->assertSuccessful();
    expect($this->http->requests)->toBe([]);

    $this->travel(11)->minutes();
    $this->artisan('payments:retry-refunds', ['--list' => true])->expectsOutputToContain('not yet confirmed')->assertSuccessful();
    $this->artisan('payments:retry-refunds')->expectsOutput('Made 1 gateway refund(s).')->assertSuccessful();
    $this->artisan('payments:retry-refunds')->expectsOutput('Made 0 gateway refund(s).')->assertSuccessful();

    expect($this->http->requestsTo('POST /v1/refunds'))->toHaveCount(1)
        ->and($payment->fresh()->raw_response['refunds'])->toHaveCount(1)
        ->and(Artisan::call('ledger:verify'))->toBe(0);
});

it('does not tell admins about a charge.refunded webhook for a refund the ledger made, replayed or not', function () {
    ['lesson' => $lesson, 'payment' => $payment, 'dispute' => $dispute, 'admin' => $admin] = grDisputed();
    grStripeWillRefund($this->http, 3700);
    app(ResolveDispute::class)($admin, $dispute, 37, 63, 'Split.');

    Event::fake([PaymentNeedsAttention::class]);
    $charge = ['id' => 'ch_test_1', 'object' => 'charge', 'payment_intent' => 'pi_test_1', 'amount' => 10000, 'amount_refunded' => 3700];

    app(ApplyPaymentWebhook::class)('stripe', 'charge.refunded', $charge);
    app(ApplyPaymentWebhook::class)('stripe', 'charge.refunded', $charge);

    $payment = $payment->fresh();
    expect($payment->refunded_amount->toFils())->toBe(3700)
        ->and($payment->status)->toBe(PaymentStatus::PartiallyRefunded);
    Event::assertNotDispatched(PaymentNeedsAttention::class);

    // More than the ledger returned is still the gateway moving money the platform did not.
    app(ApplyPaymentWebhook::class)('stripe', 'charge.refunded', [...$charge, 'amount_refunded' => 5000]);

    expect($payment->fresh()->refunded_amount->toFils())->toBe(5000);
    Event::assertDispatchedTimes(PaymentNeedsAttention::class, 1);
});

it('answers a gateway that is no longer in the registry as a refusal for admins, not a crash', function () {
    ['lesson' => $lesson, 'payment' => $payment] = grDisputed(gateway: 'stripe');
    Event::fake([PaymentNeedsAttention::class]);
    PaymentGatewayAccount::query()->delete();
    app(LedgerService::class)->refund($lesson);

    app(RefundParentAtGatewayListener::class)->handle(grEvent($lesson));

    expect($payment->fresh()->raw_response['refund_failures'][grRefundEntry($lesson)->id]['code'])->toBe('gateway_unavailable');
    Event::assertDispatchedTimes(PaymentNeedsAttention::class, 1);
});

it('refunds a fake-gateway payment through the fake driver and records it the same way', function () {
    PaymentGatewayAccount::factory()->fake()->active()->create();
    ['lesson' => $lesson, 'payment' => $payment, 'dispute' => $dispute, 'admin' => $admin] = grDisputed(gateway: 'fake');

    app(ResolveDispute::class)($admin, $dispute, 37, 63, 'Split.');

    $payment = $payment->fresh();
    expect($payment->refunded_amount->toFils())->toBe(3700)
        ->and($payment->status)->toBe(PaymentStatus::PartiallyRefunded)
        ->and($payment->raw_response['refunds'])->toHaveCount(1)
        ->and($this->http->requests)->toBe([]);
});
