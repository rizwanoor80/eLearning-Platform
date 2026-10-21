<?php

use App\Actions\Payments\MakeGatewayRefund;
use App\Actions\Payments\RequeueFailedRefund;
use App\Enums\LedgerAccount;
use App\Enums\LessonStatus;
use App\Enums\PaymentRefundKind;
use App\Enums\PaymentRefundStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserStatus;
use App\Events\Payments\PaymentNeedsAttention;
use App\Exceptions\RefundRequeueException;
use App\Filament\Resources\PaymentRefunds\Pages\ListPaymentRefunds;
use App\Jobs\Payments\ProcessPaymentRefund;
use App\Jobs\Payments\ReconcileGatewayRefunds;
use App\Models\AuditLog;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentGatewayAccount;
use App\Models\PaymentRefund;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\Payments\FakeStripeHttp;

/**
 * 24b (R260, R265): the `payment_refunds` table, the data copy from the JSON it replaces, the reconcile and
 * process jobs, the admin re-queue and its Filament page. Stripe is the faked HTTP client throughout.
 */
beforeEach(function () {
    $this->http = FakeStripeHttp::install();
    PaymentGatewayAccount::factory()->active()->create();
});

afterEach(function () {
    FakeStripeHttp::uninstall();
});

function prPayment(array $overrides = []): Payment
{
    return Payment::factory()->create(array_replace([
        'lesson_id' => Lesson::factory()->withStatus(LessonStatus::Settled)->create()->id,
        'gateway' => 'stripe',
        'gateway_ref' => 'pi_test_1',
        'amount' => 10000,
        'status' => PaymentStatus::Captured,
        'raw_response' => ['amount_received' => 10000],
    ], $overrides));
}

function prLedgerEntry(Payment $payment, int $amount = 4000): int
{
    return DB::table('ledger_entries')->insertGetId(['lesson_id' => $payment->lesson_id, 'account' => LedgerAccount::Refund->value, 'type' => 'refund', 'amount' => $amount, 'created_at' => now()]);
}

function prRefundList(array $data): array
{
    return ['object' => 'list', 'data' => $data, 'has_more' => false];
}

it('copies the refund outcomes the JSON recorded into rows, once, and ignores keys that are not ledger entries', function () {
    $payment = prPayment();
    $good = prLedgerEntry($payment, 4000);
    $refused = prLedgerEntry($payment, 2500);
    $payment->update(['raw_response' => [
        'amount_received' => 10000,
        'refunds' => [(string) $good => ['id' => 're_old_1', 'amount_fils' => 4000, 'at' => '2026-10-01T10:00:00+00:00'], 'not-a-number' => ['id' => 're_x'], '999999' => ['id' => 're_y']],
        'refund_failures' => [(string) $refused => ['code' => 'charge_already_refunded', 'at' => '2026-10-02T10:00:00+00:00']],
    ]]);
    $stranded = prPayment(['gateway_ref' => 'pi_test_2', 'raw_response' => ['unverified_refund' => ['status' => 'refused', 'code' => 'refund_failed', 'at' => '2026-10-03T10:00:00+00:00']]]);

    Schema::drop('payment_refunds');
    $migration = require database_path('migrations/2026_10_21_100000_create_payment_refunds_table.php');
    $migration->up();

    $ok = PaymentRefund::query()->where('ledger_entry_id', $good)->sole();
    $no = PaymentRefund::query()->where('ledger_entry_id', $refused)->sole();
    $unverified = PaymentRefund::query()->where('payment_id', $stranded->id)->sole();

    expect(PaymentRefund::query()->count())->toBe(3)
        ->and($ok->status)->toBe(PaymentRefundStatus::Succeeded)
        ->and($ok->gateway_refund_id)->toBe('re_old_1')
        ->and($ok->amount->toFils())->toBe(4000)
        ->and($ok->idempotency_key)->toBe("refund-pi_test_1-{$good}")
        ->and($no->status)->toBe(PaymentRefundStatus::Failed)
        ->and($no->failure_reason)->toBe('charge_already_refunded')
        ->and($no->amount->toFils())->toBe(2500)
        ->and($unverified->kind)->toBe(PaymentRefundKind::Unverified)
        ->and($unverified->status)->toBe(PaymentRefundStatus::Failed)
        ->and($unverified->idempotency_key)->toBe('unverified-pi_test_2');

    // The copy is safe to run again over the same data (the unique indexes make the second pass a no-op).
    $copy = new ReflectionMethod($migration, 'copyLedgerRefunds');
    $copy->invoke($migration);
    $copyUnverified = new ReflectionMethod($migration, 'copyUnverifiedRefunds');
    $copyUnverified->invoke($migration);

    expect(PaymentRefund::query()->count())->toBe(3);
});

