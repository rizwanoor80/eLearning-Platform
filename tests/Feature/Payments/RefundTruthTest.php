<?php

use App\Actions\Payments\ApplyPaymentWebhook;
use App\Actions\Payments\MakeGatewayRefund;
use App\Actions\Payments\RecordExternalRefunds;
use App\Actions\Payments\SettleGatewayRefund;
use App\Enums\LedgerAccount;
use App\Enums\PaymentRefundKind;
use App\Enums\PaymentRefundStatus;
use App\Enums\PaymentStatus;
use App\Events\Payments\PaymentNeedsAttention;
use App\Jobs\Payments\ReconcileGatewayRefunds;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentGatewayAccount;
use App\Models\PaymentRefund;
use App\Services\Ledger\LedgerService;
use App\Services\Ops\OpsStatusReport;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\Money;
use App\Support\Payments\UnheldCaptures;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\Payments\FakeStripeHttp;

/**
 * 25b (R267): a Stripe refund reported `pending` is `requested`, not `succeeded`; it advances only on the gateway's
 * word (the call, `refund.updated`, the reconcile job or the sweep's re-ask); the refund lookups page through every
 * refund; `ledger:verify` reports a capture the platform does not hold exactly. Stripe is the faked HTTP client.
 */
beforeEach(function () {
    $this->http = FakeStripeHttp::install();
    PaymentGatewayAccount::factory()->active()->create();
});

afterEach(function () {
    FakeStripeHttp::uninstall();
});

/** A captured Stripe payment of 10000 fils whose lesson the platform holds (a Hold entry of the same amount). */
function rtHeldPayment(array $overrides = []): Payment
{
    $lesson = Lesson::factory()->create(['price' => 10000, 'commission_pct' => 25, 'commission_amount' => 2500, 'tutor_amount' => 7500]);
    app(LedgerService::class)->hold($lesson);

    return Payment::factory()->create(array_replace([
        'lesson_id' => $lesson->id,
        'gateway' => 'stripe',
        'gateway_ref' => 'pi_test_1',
        'amount' => 10000,
        'status' => PaymentStatus::Captured,
        'raw_response' => ['amount_received' => 10000],
    ], $overrides));
}

/** A captured Stripe payment the platform never held: no ledger entry at all. */
function rtUnheldPayment(array $overrides = []): Payment
{
    return Payment::factory()->create(array_replace([
        'gateway' => 'stripe',
        'gateway_ref' => 'pi_test_1',
        'amount' => 10000,
        'status' => PaymentStatus::Captured,
        'raw_response' => ['amount_received' => 10000],
    ], $overrides));
}

function rtRequested(Payment $payment, array $overrides = []): PaymentRefund
{
    return PaymentRefund::factory()->requested()->create(array_replace([
        'payment_id' => $payment->id,
        'idempotency_key' => 'unverified-'.$payment->gateway_ref,
        'amount' => 4000,
    ], $overrides));
}

/** A requested refund the ledger owes the parent: a ledger-kind row pointing at a real Refund entry. */
function rtLedgerRequested(Payment $payment, array $overrides = []): PaymentRefund
{
    $entry = DB::table('ledger_entries')->insertGetId(['lesson_id' => $payment->lesson_id, 'account' => LedgerAccount::Refund->value, 'type' => 'refund', 'amount' => 4000, 'created_at' => now()]);

    return rtRequested($payment, array_replace(['kind' => PaymentRefundKind::Ledger, 'ledger_entry_id' => $entry], $overrides));
}

function rtRefundObject(string $status, string $id = 're_test_1', int $amount = 4000, ?string $key = null, string $intent = 'pi_test_1'): array
{
    return [
        'id' => $id,
        'object' => 'refund',
        'status' => $status,
        'amount' => $amount,
        'currency' => 'aed',
        'payment_intent' => $intent,
        'created' => 1790000000,
        'metadata' => $key === null ? [] : ['idempotency_key' => $key],
    ];
}

function rtList(array $data, bool $more = false): array
{
    return ['object' => 'list', 'data' => $data, 'has_more' => $more];
}

// ---- SettleGatewayRefund ----

