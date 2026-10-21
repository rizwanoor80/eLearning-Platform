<?php

use App\Actions\Payments\ReconcileUnrecordedPayments;
use App\Actions\Payments\RequeueFailedRefund;
use App\Actions\Payments\SaveStripeCard;
use App\Actions\RecurringSlots\ChargeReservedLesson;
use App\Actions\RecurringSlots\ResumeRecurringSlot;
use App\Enums\ChargeOutcome;
use App\Enums\LessonStatus;
use App\Enums\PaymentGatewayMode;
use App\Enums\PaymentMethodStatus;
use App\Enums\PaymentRefundStatus;
use App\Enums\PaymentStatus;
use App\Enums\RecurringSlotPauseReason;
use App\Enums\RecurringSlotStatus;
use App\Exceptions\PaymentCaptureException;
use App\Exceptions\RecurringSlotException;
use App\Exceptions\RefundRequeueException;
use App\Filament\Resources\PaymentGateways\Pages\EditPaymentGateway;
use App\Jobs\Payments\DetachRemovedCard;
use App\Mail\Payments\WeeklyLessonChargeFailedMail;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentGatewayAccount;
use App\Models\PaymentMethod;
use App\Models\PaymentRefund;
use App\Models\RecurringSlot;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Payments\NoActivePaymentGateway;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\StripePaymentGateway;
use App\Support\Money;
use App\Support\Payments\StripeIdempotency;
use App\Support\Payments\StripeModeReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\Payments\FakeStripeHttp;

/**
 * 25a (R266): the test→live switch. A Stripe customer, card token and PaymentIntent exist in one mode only, so every
 * reference records the mode it was made in, every idempotency key carries the mode and the environment, and nothing
 * made in one mode is ever sent to the other. Only Stripe's public placeholder values appear here; the HTTP client is
 * the faked one, so nothing calls Stripe.
 *
 * "Now" is Monday 2026-09-14 06:00 UTC; a lesson starts Friday 2026-09-18 12:00, so its first charge is due at T-48h.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-14 06:00:00', 'UTC'));
    $this->http = FakeStripeHttp::install();
    $this->testDriver = new StripePaymentGateway('sk_test_example', 'whsec_example');
    $this->liveDriver = new StripePaymentGateway('sk_live_example', 'whsec_example', true);
});

afterEach(function () {
    FakeStripeHttp::uninstall();
    config(['payments.live_mode_allowed' => false]);
});

/** A parent with a Stripe card made in `$mode`, an active weekly slot and a lesson due for its first charge. */
function msSetup(PaymentGatewayMode $mode = PaymentGatewayMode::Test): array
{
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id]);
    $slot = RecurringSlot::factory()->create([
        'learner_id' => $learner->id,
        'tutor_profile_id' => TutorProfile::factory()->bookable()->create()->id,
        'timezone' => 'UTC',
        'starts_on' => '2026-09-14',
        'generated_until' => '2026-09-13',
    ]);
    $card = PaymentMethod::factory()->create([
        'account_user_id' => $parent->id,
        'gateway' => 'stripe',
        'gateway_mode' => $mode,
        'gateway_customer_ref' => "cus_{$mode->value}_1",
        'gateway_token' => "pm_{$mode->value}_1",
    ]);
    $lesson = Lesson::factory()->withStatus(LessonStatus::Reserved)->create([
        'tutor_profile_id' => $slot->tutor_profile_id,
        'learner_id' => $learner->id,
        'booked_by_user_id' => $parent->id,
        'recurring_slot_id' => $slot->id,
        'starts_at' => CarbonImmutable::parse('2026-09-18 12:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-18 13:00:00', 'UTC'),
        'next_charge_at' => CarbonImmutable::parse('2026-09-16 12:00:00', 'UTC'),
    ]);

    return compact('parent', 'learner', 'slot', 'card', 'lesson');
}