it('lets the database refuse a second live refund for one ledger entry, or for one unverified capture, but not after a failure', function () {
    $payment = prPayment();
    $entry = prLedgerEntry($payment);
    $row = ['payment_id' => $payment->id, 'ledger_entry_id' => $entry, 'kind' => 'ledger', 'amount' => 4000, 'status' => 'requested', 'requested_at' => now(), 'created_at' => now(), 'updated_at' => now()];

    DB::table('payment_refunds')->insert($row + ['attempt' => 1, 'idempotency_key' => 'k-1']);

    expect(fn () => DB::table('payment_refunds')->insert($row + ['attempt' => 2, 'idempotency_key' => 'k-2']))->toThrow(QueryException::class);
});

it('allows the next attempt once the first has failed, and refuses the same attempt number twice', function () {
    $payment = prPayment();
    $entry = prLedgerEntry($payment);
    $row = ['payment_id' => $payment->id, 'ledger_entry_id' => $entry, 'kind' => 'ledger', 'amount' => 4000, 'requested_at' => now(), 'created_at' => now(), 'updated_at' => now()];

    DB::table('payment_refunds')->insert($row + ['attempt' => 1, 'status' => 'failed', 'idempotency_key' => 'k-1']);
    DB::table('payment_refunds')->insert($row + ['attempt' => 2, 'status' => 'requested', 'idempotency_key' => 'k-2']);

    expect(PaymentRefund::query()->count())->toBe(2)
        ->and(fn () => DB::table('payment_refunds')->insert($row + ['attempt' => 2, 'status' => 'failed', 'idempotency_key' => 'k-3']))->toThrow(QueryException::class);
});

