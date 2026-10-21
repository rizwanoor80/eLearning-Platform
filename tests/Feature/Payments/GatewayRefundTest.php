<?php

use App\Actions\Lessons\ResolveDispute;
use App\Actions\Payments\ApplyPaymentWebhook;
use App\Enums\LedgerAccount;
use App\Enums\LessonStatus;
use App\Enums\PaymentRefundKind;
use App\Enums\PaymentRefundStatus;
use App\Enums\PaymentStatus;
use App\Events\Lessons\LessonStatusChanged;
use App\Events\Payments\PaymentNeedsAttention;
use App\Listeners\Payments\RefundParentAtGatewayListener;
use App\Models\Dispute;
use App\Models\LedgerEntry;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentGatewayAccount;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Lessons\LessonSettlement;
use App\Services\Payments\NoActivePaymentGateway;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\RateLimitException;
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

function grRefunds(Payment $payment): Collection
{
    return PaymentRefund::query()->where('payment_id', $payment->id)->orderBy('id')->get();
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
        ->and(grRefunds($payment))->toHaveCount(1);

    $row = grRefunds($payment)->sole();
    expect($row->kind)->toBe(PaymentRefundKind::Ledger)
        ->and($row->ledger_entry_id)->toBe($entry->id)
        ->and($row->attempt)->toBe(1)
        ->and($row->idempotency_key)->toBe("refund-pi_test_1-{$entry->id}")
        ->and($row->status)->toBe(PaymentRefundStatus::Succeeded)
        ->and($row->gateway_refund_id)->toBe('re_test_1')
        ->and($row->gateway_status)->toBe('succeeded')
        ->and($row->amount->toFils())->toBe(3700)
        ->and($row->completed_at)->not->toBeNull();

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
        ->and(grRefunds($payment)->sole()->amount->toFils())->toBe(10000)
        ->and(grRefunds($payment)->sole()->ledger_entry_id)->toBe($entry->id);
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
    // The attempt was written before the gateway was asked, and stays `requested` for the retry.
    expect(grRefunds($payment)->sole()->status)->toBe(PaymentRefundStatus::Requested);

    grStripeWillRefund($this->http, 10000);
    app(RefundParentAtGatewayListener::class)->handle(grEvent($lesson));

    $entry = grRefundEntry($lesson);
    expect(grRefunds($payment)->sole()->status)->toBe(PaymentRefundStatus::Succeeded)
        ->and(grRefunds($payment)->sole()->ledger_entry_id)->toBe($entry->id)
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

    expect(grRefunds($payment)->sole()->status)->toBe(PaymentRefundStatus::Succeeded);
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
    $row = grRefunds($payment)->sole();
    expect($row->status)->toBe(PaymentRefundStatus::Failed)
        ->and($row->failure_reason)->toContain('charge_already_refunded')
        ->and($row->ledger_entry_id)->toBe($entry->id)
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
        ->and(grRefunds($payment))->toHaveCount(1)
        ->and(grRefunds($payment)->sole()->status)->toBe(PaymentRefundStatus::Succeeded)
        ->and(Artisan::call('ledger:verify'))->toBe(0);
});

it('does not tell admins about a charge.refunded webhook for a refund the ledger made, replayed or not', function () {
    ['lesson' => $lesson, 'payment' => $payment, 'dispute' => $dispute, 'admin' => $admin] = grDisputed();
    grStripeWillRefund($this->http, 3700);
    app(ResolveDispute::class)($admin, $dispute, 37, 63, 'Split.');
    $entry = grRefundEntry($lesson);

    Event::fake([PaymentNeedsAttention::class]);
    $charge = ['id' => 'ch_test_1', 'object' => 'charge', 'payment_intent' => 'pi_test_1', 'amount' => 10000, 'amount_refunded' => 3700];

    app(ApplyPaymentWebhook::class)('stripe', 'charge.refunded', $charge);
    app(ApplyPaymentWebhook::class)('stripe', 'charge.refunded', $charge);

    $payment = $payment->fresh();
    expect($payment->refunded_amount->toFils())->toBe(3700)
        ->and($payment->status)->toBe(PaymentStatus::PartiallyRefunded);
    Event::assertNotDispatched(PaymentNeedsAttention::class);

    // More than the ledger returned is still the gateway moving money the platform did not: the queued job lists the
    // gateway's refunds, takes the one the platform has no key for, and records it as an external refund.
    $this->http->reply('GET /v1/refunds', ['object' => 'list', 'has_more' => false, 'data' => [
        ['id' => 're_test_1', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 3700, 'currency' => 'aed', 'created' => 1760000000, 'metadata' => ['idempotency_key' => "refund-pi_test_1-{$entry->id}"]],
        ['id' => 're_dash_1', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 1300, 'currency' => 'aed', 'created' => 1760000100, 'metadata' => []],
    ]]);
    app(ApplyPaymentWebhook::class)('stripe', 'charge.refunded', [...$charge, 'amount_refunded' => 5000]);

    expect($payment->fresh()->refunded_amount->toFils())->toBe(5000);
    Event::assertDispatchedTimes(PaymentNeedsAttention::class, 1);
    $external = PaymentRefund::query()->where('kind', PaymentRefundKind::External->value)->sole();
    expect($external->gateway_refund_id)->toBe('re_dash_1')
        ->and($external->amount->toFils())->toBe(1300)
        ->and($external->status)->toBe(PaymentRefundStatus::Succeeded)
        ->and($external->ledger_entry_id)->toBeNull();
});