function msIntent(string $id, bool $live): array
{
    return [
        'id' => $id, 'object' => 'payment_intent', 'status' => 'succeeded', 'capture_method' => 'automatic', 'amount' => 10000,
        'amount_capturable' => 0, 'amount_received' => 10000, 'currency' => 'aed', 'livemode' => $live, 'latest_charge' => 'ch_x',
        'metadata' => [],
    ];
}

/** Stripe's own sentence for the mode: a reference of the other mode in any request body or header. */
function msCarries(FakeStripeHttp $http, string $needle): bool
{
    return str_contains(json_encode($http->requests), $needle);
}

it('scopes every idempotency key by the mode and the environment', function () {
    expect(StripeIdempotency::key(PaymentGatewayMode::Test, 'lesson:7:attempt:1'))->toBe('test:testing:lesson:7:attempt:1')
        ->and(StripeIdempotency::key(PaymentGatewayMode::Live, 'lesson:7:attempt:1'))->toBe('live:testing:lesson:7:attempt:1');

    ['card' => $card, 'lesson' => $lesson] = msSetup(PaymentGatewayMode::Live);
    $this->http->reply('POST /v1/payment_intents', msIntent('pi_live_1', true));

    $this->liveDriver->chargeSavedCard($lesson, Money::fils(10000), $card, 'lesson:7:attempt:1');

    expect($this->http->idempotencyKeyOf($this->http->requests[0]))->toBe('live:testing:lesson:7:attempt:1');
});

it('refuses a charge and a refund with a reference of the other mode before any call to Stripe', function () {
    ['card' => $testCard, 'lesson' => $lesson] = msSetup(PaymentGatewayMode::Test);
    $payment = Payment::factory()->create(['lesson_id' => $lesson->id, 'gateway' => 'stripe', 'gateway_mode' => PaymentGatewayMode::Test, 'gateway_ref' => 'pi_test_9', 'status' => PaymentStatus::Captured]);

    expect(fn () => $this->liveDriver->chargeSavedCard($lesson, Money::fils(10000), $testCard, 'k1'))->toThrow(PaymentCaptureException::class, StripePaymentGateway::OTHER_MODE_REASON)
        ->and(fn () => $this->liveDriver->refund($payment, Money::fils(10000), 'refund-1'))->toThrow(PaymentCaptureException::class, StripePaymentGateway::OTHER_MODE_REASON)
        ->and($this->liveDriver->listRefunds($payment))->toBe([])
        ->and($this->http->requests)->toBe([]);

    // The same references through the driver of their own mode do reach Stripe.
    $this->http->reply('GET /v1/refunds', ['object' => 'list', 'data' => [], 'has_more' => false]);
    expect($this->testDriver->listRefunds($payment))->toBe([])
        ->and($this->http->requests)->toHaveCount(1);
});

it('reads a Stripe row whose keys disagree with its mode as unusable, and refuses to save one', function () {
    $row = PaymentGatewayAccount::factory()->create();
    config(['payments.live_mode_allowed' => true]);

    expect(fn () => $row->forceFill(['mode' => PaymentGatewayMode::Live])->save())->toThrow(LogicException::class, 'not a live-mode key');

    $row->refresh()->forceFill(['mode' => PaymentGatewayMode::Live, 'credentials' => ['publishable_key' => 'pk_live_example', 'secret_key' => 'sk_live_example', 'webhook_secret' => 'whsec_live_example']])->save();

    expect($row->fresh()->keyModeProblem())->toBeNull();

    DB::table('payment_gateways')->where('id', $row->id)->update(['credentials' => $row->getRawOriginal('credentials')]);
    $mixed = PaymentGatewayAccount::query()->find($row->id);
    $mixed->mode = PaymentGatewayMode::Test;

    expect($mixed->keyModeProblem())->toContain('not a test-mode key');
});