it('keeps a refund Stripe reports pending as requested, recording only its id and status', function () {
    Event::fake([PaymentNeedsAttention::class]);
    $payment = rtUnheldPayment();
    $row = rtRequested($payment);

    $status = app(SettleGatewayRefund::class)($row, 'pending', 're_test_1');

    expect($status)->toBe(PaymentRefundStatus::Requested)
        ->and($row->fresh()->status)->toBe(PaymentRefundStatus::Requested)
        ->and($row->fresh()->gateway_refund_id)->toBe('re_test_1')
        ->and($row->fresh()->gateway_status)->toBe('pending')
        ->and($row->fresh()->completed_at)->toBeNull()
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Captured)
        ->and($payment->fresh()->refunded_amount)->toBeNull();
    Event::assertNotDispatched(PaymentNeedsAttention::class);
});

it('advances a pending refund to succeeded and the payment with it, once', function () {
    Event::fake([PaymentNeedsAttention::class]);
    $payment = rtHeldPayment();
    $row = rtLedgerRequested($payment, ['idempotency_key' => 'refund-pi_test_1-1']);
    $settle = app(SettleGatewayRefund::class);

    $settle($row, 'pending', 're_test_1');
    $settle($row, 'succeeded', 're_test_1');
    $settle($row, 'succeeded', 're_test_1');

    expect($row->fresh()->status)->toBe(PaymentRefundStatus::Succeeded)
        ->and($row->fresh()->completed_at)->not->toBeNull()
        ->and($payment->fresh()->status)->toBe(PaymentStatus::PartiallyRefunded)
        ->and($payment->fresh()->refunded_amount->toFils())->toBe(4000);
    Event::assertNotDispatched(PaymentNeedsAttention::class);
});

it('fails a pending refund the gateway then fails or cancels, tells admins, and never touches the ledger', function (string $gatewayStatus) {
    Event::fake([PaymentNeedsAttention::class]);
    $payment = rtHeldPayment();
    $before = DB::table('ledger_entries')->count();
    $row = rtRequested($payment, ['idempotency_key' => 'refund-pi_test_1-1']);
    $settle = app(SettleGatewayRefund::class);

    $settle($row, 'pending', 're_test_1');
    $settle($row, $gatewayStatus, 're_test_1');

    expect($row->fresh()->status)->toBe(PaymentRefundStatus::Failed)
        ->and($row->fresh()->failure_reason)->toBe("refund_{$gatewayStatus}")
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Captured)
        ->and(DB::table('ledger_entries')->count())->toBe($before);
    Event::assertDispatchedTimes(PaymentNeedsAttention::class, 1);
    Event::assertDispatched(PaymentNeedsAttention::class, fn ($e) => $e->reason === PaymentNeedsAttention::REFUND_FAILED);
})->with(['failed', 'canceled']);

it('never moves a settled row back: a late pending changes nothing, a later failure only alerts', function () {
    Event::fake([PaymentNeedsAttention::class]);
    $payment = rtHeldPayment();
    $row = rtLedgerRequested($payment, ['idempotency_key' => 'refund-pi_test_1-1']);
    $settle = app(SettleGatewayRefund::class);

    $settle($row, 'succeeded', 're_test_1');
    $settle($row, 'pending', 're_test_1');

    expect($row->fresh()->status)->toBe(PaymentRefundStatus::Succeeded)
        ->and($row->fresh()->gateway_status)->toBe('succeeded');
    Event::assertNotDispatched(PaymentNeedsAttention::class);

    $settle($row, 'failed', 're_test_1');

    expect($row->fresh()->status)->toBe(PaymentRefundStatus::Succeeded)
        ->and($payment->fresh()->refunded_amount->toFils())->toBe(4000);
    Event::assertDispatchedTimes(PaymentNeedsAttention::class, 1);
});

it('closes the payment and tells admins when the refund of an unverified capture succeeds', function () {
    Event::fake([PaymentNeedsAttention::class]);
    $payment = rtUnheldPayment();
    $row = rtRequested($payment, ['kind' => PaymentRefundKind::Unverified, 'amount' => 10000]);

    app(SettleGatewayRefund::class)($row, 'succeeded', 're_test_1');

    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($payment->fresh()->refunded_amount->toFils())->toBe(10000);
    Event::assertDispatched(PaymentNeedsAttention::class, fn ($e) => $e->reason === PaymentNeedsAttention::UNVERIFIED_CAPTURE_REFUNDED);
});