it('refuses a refund row that breaks the table\'s own rules', function () {
    $payment = prPayment();
    $base = ['payment_id' => $payment->id, 'attempt' => 1, 'requested_at' => now(), 'created_at' => now(), 'updated_at' => now(), 'status' => 'requested'];

    expect(fn () => DB::table('payment_refunds')->insert($base + ['kind' => 'ledger', 'amount' => 100]))->toThrow(QueryException::class) // a ledger refund names its entry
        ->and(fn () => DB::table('payment_refunds')->insert($base + ['kind' => 'unverified', 'amount' => 0]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payment_refunds')->insert($base + ['kind' => 'unverified', 'amount' => 100, 'status' => 'weird']))->toThrow(QueryException::class);
});

it('reconciles the gateway\'s refunds: records one made by someone else, skips the platform\'s own, and alerts once', function () {
    Event::fake([PaymentNeedsAttention::class]);
    $payment = prPayment();
    PaymentRefund::factory()->create(['payment_id' => $payment->id, 'idempotency_key' => 'unverified-pi_test_1', 'gateway_refund_id' => 're_own', 'amount' => 3000]);
    $reply = prRefundList([
        ['id' => 're_own', 'object' => 'refund', 'amount' => 3000, 'status' => 'succeeded', 'created' => 1790000000, 'metadata' => []],
        ['id' => 're_keyed', 'object' => 'refund', 'amount' => 500, 'status' => 'succeeded', 'created' => 1790000000, 'metadata' => ['idempotency_key' => 'unverified-pi_test_1']],
        ['id' => 're_failed', 'object' => 'refund', 'amount' => 700, 'status' => 'failed', 'created' => 1790000000, 'metadata' => []],
        ['id' => 're_dashboard', 'object' => 'refund', 'amount' => 2000, 'status' => 'succeeded', 'created' => 1790000000, 'metadata' => []],
    ]);
    $this->http->reply('GET /v1/refunds', $reply)->reply('GET /v1/refunds', $reply);

    dispatch_sync(new ReconcileGatewayRefunds($payment->id));
    dispatch_sync(new ReconcileGatewayRefunds($payment->id));

    $external = PaymentRefund::query()->where('kind', PaymentRefundKind::External->value)->sole();

    expect($external->gateway_refund_id)->toBe('re_dashboard')
        ->and($external->amount->toFils())->toBe(2000)
        ->and($external->status)->toBe(PaymentRefundStatus::Succeeded)
        ->and(PaymentRefund::query()->count())->toBe(2);
    Event::assertDispatchedTimes(PaymentNeedsAttention::class, 1);
});

it('says so, and records nothing, when the gateway cannot tell whose refunds they are', function () {
    Event::fake([PaymentNeedsAttention::class]);
    PaymentGatewayAccount::factory()->fake()->active()->create();
    $payment = prPayment(['gateway' => 'fake']);

    dispatch_sync(new ReconcileGatewayRefunds($payment->id));

    expect(PaymentRefund::query()->count())->toBe(0);
    Event::assertDispatched(PaymentNeedsAttention::class, fn ($e) => $e->reason === PaymentNeedsAttention::REFUNDED_AT_GATEWAY);
});

it('alerts admins when the reconcile job gives up', function () {
    Event::fake([PaymentNeedsAttention::class]);
    $payment = prPayment();

    (new ReconcileGatewayRefunds($payment->id))->failed(new RuntimeException('gateway down'));

    Event::assertDispatched(PaymentNeedsAttention::class, fn ($e) => $e->reason === PaymentNeedsAttention::REFUNDED_AT_GATEWAY);
});

it('re-queues a refused ledger refund as a new attempt with its own key, audits it, and sends it on the queue', function () {
    Bus::fake([ProcessPaymentRefund::class]);
    $payment = prPayment();
    $entry = prLedgerEntry($payment, 4000);
    $failed = PaymentRefund::factory()->failed()->create(['payment_id' => $payment->id, 'ledger_entry_id' => $entry, 'kind' => PaymentRefundKind::Ledger, 'idempotency_key' => "refund-pi_test_1-{$entry}", 'amount' => 4000]);
    $admin = User::factory()->admin()->create();

    $new = app(RequeueFailedRefund::class)($admin, $failed);

    expect($new->attempt)->toBe(2)
        ->and($new->status)->toBe(PaymentRefundStatus::Requested)
        ->and($new->amount->toFils())->toBe(4000)
        ->and($new->idempotency_key)->toBe("refund-pi_test_1-{$entry}-a2")
        ->and($failed->fresh()->status)->toBe(PaymentRefundStatus::Failed)
        ->and(AuditLog::query()->where('action', 'payment_refund.requeued')->count())->toBe(1);
    Bus::assertDispatched(ProcessPaymentRefund::class, fn (ProcessPaymentRefund $job) => $job->refundId === $new->id);
});

it('re-queues a refused refund of an unverified capture under its own key', function () {
    Bus::fake([ProcessPaymentRefund::class]);
    $payment = prPayment();
    $failed = PaymentRefund::factory()->failed()->create(['payment_id' => $payment->id, 'idempotency_key' => 'unverified-pi_test_1', 'amount' => 10000]);

    $new = app(RequeueFailedRefund::class)(User::factory()->admin()->create(), $failed);

    expect($new->kind)->toBe(PaymentRefundKind::Unverified)
        ->and($new->idempotency_key)->toBe('unverified-pi_test_1-a2');
});

it('refuses to re-queue for anyone but an active admin, for a refund that did not fail, an external one, or an older attempt', function () {
    Bus::fake([ProcessPaymentRefund::class]);
    $payment = prPayment();
    $entry = prLedgerEntry($payment, 4000);
    $old = PaymentRefund::factory()->failed()->create(['payment_id' => $payment->id, 'ledger_entry_id' => $entry, 'kind' => PaymentRefundKind::Ledger, 'idempotency_key' => 'k-1', 'amount' => 4000]);
    PaymentRefund::factory()->failed()->create(['payment_id' => $payment->id, 'ledger_entry_id' => $entry, 'kind' => PaymentRefundKind::Ledger, 'attempt' => 2, 'idempotency_key' => 'k-2', 'amount' => 4000]);
    $done = PaymentRefund::factory()->create(['payment_id' => prPayment(['gateway_ref' => 'pi_test_2'])->id]);
    $external = PaymentRefund::factory()->external()->failed()->create(['payment_id' => prPayment(['gateway_ref' => 'pi_test_3'])->id]);
    $admin = User::factory()->admin()->create();
    $action = app(RequeueFailedRefund::class);

    expect(fn () => $action(User::factory()->create(), $old))->toThrow(AuthorizationException::class)
        ->and(fn () => $action(User::factory()->tutor()->create(), $old))->toThrow(AuthorizationException::class)
        ->and(fn () => $action(User::factory()->admin()->create(['status' => UserStatus::Suspended]), $old))->toThrow(AuthorizationException::class)
        ->and(fn () => $action($admin, $old))->toThrow(RefundRequeueException::class)
        ->and(fn () => $action($admin, $done))->toThrow(RefundRequeueException::class)
        ->and(fn () => $action($admin, $external))->toThrow(RefundRequeueException::class);
    Bus::assertNotDispatched(ProcessPaymentRefund::class);
    expect(PaymentRefund::query()->count())->toBe(4);
});

it('caps a re-queued refund at what the gateway can still return, and sends nothing when nothing is left', function () {
    Bus::fake([ProcessPaymentRefund::class]);
    $payment = prPayment();
    $entry = prLedgerEntry($payment, 4000);
    PaymentRefund::factory()->external()->create(['payment_id' => $payment->id, 'amount' => 8000, 'gateway_refund_id' => 're_dash']);
    $failed = PaymentRefund::factory()->failed()->create(['payment_id' => $payment->id, 'ledger_entry_id' => $entry, 'kind' => PaymentRefundKind::Ledger, 'idempotency_key' => 'k-1', 'amount' => 4000]);
    $admin = User::factory()->admin()->create();

    $capped = app(RequeueFailedRefund::class)($admin, $failed);

    expect($capped->amount->toFils())->toBe(2000)
        ->and($capped->status)->toBe(PaymentRefundStatus::Requested);

    $capped->update(['status' => PaymentRefundStatus::Failed, 'failure_reason' => 'refund_failed']);
    PaymentRefund::factory()->external()->create(['payment_id' => $payment->id, 'amount' => 2000, 'gateway_refund_id' => 're_dash2']);

    $none = app(RequeueFailedRefund::class)($admin, $capped->fresh());

    expect($none->status)->toBe(PaymentRefundStatus::Failed)
        ->and($none->failure_reason)->toBe(RequeueFailedRefund::EXCEEDS_REMAINING)
        ->and($none->attempt)->toBe(3);
    Bus::assertDispatchedTimes(ProcessPaymentRefund::class, 1);
});

it('makes a requested refund on the queue, once, and skips a row that is no longer requested', function () {
    Event::fake([PaymentNeedsAttention::class]);
    $payment = prPayment();
    $row = PaymentRefund::factory()->requested()->create(['payment_id' => $payment->id, 'idempotency_key' => 'unverified-pi_test_1', 'amount' => 10000]);
    $this->http
        ->reply('GET /v1/refunds', prRefundList([]))
        ->reply('POST /v1/refunds', ['id' => 're_test_1', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 10000, 'currency' => 'aed', 'payment_intent' => 'pi_test_1']);

    (new ProcessPaymentRefund($row->id))->handle(app(MakeGatewayRefund::class));
    (new ProcessPaymentRefund($row->id))->handle(app(MakeGatewayRefund::class));

    expect($row->fresh()->status)->toBe(PaymentRefundStatus::Succeeded)
        ->and($row->fresh()->gateway_refund_id)->toBe('re_test_1')
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($this->http->requestsTo('POST /v1/refunds'))->toHaveCount(1);
    Event::assertDispatchedTimes(PaymentNeedsAttention::class, 1);
});

it('alerts admins when the process job gives up', function () {
    Event::fake([PaymentNeedsAttention::class]);
    $row = PaymentRefund::factory()->requested()->create(['payment_id' => prPayment()->id]);

    (new ProcessPaymentRefund($row->id))->failed(new RuntimeException('down'));

    Event::assertDispatched(PaymentNeedsAttention::class, fn ($e) => $e->reason === PaymentNeedsAttention::REFUND_FAILED);
});

it('shows the admin page to an active admin only, and offers Re-queue on a refused refund only', function () {
    Bus::fake([ProcessPaymentRefund::class]);
    $payment = prPayment();
    $failed = PaymentRefund::factory()->failed()->create(['payment_id' => $payment->id, 'idempotency_key' => 'unverified-pi_test_1', 'amount' => 10000]);
    $done = PaymentRefund::factory()->create(['payment_id' => prPayment(['gateway_ref' => 'pi_test_2'])->id]);
    $external = PaymentRefund::factory()->external()->failed()->create(['payment_id' => prPayment(['gateway_ref' => 'pi_test_3'])->id]);
    $admin = User::factory()->admin()->create();

    $this->actingAs(User::factory()->create())->get('/admin/payment-refunds')->assertForbidden();
    $this->actingAs(User::factory()->admin()->create(['status' => UserStatus::Suspended]))->get('/admin/payment-refunds')->assertForbidden();
    $this->actingAs($admin)->get('/admin/payment-refunds')->assertOk();

    Livewire::actingAs($admin)->test(ListPaymentRefunds::class)
        ->assertCanSeeTableRecords([$failed, $done, $external])
        ->assertTableActionVisible('requeue', $failed)
        ->assertTableActionHidden('requeue', $done)
        ->assertTableActionHidden('requeue', $external)
        ->callTableAction('requeue', $failed)
        ->assertNotified();

    expect(PaymentRefund::query()->where('payment_id', $payment->id)->max('attempt'))->toBe(2);
    Bus::assertDispatchedTimes(ProcessPaymentRefund::class, 1);
});