it('treats a gateway that is momentarily not in the registry as a retry, not a permanent refusal, and tells admins once a day', function () {
    ['lesson' => $lesson, 'payment' => $payment] = grDisputed(gateway: 'stripe');
    Event::fake([PaymentNeedsAttention::class]);
    PaymentGatewayAccount::query()->delete();
    app(LedgerService::class)->refund($lesson);

    expect(fn () => app(RefundParentAtGatewayListener::class)->handle(grEvent($lesson)))->toThrow(NoActivePaymentGateway::class);
    expect(grRefunds($payment)->sole()->status)->toBe(PaymentRefundStatus::Requested);

    $this->travel(11)->minutes();
    $this->artisan('payments:retry-refunds')->assertSuccessful();
    $this->travel(1)->hours();
    $this->artisan('payments:retry-refunds')->assertSuccessful();
    Event::assertDispatchedTimes(PaymentNeedsAttention::class, 1);

    PaymentGatewayAccount::factory()->active()->create();
    grStripeWillRefund($this->http, 10000);
    $this->artisan('payments:retry-refunds')->expectsOutput('Made 1 gateway refund(s).')->assertSuccessful();
    expect(grRefunds($payment)->sole()->status)->toBe(PaymentRefundStatus::Succeeded);
});

it('keeps a rate-limited refund for the retry instead of recording it as refused', function () {
    ['lesson' => $lesson, 'payment' => $payment] = grDisputed();
    app(LedgerService::class)->refund($lesson);

    $this->http->reply('GET /v1/refunds', ['object' => 'list', 'data' => [], 'has_more' => false]);
    $this->http->reply('POST /v1/refunds', ['error' => ['type' => 'invalid_request_error', 'code' => 'rate_limit', 'message' => 'Too many requests.']], 429);

    expect(fn () => app(RefundParentAtGatewayListener::class)->handle(grEvent($lesson)))->toThrow(RateLimitException::class);
    expect(grRefunds($payment)->sole()->status)->toBe(PaymentRefundStatus::Requested);

    grStripeWillRefund($this->http, 10000);
    app(RefundParentAtGatewayListener::class)->handle(grEvent($lesson));
    expect(grRefunds($payment)->sole()->status)->toBe(PaymentRefundStatus::Succeeded);
});

it('records a refund Stripe reports as failed as a refusal, not as returned money', function () {
    ['lesson' => $lesson, 'payment' => $payment] = grDisputed();
    Event::fake([PaymentNeedsAttention::class]);
    app(LedgerService::class)->refund($lesson);

    $this->http->reply('GET /v1/refunds', ['object' => 'list', 'data' => [], 'has_more' => false]);
    $this->http->reply('POST /v1/refunds', ['id' => 're_test_1', 'object' => 'refund', 'status' => 'failed', 'amount' => 10000, 'currency' => 'aed', 'payment_intent' => 'pi_test_1']);
    app(RefundParentAtGatewayListener::class)->handle(grEvent($lesson));

    expect(grRefunds($payment)->sole()->status)->toBe(PaymentRefundStatus::Failed)
        ->and(grRefunds($payment)->sole()->failure_reason)->toBe('refund_failed');
    Event::assertDispatchedTimes(PaymentNeedsAttention::class, 1);
});

it('refunds a fake-gateway payment through the fake driver and records it the same way', function () {
    PaymentGatewayAccount::factory()->fake()->active()->create();
    ['lesson' => $lesson, 'payment' => $payment, 'dispute' => $dispute, 'admin' => $admin] = grDisputed(gateway: 'fake');

    app(ResolveDispute::class)($admin, $dispute, 37, 63, 'Split.');

    $payment = $payment->fresh();
    expect($payment->refunded_amount->toFils())->toBe(3700)
        ->and($payment->status)->toBe(PaymentStatus::PartiallyRefunded)
        ->and(grRefunds($payment))->toHaveCount(1)
        ->and(grRefunds($payment)->sole()->status)->toBe(PaymentRefundStatus::Succeeded)
        ->and($this->http->requests)->toBe([]);
});