// ---- MakeGatewayRefund ----

it('leaves the row requested, and reports nothing made, when the refund call comes back pending', function () {
    $payment = rtUnheldPayment();
    $row = rtRequested($payment);
    $this->http
        ->reply('GET /v1/refunds', rtList([]))
        ->reply('POST /v1/refunds', rtRefundObject('pending'));

    $made = app(MakeGatewayRefund::class)($row);

    expect($made)->toBeFalse()
        ->and($row->fresh()->status)->toBe(PaymentRefundStatus::Requested)
        ->and($row->fresh()->gateway_refund_id)->toBe('re_test_1')
        ->and($payment->fresh()->refunded_amount)->toBeNull();
});

it('settles the row when the sweep asks again and the gateway now reports the refund succeeded', function () {
    Event::fake([PaymentNeedsAttention::class]);
    $payment = rtUnheldPayment(['updated_at' => now()->subHour()]);
    $row = rtRequested($payment, ['kind' => PaymentRefundKind::Unverified, 'amount' => 10000, 'requested_at' => now()->subHour()]);
    $this->http->reply('GET /v1/refunds', rtList([rtRefundObject('succeeded', 're_test_1', 10000, 'unverified-pi_test_1')]));

    Artisan::call('payments:retry-refunds', ['--no-interaction' => true]);

    expect($row->fresh()->status)->toBe(PaymentRefundStatus::Succeeded)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($this->http->requestsTo('POST /v1/refunds'))->toHaveCount(0);
});

// ---- refund.updated ----

it('settles the platform\'s own row from refund.updated, found by the key in its metadata before the id is stored', function () {
    Event::fake([PaymentNeedsAttention::class]);
    $payment = rtHeldPayment();
    $row = rtLedgerRequested($payment, ['idempotency_key' => 'refund-pi_test_1-1']);

    app(ApplyPaymentWebhook::class)('stripe', 'refund.updated', rtRefundObject('succeeded', 're_test_9', 4000, 'refund-pi_test_1-1'));

    expect($row->fresh()->status)->toBe(PaymentRefundStatus::Succeeded)
        ->and($row->fresh()->gateway_refund_id)->toBe('re_test_9')
        ->and($payment->fresh()->refunded_amount->toFils())->toBe(4000);
});

it('settles pending to failed from refund.updated, and ignores a pending that arrives after the failure', function () {
    Event::fake([PaymentNeedsAttention::class]);
    $payment = rtHeldPayment();
    $row = rtRequested($payment, ['idempotency_key' => 'refund-pi_test_1-1']);
    $webhook = app(ApplyPaymentWebhook::class);

    $webhook('stripe', 'refund.updated', rtRefundObject('pending', 're_test_9', 4000, 'refund-pi_test_1-1'));
    $webhook('stripe', 'charge.refund.updated', rtRefundObject('failed', 're_test_9', 4000, 'refund-pi_test_1-1'));
    $webhook('stripe', 'refund.updated', rtRefundObject('pending', 're_test_9', 4000, 'refund-pi_test_1-1'));

    expect($row->fresh()->status)->toBe(PaymentRefundStatus::Failed)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Captured);
    Event::assertDispatchedTimes(PaymentNeedsAttention::class, 1);
});

it('queues the reconcile for a refund.updated no row explains, but not for one that failed', function () {
    Bus::fake([ReconcileGatewayRefunds::class]);
    $payment = rtHeldPayment();
    $webhook = app(ApplyPaymentWebhook::class);

    $webhook('stripe', 'refund.updated', rtRefundObject('failed', 're_dash_1'));
    Bus::assertNotDispatched(ReconcileGatewayRefunds::class);

    $webhook('stripe', 'refund.updated', rtRefundObject('pending', 're_dash_2'));
    Bus::assertDispatchedTimes(ReconcileGatewayRefunds::class, 1);
    expect(PaymentRefund::query()->where('payment_id', $payment->id)->count())->toBe(0);
});

