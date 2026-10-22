<?php

use App\Actions\Payments\SettleGatewayRefund;
use App\Enums\LedgerAccount;
use App\Enums\LessonStatus;
use App\Enums\PaymentRefundKind;
use App\Enums\PaymentStatus;
use App\Events\Payments\PaymentNeedsAttention;
use App\Mail\Admin\AdminPaymentAttentionMail;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentGatewayAccount;
use App\Models\PaymentRefund;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

// R277: the 25b wording and duplicate-line leftovers.

function loPayment(array $overrides = []): Payment
{
    return Payment::factory()->create(array_replace([
        'lesson_id' => Lesson::factory()->withStatus(LessonStatus::Settled)->create()->id,
        'gateway' => 'fake',
        'gateway_ref' => 'pi_lo_1',
        'amount' => 10000,
        'status' => PaymentStatus::Captured,
        'raw_response' => ['amount_received' => 10000],
    ], $overrides));
}

it('names a different reason for each kind of refund that fails', function () {
    expect(PaymentNeedsAttention::refundFailedFor(PaymentRefundKind::Ledger))->toBe(PaymentNeedsAttention::REFUND_FAILED)
        ->and(PaymentNeedsAttention::refundFailedFor(PaymentRefundKind::Unverified))->toBe(PaymentNeedsAttention::UNVERIFIED_REFUND_FAILED)
        ->and(PaymentNeedsAttention::refundFailedFor(PaymentRefundKind::External))->toBe(PaymentNeedsAttention::EXTERNAL_REFUND_FAILED);
});

it('raises the reason that matches the kind when the gateway fails a refund it had accepted', function (PaymentRefundKind $kind, string $reason) {
    Event::fake([PaymentNeedsAttention::class]);
    $payment = loPayment();
    $overrides = ['payment_id' => $payment->id, 'kind' => $kind, 'idempotency_key' => 'k-lo-1', 'amount' => 4000];

    if ($kind === PaymentRefundKind::Ledger) {
        $overrides['ledger_entry_id'] = DB::table('ledger_entries')->insertGetId(['lesson_id' => $payment->lesson_id, 'account' => LedgerAccount::Refund->value, 'type' => 'refund', 'amount' => 4000, 'created_at' => now()]);
    }

    $row = PaymentRefund::factory()->requested()->create($overrides);

    app(SettleGatewayRefund::class)($row, 'failed', 're_lo_1');

    Event::assertDispatched(PaymentNeedsAttention::class, fn ($e) => $e->reason === $reason);
    Event::assertDispatchedTimes(PaymentNeedsAttention::class, 1);
})->with([
    'ledger' => [PaymentRefundKind::Ledger, PaymentNeedsAttention::REFUND_FAILED],
    'unverified' => [PaymentRefundKind::Unverified, PaymentNeedsAttention::UNVERIFIED_REFUND_FAILED],
    'external' => [PaymentRefundKind::External, PaymentNeedsAttention::EXTERNAL_REFUND_FAILED],
]);

it('tells admins once a day about a refund the gateway has held pending past the limit, with its own reason', function () {
    Event::fake([PaymentNeedsAttention::class]);
    PaymentGatewayAccount::factory()->fake()->active()->create();
    $payment = loPayment();
    PaymentRefund::factory()->requested()->create(['payment_id' => $payment->id, 'kind' => PaymentRefundKind::External, 'idempotency_key' => 'k-lo-2', 'gateway_refund_id' => 're_lo_2', 'requested_at' => now()->subHours(73)]);

    $this->artisan('payments:retry-refunds')->assertSuccessful();
    $this->artisan('payments:retry-refunds')->assertSuccessful();

    Event::assertDispatchedTimes(PaymentNeedsAttention::class, 1);
    Event::assertDispatched(PaymentNeedsAttention::class, fn ($e) => $e->reason === PaymentNeedsAttention::REFUND_STUCK);
});

it('words each attention mail for what actually happened', function (string $reason, array $contains, array $lacks) {
    $payment = loPayment(['refunded_amount' => 2500]);
    $html = (new AdminPaymentAttentionMail($payment, $reason))->render();

    foreach ($contains as $text) {
        expect($html)->toContain($text);
    }

    foreach ($lacks as $text) {
        expect($html)->not->toContain($text);
    }
})->with([
    'ledger refund refused or failed' => [PaymentNeedsAttention::REFUND_FAILED, ['The ledger has returned money', 'not, and needs you'], ['never involved']],
    'unverified capture' => [PaymentNeedsAttention::UNVERIFIED_REFUND_FAILED, ['booking could not be confirmed', 'The ledger was never involved'], ['The ledger has returned money']],
    'external refund failed' => [PaymentNeedsAttention::EXTERNAL_REFUND_FAILED, ['outside the platform', 'does not retry it'], ['The ledger has returned money', 'never involved']],
    'stuck pending' => [PaymentNeedsAttention::REFUND_STUCK, ['72 hours ago', 'still pending'], ['The ledger has returned money', 'refused']],
]);

it('lists a refund the gateway holds pending once, not also as not yet confirmed', function () {
    $payment = loPayment();
    $entry = DB::table('ledger_entries')->insertGetId(['lesson_id' => $payment->lesson_id, 'account' => LedgerAccount::Refund->value, 'type' => 'refund', 'amount' => 4000, 'created_at' => now()->subMinutes(30)]);
    PaymentRefund::factory()->requested()->create(['payment_id' => $payment->id, 'kind' => PaymentRefundKind::Ledger, 'ledger_entry_id' => $entry, 'idempotency_key' => 'k-lo-3', 'amount' => 4000, 'gateway_refund_id' => 're_lo_3', 'gateway_status' => 'pending', 'requested_at' => now()->subMinutes(30)]);

    $this->artisan('payments:retry-refunds', ['--list' => true])
        ->expectsOutputToContain('pending at gateway (pending)')
        ->doesntExpectOutputToContain('not yet confirmed')
        ->assertSuccessful();
});

it('reports a captured payment with ledger entries but no hold on one line of ledger:verify', function () {
    $payment = loPayment(['status' => PaymentStatus::Captured]);
    DB::table('ledger_entries')->insert(['lesson_id' => $payment->lesson_id, 'account' => LedgerAccount::Refund->value, 'type' => 'refund', 'amount' => 100, 'created_at' => now()]);
    DB::table('payments')->where('id', $payment->id)->update(['updated_at' => now()->subHours(2)]);

    $exit = Artisan::call('ledger:verify');

    expect($exit)->toBe(1)
        ->and(substr_count(Artisan::output(), "Payment {$payment->id} on lesson"))->toBe(1);
});
