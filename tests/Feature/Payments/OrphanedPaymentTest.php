<?php

use App\Actions\Payments\ReleaseOrphanedPendingPayment;
use App\Actions\Payments\RemoveSavedCard;
use App\Enums\LessonStatus;
use App\Enums\PaymentGatewayMode;
use App\Enums\PaymentStatus;
use App\Events\Payments\UnverifiedCaptureNeedsRefund;
use App\Exceptions\CardRemovalException;
use App\Exceptions\OrphanedPaymentException;
use App\Models\AuditLog;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentGatewayAccount;
use App\Models\PaymentMethod;
use App\Models\RecurringSlot;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Payments\FakeStripeHttp;

/**
 * 25c (R268): an admin settles a weekly-charge attempt left `pending` on a lesson that is no longer reserved. The
 * gateway is only read, never charged; money taken goes back through the unverified-capture refund, nothing taken
 * closes the row `failed`, and the parent can then remove the card.
 */
beforeEach(function () {
    $this->http = FakeStripeHttp::install();
    PaymentGatewayAccount::factory()->active()->create();
    $this->admin = User::factory()->admin()->create();
});

afterEach(function () {
    FakeStripeHttp::uninstall();
});

/** @return array{payment: Payment, lesson: Lesson, card: PaymentMethod} */
function opOrphan(array $payment = [], LessonStatus $status = LessonStatus::CancelledByParent, string $gateway = 'stripe'): array
{
    $parent = User::factory()->create();
    $card = PaymentMethod::factory()->create(['account_user_id' => $parent->id, 'gateway' => $gateway, 'gateway_customer_ref' => 'cus_1', 'gateway_token' => 'pm_old_1']);
    $slot = RecurringSlot::factory()->create();
    $lesson = Lesson::factory()->withStatus($status)->create(['recurring_slot_id' => $slot->id]);
    $row = Payment::factory()->create(array_replace([
        'lesson_id' => $lesson->id,
        'payer_user_id' => $parent->id,
        'payment_method_id' => $card->id,
        'gateway' => $gateway,
        'gateway_ref' => null,
        'attempt_no' => 1,
        'status' => PaymentStatus::Pending,
        'amount' => 10000,
    ], $payment));
    $row->forceFill(['created_at' => now()->subHour(), 'updated_at' => now()->subHour()])->saveQuietly();

    return ['payment' => $row, 'lesson' => $lesson, 'card' => $card];
}

function opIntentList(array $intents): array
{
    return ['object' => 'list', 'has_more' => false, 'data' => $intents];
}

function opIntent(string $status, int $lessonId, array $overrides = []): array
{
    return array_replace([
        'id' => 'pi_test_9', 'object' => 'payment_intent', 'status' => $status, 'capture_method' => 'automatic', 'amount' => 10000,
        'amount_received' => $status === 'succeeded' ? 10000 : 0, 'currency' => 'aed', 'livemode' => false,
        'metadata' => ['kind' => 'recurring', 'lesson_id' => (string) $lessonId, 'attempt' => '1', 'idempotency_key' => "lesson:{$lessonId}:attempt:1"],
    ], $overrides);
}

it('hands a charge Stripe did take to the unverified-capture refund, and never charges again', function () {
    Event::fake([UnverifiedCaptureNeedsRefund::class]);
    ['payment' => $payment, 'lesson' => $lesson] = opOrphan();
    $this->http->reply('GET /v1/payment_intents', opIntentList([opIntent('succeeded', $lesson->id)]));

    $outcome = app(ReleaseOrphanedPendingPayment::class)($this->admin, $payment, 'Parent skipped while Stripe was down.');

    expect($outcome)->toBe('refund_started')
        ->and($payment->fresh())->status->toBe(PaymentStatus::Captured)
        ->and($payment->fresh()->gateway_ref)->toBe('pi_test_9')
        ->and($payment->fresh()->raw_response['amount_received'])->toBe(10000)
        ->and($this->http->requestsTo('POST /v1/payment_intents'))->toBeEmpty();
    Event::assertDispatched(UnverifiedCaptureNeedsRefund::class, fn ($e) => $e->payment->id === $payment->id);

    $audit = AuditLog::query()->where('action', 'payment.orphan_released')->sole();
    expect($audit->after)->toMatchArray(['outcome' => 'refund_started', 'lesson_id' => $lesson->id, 'note' => 'Parent skipped while Stripe was down.'])
        ->and(json_encode($audit->toArray()))->not->toContain('pm_old_1')->not->toContain('cus_1');
});

it('closes the attempt as failed when Stripe holds no money for it, and then the card can be removed', function (string $stripeStatus) {
    ['payment' => $payment, 'lesson' => $lesson, 'card' => $card] = opOrphan();
    $this->http->reply('GET /v1/payment_intents', opIntentList($stripeStatus === 'none' ? [] : [opIntent($stripeStatus, $lesson->id)]));
    $account = User::query()->find($card->account_user_id);
    Queue::fake();

    expect(fn () => app(RemoveSavedCard::class)($account, true))->toThrow(CardRemovalException::class);

    expect(app(ReleaseOrphanedPendingPayment::class)($this->admin, $payment, 'Never reached Stripe.'))->toBe('failed')
        ->and($payment->fresh())->status->toBe(PaymentStatus::Failed)
        ->and($payment->fresh()->failure_reason)->toBe('orphan_released');

    app(RemoveSavedCard::class)($account, true);
    expect($card->fresh()->removed_at)->not->toBeNull();
})->with(['none', 'requires_payment_method', 'requires_action', 'canceled']);