it('takes the platform\'s own open refunds out of a charge.refunded total, and re-reads them', function () {
    Bus::fake([ReconcileGatewayRefunds::class]);
    $payment = rtHeldPayment();
    rtRequested($payment, ['idempotency_key' => 'refund-pi_test_1-1', 'amount' => 4000]);

    app(ApplyPaymentWebhook::class)('stripe', 'charge.refunded', ['id' => 'ch_1', 'payment_intent' => 'pi_test_1', 'amount' => 10000, 'amount_refunded' => 4000, 'currency' => 'aed']);

    expect($payment->fresh()->refunded_amount)->toBeNull()
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Captured);
    Bus::assertDispatchedTimes(ReconcileGatewayRefunds::class, 1);
});

// ---- the reconcile job ----

it('records a refund made in the Stripe dashboard while pending as requested, and settles it when it succeeds', function () {
    $payment = rtHeldPayment();
    $this->http
        ->reply('GET /v1/refunds', rtList([rtRefundObject('pending', 're_dash_1', 2500)]))
        ->reply('GET /v1/refunds', rtList([rtRefundObject('succeeded', 're_dash_1', 2500)]));

    $first = app(RecordExternalRefunds::class)($payment);
    $row = PaymentRefund::query()->where('payment_id', $payment->id)->sole();

    expect($first)->toBe(1)
        ->and($row->kind)->toBe(PaymentRefundKind::External)
        ->and($row->status)->toBe(PaymentRefundStatus::Requested);

    $second = app(RecordExternalRefunds::class)($payment);

    expect($second)->toBe(0)
        ->and($row->fresh()->status)->toBe(PaymentRefundStatus::Succeeded)
        ->and($row->fresh()->completed_at)->not->toBeNull()
        ->and(PaymentRefund::query()->where('payment_id', $payment->id)->count())->toBe(1);
});

it('settles the platform\'s own pending row from the reconcile listing, by key, and records no external row for it', function () {
    $payment = rtHeldPayment();
    $row = rtRequested($payment, ['idempotency_key' => 'refund-pi_test_1-1']);
    $this->http->reply('GET /v1/refunds', rtList([rtRefundObject('failed', 're_test_9', 4000, 'refund-pi_test_1-1')]));

    $recorded = app(RecordExternalRefunds::class)($payment);

    expect($recorded)->toBe(0)
        ->and($row->fresh()->status)->toBe(PaymentRefundStatus::Failed)
        ->and($row->fresh()->gateway_refund_id)->toBe('re_test_9')
        ->and(PaymentRefund::query()->where('payment_id', $payment->id)->count())->toBe(1);
});

it('does not record a dashboard refund that already failed', function () {
    $payment = rtHeldPayment();
    $this->http->reply('GET /v1/refunds', rtList([rtRefundObject('failed', 're_dash_1', 2500)]));

    expect(app(RecordExternalRefunds::class)($payment))->toBe(0)
        ->and(PaymentRefund::query()->where('payment_id', $payment->id)->count())->toBe(0);
});

it('tells admins once a day when a refund has stayed pending past 72 hours', function () {
    Event::fake([PaymentNeedsAttention::class]);
    Cache::flush();
    $payment = rtHeldPayment();
    rtRequested($payment, ['idempotency_key' => 'refund-pi_test_1-1', 'gateway_refund_id' => 're_test_1', 'gateway_status' => 'pending', 'requested_at' => now()->subHours(80)]);
    rtRequested(rtHeldPayment(['gateway_ref' => 'pi_test_2']), ['idempotency_key' => 'refund-pi_test_2-1', 'gateway_refund_id' => 're_test_2', 'gateway_status' => 'pending', 'requested_at' => now()->subHours(2)]);
    $this->http->reply('GET /v1/refunds', rtList([rtRefundObject('pending', 're_test_1', 4000, 'refund-pi_test_1-1')]));

    Artisan::call('payments:retry-refunds', ['--no-interaction' => true]);
    Artisan::call('payments:retry-refunds', ['--no-interaction' => true]);

    Event::assertDispatchedTimes(PaymentNeedsAttention::class, 1);
});

// ---- paging ----

/**
 * @return list<array<string, mixed>>
 */
function rtManyRefunds(int $count, ?array $own = null, int $ownIndex = 120): array
{
    $refunds = [];

    for ($i = 1; $i <= $count; $i++) {
        $refunds[] = rtRefundObject('succeeded', sprintf('re_many_%03d', $i), 10);
    }

    if ($own !== null) {
        $refunds[$ownIndex] = $own;
    }

    return $refunds;
}