it('refuses a Stripe payment or card with no mode at the database', function () {
    $parent = User::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('payment_methods')->insert([
        'account_user_id' => $parent->id, 'gateway' => 'stripe', 'gateway_token' => 'pm_test_x', 'brand' => 'visa', 'last4' => '4242',
        'exp_month' => 12, 'exp_year' => 2034, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class, 'payment_methods_stripe_has_mode');
});

it('proves the full test to live switch: no live call carries a test reference, the weekly charge fails cleanly, a new live card charges', function () {
    Mail::fake();
    ['parent' => $parent, 'slot' => $slot, 'card' => $card, 'lesson' => $lesson] = msSetup(PaymentGatewayMode::Test);

    // Before the switch: the test driver charges the test card and the stamps say test.
    $this->http->reply('POST /v1/payment_intents', msIntent('pi_test_1', false));
    $before = Lesson::factory()->withStatus(LessonStatus::Reserved)->create([
        'tutor_profile_id' => $slot->tutor_profile_id, 'learner_id' => $slot->learner_id, 'booked_by_user_id' => $parent->id, 'recurring_slot_id' => $slot->id,
        'starts_at' => CarbonImmutable::parse('2026-09-25 12:00:00', 'UTC'), 'ends_at' => CarbonImmutable::parse('2026-09-25 13:00:00', 'UTC'),
        'next_charge_at' => CarbonImmutable::parse('2026-09-14 05:00:00', 'UTC'),
    ]);

    expect(app(ChargeReservedLesson::class)($before->id, $this->testDriver))->toBe(ChargeOutcome::Charged)
        ->and(Payment::query()->where('lesson_id', $before->id)->sole()->gateway_mode)->toBe(PaymentGatewayMode::Test);

    $requestsBeforeSwitch = count($this->http->requests);

    // The switch: the live driver now makes the calls. The weekly lesson's charge cannot use the test card.
    $lesson->forceFill(['next_charge_at' => now()])->save();
    expect(app(ChargeReservedLesson::class)($lesson->id, $this->liveDriver))->toBe(ChargeOutcome::RetryScheduled);

    $failed = Payment::query()->where('lesson_id', $lesson->id)->sole();

    expect($failed->status)->toBe(PaymentStatus::Failed)
        ->and($failed->failure_reason)->toBe(ChargeReservedLesson::CARD_OTHER_MODE)
        ->and($failed->gateway_mode)->toBe(PaymentGatewayMode::Live)
        // The card did nothing wrong: it is not marked as declined, and it is not removed.
        ->and($card->fresh()->last_failed_at)->toBeNull()
        ->and($card->fresh()->status)->toBe(PaymentMethodStatus::Active)
        ->and($card->fresh()->removed_at)->toBeNull()
        ->and(count($this->http->requests))->toBe($requestsBeforeSwitch);

    Mail::assertQueued(WeeklyLessonChargeFailedMail::class, fn ($mail) => $mail->recipient->is($parent) && $mail->cardNeedsReplacing === true);
    expect((new WeeklyLessonChargeFailedMail($lesson, $parent, CarbonImmutable::now(), true))->render())->toContain('has to be added again');
    expect((new WeeklyLessonChargeFailedMail($lesson, $parent, CarbonImmutable::now(), false))->render())->not->toContain('has to be added again');

    // The parent adds a card in live mode: the next attempt charges it, with a live token and a live-scoped key.
    $card->forceFill(['removed_at' => now()])->save();
    $liveCard = PaymentMethod::factory()->create([
        'account_user_id' => $parent->id, 'gateway' => 'stripe', 'gateway_mode' => PaymentGatewayMode::Live,
        'gateway_customer_ref' => 'cus_live_1', 'gateway_token' => 'pm_live_1',
    ]);
    $this->http->reply('POST /v1/payment_intents', msIntent('pi_live_1', true));
    $this->travelTo(CarbonImmutable::parse('2026-09-17 00:00:00', 'UTC'));

    expect(app(ChargeReservedLesson::class)($lesson->id, $this->liveDriver))->toBe(ChargeOutcome::Charged);

    $afterSwitch = array_slice($this->http->requests, $requestsBeforeSwitch);

    expect($afterSwitch)->toHaveCount(1)
        ->and($this->http->idempotencyKeyOf($afterSwitch[0]))->toStartWith('live:testing:lesson:'.$lesson->id)
        ->and($afterSwitch[0]['params'])->toMatchArray(['customer' => 'cus_live_1', 'payment_method' => 'pm_live_1'])
        ->and(json_encode($afterSwitch))->not->toContain('_test_')
        ->and(Payment::query()->where('lesson_id', $lesson->id)->where('status', PaymentStatus::Captured)->sole()->gateway_mode)->toBe(PaymentGatewayMode::Live);
});

it('counts a card that needs adding again toward the strike like any failed charge', function () {
    ['slot' => $slot, 'lesson' => $lesson] = msSetup(PaymentGatewayMode::Test);
    $lesson->forceFill(['charge_attempts' => 2, 'next_charge_at' => now()])->save();

    app(ChargeReservedLesson::class)($lesson->id, $this->liveDriver);

    expect($slot->fresh()->consecutive_charge_failures)->toBe(1)
        ->and($this->http->requests)->toBe([]);
});

it('never continues a pending attempt that began in the other mode', function () {
    ['lesson' => $lesson, 'card' => $card] = msSetup(PaymentGatewayMode::Live);
    Payment::factory()->create([
        'lesson_id' => $lesson->id, 'attempt_no' => 1, 'gateway' => 'stripe', 'gateway_mode' => PaymentGatewayMode::Test, 'payment_method_id' => $card->id,
        'payer_user_id' => $card->account_user_id, 'amount' => $lesson->price, 'status' => PaymentStatus::Pending,
    ]);

    expect(app(ChargeReservedLesson::class)($lesson->id, $this->liveDriver))->toBe(ChargeOutcome::RetryScheduled)
        ->and(Payment::query()->where('lesson_id', $lesson->id)->sole()->failure_reason)->toBe(ChargeReservedLesson::GATEWAY_MODE_CHANGED)
        ->and($this->http->requests)->toBe([]);
});

it('tells the parent on the card page and refuses to resume a slot until the card is added again', function () {
    ['parent' => $parent, 'slot' => $slot, 'card' => $card] = msSetup(PaymentGatewayMode::Test);
    config(['payments.live_mode_allowed' => true]);
    PaymentGatewayAccount::factory()->active()->create([
        'mode' => PaymentGatewayMode::Live,
        'credentials' => ['publishable_key' => 'pk_live_example', 'secret_key' => 'sk_live_example', 'webhook_secret' => 'whsec_live_example'],
    ]);

    expect($card->fresh()->savedInOtherMode())->toBeTrue()
        ->and($card->fresh()->isUsable())->toBeFalse()
        ->and($card->fresh()->expiryNotice())->toBe(PaymentMethod::READD_NOTICE);

    $slot->forceFill(['status' => RecurringSlotStatus::Paused, 'paused_reason' => RecurringSlotPauseReason::PaymentFailed])->save();

    expect(fn () => app(ResumeRecurringSlot::class)($parent, $slot))->toThrow(RecurringSlotException::class, 'Replace your saved card');

    // Switching back makes the same card usable again: nothing was stored on it.
    PaymentGatewayAccount::query()->where('code', 'stripe')->firstOrFail()->forceFill([
        'mode' => PaymentGatewayMode::Test,
        'credentials' => ['publishable_key' => 'pk_test_example', 'secret_key' => 'sk_test_example', 'webhook_secret' => 'whsec_example'],
    ])->save();

    expect($card->fresh()->savedInOtherMode())->toBeFalse()
        ->and($card->fresh()->isUsable())->toBeTrue();
});

it('saves a new card with the mode of the driver that saved it, and detaches only a previous token of that mode', function () {
    $parent = User::factory()->create();
    $old = PaymentMethod::factory()->create(['account_user_id' => $parent->id, 'gateway' => 'stripe', 'gateway_mode' => PaymentGatewayMode::Test, 'gateway_token' => 'pm_test_old']);
    config(['payments.live_mode_allowed' => true]);
    PaymentGatewayAccount::factory()->active()->create([
        'mode' => PaymentGatewayMode::Live,
        'credentials' => ['publishable_key' => 'pk_live_example', 'secret_key' => 'sk_live_example', 'webhook_secret' => 'whsec_live_example'],
    ]);
    $this->http
        ->reply('GET /v1/setup_intents/seti_live_1', [
            'id' => 'seti_live_1', 'object' => 'setup_intent', 'status' => 'succeeded', 'customer' => 'cus_live_1',
            'metadata' => ['account_user_id' => (string) $parent->id],
            'payment_method' => ['id' => 'pm_live_new', 'object' => 'payment_method', 'customer' => null, 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2034]],
        ])
        ->reply('POST /v1/payment_methods/pm_live_new/attach', [
            'id' => 'pm_live_new', 'object' => 'payment_method', 'customer' => 'cus_live_1',
            'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2034],
        ]);

    $saved = app(SaveStripeCard::class)($parent, 'seti_live_1');

    expect($saved->gateway_mode)->toBe(PaymentGatewayMode::Live)
        ->and($saved->gateway_token)->toBe('pm_live_new')
        ->and($saved->isUsable())->toBeTrue()
        ->and(msCarries($this->http, 'pm_test_old'))->toBeFalse()
        ->and($this->http->requestsTo('POST /v1/payment_methods/pm_test_old/detach'))->toBe([]);
});