it('refuses while Stripe says the attempt is still in progress, and when Stripe cannot be asked', function () {
    ['payment' => $payment, 'lesson' => $lesson] = opOrphan();
    $this->http->reply('GET /v1/payment_intents', opIntentList([opIntent('processing', $lesson->id)]));

    expect(fn () => app(ReleaseOrphanedPendingPayment::class)($this->admin, $payment, 'x'))->toThrow(OrphanedPaymentException::class, 'still in progress');
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);

    $this->http->reply('GET /v1/payment_intents', ['error' => ['type' => 'api_error', 'message' => 'boom']], 500);

    expect(fn () => app(ReleaseOrphanedPendingPayment::class)($this->admin, $payment, 'x'))->toThrow(OrphanedPaymentException::class, 'Try again');
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and(AuditLog::query()->where('action', 'payment.orphan_released')->count())->toBe(0);
});

it('ignores an intent that belongs to another lesson or attempt', function () {
    ['payment' => $payment, 'lesson' => $lesson] = opOrphan();
    $this->http->reply('GET /v1/payment_intents', opIntentList([
        opIntent('succeeded', $lesson->id + 1, ['metadata' => ['kind' => 'recurring', 'lesson_id' => (string) ($lesson->id + 1), 'attempt' => '1', 'idempotency_key' => 'lesson:'.($lesson->id + 1).':attempt:1']]),
        opIntent('succeeded', $lesson->id, ['id' => 'pi_old', 'metadata' => ['kind' => 'recurring', 'lesson_id' => (string) $lesson->id, 'attempt' => '2', 'idempotency_key' => "lesson:{$lesson->id}:attempt:2"]]),
    ]));

    expect(app(ReleaseOrphanedPendingPayment::class)($this->admin, $payment, 'x'))->toBe('failed')
        ->and($payment->fresh()->gateway_ref)->toBeNull();
});

it('does not let a newer cancelled intent hide an older one that took the money', function () {
    Event::fake([UnverifiedCaptureNeedsRefund::class]);
    ['payment' => $payment, 'lesson' => $lesson] = opOrphan();
    $this->http->reply('GET /v1/payment_intents', opIntentList([
        opIntent('canceled', $lesson->id, ['id' => 'pi_newer']),
        opIntent('succeeded', $lesson->id, ['id' => 'pi_older']),
    ]));

    expect(app(ReleaseOrphanedPendingPayment::class)($this->admin, $payment, 'x'))->toBe('refund_started')
        ->and($payment->fresh()->gateway_ref)->toBe('pi_older');
});

it('refuses a gateway it does not know how to look up', function () {
    ['payment' => $payment] = opOrphan(gateway: 'someday');

    expect(fn () => app(ReleaseOrphanedPendingPayment::class)($this->admin, $payment, 'x'))->toThrow(OrphanedPaymentException::class, 'cannot be looked up')
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('closes a pending payment of the fake gateway without asking Stripe', function () {
    ['payment' => $payment] = opOrphan(gateway: 'fake');

    expect(app(ReleaseOrphanedPendingPayment::class)($this->admin, $payment, 'x'))->toBe('failed')
        ->and($this->http->requests)->toBeEmpty();
});

it('refuses anything that is not an orphan', function (string $case) {
    ['payment' => $payment, 'lesson' => $lesson] = match ($case) {
        'reserved lesson' => opOrphan(status: LessonStatus::Reserved),
        'too fresh' => opOrphan(),
        'already captured' => opOrphan(['status' => PaymentStatus::Captured]),
        'no saved card (a booking)' => opOrphan(['payment_method_id' => null]),
        'other mode' => opOrphan(['gateway_mode' => PaymentGatewayMode::Live]),
        'ledger entry' => opOrphan(),
    };

    if ($case === 'too fresh') {
        $payment->forceFill(['created_at' => now()->subMinutes(5)])->saveQuietly();
    }

    if ($case === 'ledger entry') {
        $lesson->forceFill(['price' => 10000])->saveQuietly();
        app(LedgerService::class)->hold($lesson->fresh());
    }

    $before = $payment->fresh()->status;
    expect(fn () => app(ReleaseOrphanedPendingPayment::class)($this->admin, $payment, 'x'))->toThrow(OrphanedPaymentException::class)
        ->and($payment->fresh()->status)->toBe($before)
        ->and($this->http->requestsTo('GET /v1/payment_intents'))->toBeEmpty();
})->with(['reserved lesson', 'too fresh', 'already captured', 'no saved card (a booking)', 'other mode', 'ledger entry']);

it('refuses a non-admin', function () {
    ['payment' => $payment] = opOrphan();

    expect(fn () => app(ReleaseOrphanedPendingPayment::class)(User::factory()->create(), $payment, 'x'))->toThrow(OrphanedPaymentException::class, 'admin');
});

it('shows the lessons table action only for an orphan', function () {
    ['lesson' => $lesson] = opOrphan();
    $other = Lesson::factory()->withStatus(LessonStatus::CancelledByParent)->create();

    expect(ReleaseOrphanedPendingPayment::looksOrphaned($lesson->payment()->first(), $lesson->status))->toBeTrue()
        ->and(ReleaseOrphanedPendingPayment::looksOrphaned($other->payment()->first(), $other->status))->toBeFalse();
});