function rtPagedReplies(FakeStripeHttp $http, array $refunds): void
{
    foreach (array_chunk($refunds, 100) as $i => $page) {
        $http->reply('GET /v1/refunds', rtList($page, ($i + 1) * 100 < count($refunds)));
    }
}

it('lists all 150 refunds on one payment, following Stripe\'s cursor, not the first 100', function () {
    $payment = rtHeldPayment();
    rtPagedReplies($this->http, rtManyRefunds(150));

    $listed = app(PaymentGatewayManager::class)->forCode('stripe')->listRefunds($payment);
    $requests = $this->http->requestsTo('GET /v1/refunds');

    expect($listed)->toHaveCount(150)
        ->and($requests)->toHaveCount(2)
        ->and($requests[1]['params']['starting_after'])->toBe('re_many_100');
});

it('finds the refund it already made on the second page and does not make a second one', function () {
    $payment = rtHeldPayment();
    $own = rtRefundObject('succeeded', 're_own', 4000, 'refund-pi_test_1-1');
    rtPagedReplies($this->http, rtManyRefunds(150, $own));

    $result = app(PaymentGatewayManager::class)->forCode('stripe')->refund($payment, Money::fils(4000), 'refund-pi_test_1-1');

    expect($result->gatewayRef)->toBe('re_own')
        ->and($this->http->requestsTo('POST /v1/refunds'))->toHaveCount(0);
});

it('stops with an error that is not a refusal, and makes no refund, when the lookup cannot reach the end', function () {
    $payment = rtHeldPayment();

    for ($page = 0; $page < 60; $page++) {
        $this->http->reply('GET /v1/refunds', rtList([rtRefundObject('succeeded', "re_page_{$page}", 10)], true));
    }

    $call = fn () => app(PaymentGatewayManager::class)->forCode('stripe')->refund($payment, Money::fils(4000), 'refund-pi_test_1-1');

    expect($call)->toThrow(RuntimeException::class, 'stripe_refund_pages_exhausted')
        ->and($this->http->requestsTo('POST /v1/refunds'))->toHaveCount(0);
});

// ---- ledger:verify ----

it('is green for a capture held exactly, and red for one whose hold is not the payment\'s amount', function () {
    $payment = rtHeldPayment();

    expect(Artisan::call('ledger:verify', ['--no-interaction' => true]))->toBe(0)
        ->and(app(UnheldCaptures::class)())->toBeEmpty();

    $payment->update(['amount' => 12000]);

    expect(Artisan::call('ledger:verify', ['--no-interaction' => true]))->toBe(1)
        ->and(app(UnheldCaptures::class)()->all())->toBe([['payment_id' => $payment->id, 'lesson_id' => $payment->lesson_id, 'status' => 'captured', 'held' => 10000, 'amount' => 12000]]);

    $payment->update(['amount' => 10000]);

    expect(Artisan::call('ledger:verify', ['--no-interaction' => true]))->toBe(0);
});

it('is red for a capture whose lesson has ledger entries but no hold, and green for one with no entries at all (the sweep refunds that)', function () {
    $lesson = Lesson::factory()->create(['price' => 10000]);
    $payment = rtUnheldPayment(['lesson_id' => $lesson->id]);

    expect(app(UnheldCaptures::class)())->toBeEmpty();

    DB::table('ledger_entries')->insert([
        ['lesson_id' => $lesson->id, 'account' => 'refund', 'type' => 'refund', 'amount' => 500, 'created_at' => now()],
        ['lesson_id' => $lesson->id, 'account' => 'gateway', 'type' => 'refund', 'amount' => -500, 'created_at' => now()],
    ]);

    expect(app(UnheldCaptures::class)()->pluck('payment_id')->all())->toBe([$payment->id])
        ->and(Artisan::call('ledger:verify', ['--no-interaction' => true]))->toBe(1);
});

it('records a failing ledger check for the ops status when a capture is not held exactly', function () {
    $payment = rtHeldPayment();
    $payment->update(['amount' => 12000]);

    Artisan::call('ops:record-ledger-check', ['--no-interaction' => true]);

    expect(Cache::get(OpsStatusReport::LEDGER_CHECK_KEY)['result'] ?? null)->toBe('fail');
});