it('leaves a removed card of the other mode attached instead of detaching it from the wrong account', function () {
    $card = PaymentMethod::factory()->create(['gateway' => 'stripe', 'gateway_mode' => PaymentGatewayMode::Test, 'removed_at' => now(), 'status' => PaymentMethodStatus::Active]);
    config(['payments.live_mode_allowed' => true]);
    PaymentGatewayAccount::factory()->active()->create([
        'mode' => PaymentGatewayMode::Live,
        'credentials' => ['publishable_key' => 'pk_live_example', 'secret_key' => 'sk_live_example', 'webhook_secret' => 'whsec_live_example'],
    ]);

    app()->call([new DetachRemovedCard($card->id), 'handle']);

    expect($this->http->requests)->toBe([]);
});

it('refuses to ask again for a refund on a payment of the other mode, and to settle an intent of the other mode', function () {
    $admin = User::factory()->admin()->create();
    $payment = Payment::factory()->create(['gateway' => 'stripe', 'gateway_mode' => PaymentGatewayMode::Test, 'gateway_ref' => 'pi_test_5', 'status' => PaymentStatus::Captured]);
    $refund = PaymentRefund::factory()->create(['payment_id' => $payment->id, 'status' => PaymentRefundStatus::Failed]);
    config(['payments.live_mode_allowed' => true]);
    PaymentGatewayAccount::factory()->active()->create([
        'mode' => PaymentGatewayMode::Live,
        'credentials' => ['publishable_key' => 'pk_live_example', 'secret_key' => 'sk_live_example', 'webhook_secret' => 'whsec_live_example'],
    ]);

    expect(fn () => app(RequeueFailedRefund::class)($admin, $refund))->toThrow(RefundRequeueException::class, 'not the mode Stripe is in now')
        ->and($refund->fresh()->status)->toBe(PaymentRefundStatus::Failed)
        ->and($this->http->requests)->toBe([]);

    $pending = Payment::factory()->create(['gateway' => 'stripe', 'gateway_mode' => PaymentGatewayMode::Test, 'gateway_ref' => null, 'status' => PaymentStatus::Pending, 'created_at' => now()->subDay()]);
    app(ReconcileUnrecordedPayments::class)();

    expect($this->http->requests)->toBe([])
        ->and($pending->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('counts the references of each mode and what a switch would leave, read-only', function () {
    ['card' => $card] = msSetup(PaymentGatewayMode::Test);
    Payment::factory()->create(['gateway' => 'stripe', 'gateway_mode' => PaymentGatewayMode::Test, 'gateway_ref' => 'pi_test_5', 'status' => PaymentStatus::Pending]);

    $report = app(StripeModeReadiness::class)->for(PaymentGatewayMode::Live);

    expect($report['cards'])->toBe(['test' => 1, 'live' => 0])
        ->and($report['unusable_cards'])->toBe(1)
        ->and($report['slots_needing_new_card'])->toBe(1)
        ->and($report['pending_payments'])->toBe(1);

    Artisan::call('payments:mode-readiness', ['--target' => 'live']);
    $output = Artisan::output();

    expect($output)->toContain('Switching to live: 1 cards will need adding again, 1 active weekly slots affected, 1 payments pending')
        ->and($output)->toContain('Wait until nothing is in flight')
        ->and($card->fresh()->status)->toBe(PaymentMethodStatus::Active)
        ->and(Artisan::call('payments:mode-readiness', ['--target' => 'sandbox']))->not->toBe(0)
        ->and($this->http->requests)->toBe([]);
});

it('asks the admin to confirm a Stripe mode change and writes the confirmation to the audit log', function () {
    $admin = User::factory()->admin()->create();
    $stripe = PaymentGatewayAccount::query()->where('code', 'stripe')->firstOrFail();
    config(['payments.live_mode_allowed' => true]);

    $keys = ['secret_publishable_key' => 'pk_live_example', 'secret_secret_key' => 'sk_live_example', 'secret_webhook_secret' => 'whsec_live_example'];

    Livewire::actingAs($admin)
        ->test(EditPaymentGateway::class, ['record' => $stripe->getRouteKey()])
        ->fillForm(['mode' => PaymentGatewayMode::Live->value] + $keys)
        ->call('save')
        ->assertHasFormErrors(['mode_switch_confirmed']);

    expect($stripe->fresh()->mode)->toBe(PaymentGatewayMode::Test);

    Livewire::actingAs($admin)
        ->test(EditPaymentGateway::class, ['record' => $stripe->getRouteKey()])
        ->fillForm(['mode' => PaymentGatewayMode::Live->value, 'mode_switch_confirmed' => true] + $keys)
        ->call('save')
        ->assertHasNoFormErrors();

    $log = AuditLog::query()->where('action', 'payment_gateway.updated')->sole();

    expect($stripe->fresh()->mode)->toBe(PaymentGatewayMode::Live)
        ->and($log->after)->toMatchArray(['mode' => 'live', 'mode_switch_confirmed' => true])
        ->and(json_encode($log->toArray()))->not->toContain('sk_live_example');
});

it('asks for no confirmation when the mode is not changed', function () {
    $admin = User::factory()->admin()->create();
    $stripe = PaymentGatewayAccount::query()->where('code', 'stripe')->firstOrFail();

    Livewire::actingAs($admin)
        ->test(EditPaymentGateway::class, ['record' => $stripe->getRouteKey()])
        ->fillForm(['name' => 'Stripe Cards'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($stripe->fresh()->name)->toBe('Stripe Cards')
        ->and(AuditLog::query()->where('action', 'payment_gateway.updated')->sole()->after)->not->toHaveKey('mode_switch_confirmed');
});

it('does not hand out a driver for a row whose keys disagree with its mode', function () {
    $row = PaymentGatewayAccount::factory()->create();
    DB::table('payment_gateways')->where('id', $row->id)->update(['mode' => 'live']);

    expect(fn () => app(PaymentGatewayManager::class)->forRow($row->fresh()))->toThrow(NoActivePaymentGateway::class);
});
