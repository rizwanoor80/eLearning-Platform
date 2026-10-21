<?php

use App\Actions\Payments\RemoveSavedCard;
use App\Actions\Payments\SaveStripeCard;
use App\Actions\Payments\SaveTestCard;
use App\Actions\RecurringSlots\ChargeReservedLesson;
use App\Enums\CurriculumCode;
use App\Enums\LessonStatus;
use App\Enums\PaymentStatus;
use App\Enums\RecurringSlotStatus;
use App\Enums\TestCard;
use App\Exceptions\CardRemovalException;
use App\Jobs\Payments\DetachRemovedCard;
use App\Mail\Payments\WeeklyLessonChargeFailedMail;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentGatewayAccount;
use App\Models\PaymentMethod;
use App\Models\RecurringSlot;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\PaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Payments\FakeStripeHttp;

/**
 * 24c (R261): a parent removes the saved card. The row stays (payments and lessons point at it), `removed_at`
 * is set, a Stripe card is detached by a queued job, and a new card can be added at once. The weekly slot's
 * next charge then fails cleanly through the existing failure path.
 */
beforeEach(function () {
    $this->http = FakeStripeHttp::install();
    $this->parent = User::factory()->create();
});

afterEach(function () {
    FakeStripeHttp::uninstall();
});

function crStripeCard(User $account, string $token = 'pm_old_1'): PaymentMethod
{
    return PaymentMethod::factory()->create(['account_user_id' => $account->id, 'gateway' => 'stripe', 'gateway_customer_ref' => 'cus_1', 'gateway_token' => $token]);
}

function crSlot(User $parent, ?TutorProfile $tutor = null, int $weekday = 2): RecurringSlot
{
    // The slot factory's own found-or-created GCSE row: a random curriculum can collide with it on `code`.
    $gcse = Curriculum::query()->firstOrCreate(['code' => CurriculumCode::Gcse], ['name' => CurriculumCode::Gcse->value, 'sort' => 0]);
    $learner = Learner::factory()->create(['account_user_id' => $parent->id, 'curriculum_id' => $gcse->id]);

    return RecurringSlot::factory()->create([
        'learner_id' => $learner->id,
        'tutor_profile_id' => ($tutor ?? TutorProfile::factory()->bookable()->create())->id,
        'weekday' => $weekday,
        'timezone' => 'UTC',
        'starts_on' => '2026-09-14',
        'generated_until' => '2026-09-13',
    ]);
}

it('removes the card, keeps the row and the history behind it, and audits it', function () {
    $card = PaymentMethod::factory()->create(['account_user_id' => $this->parent->id]);
    $payment = Payment::factory()->create(['payment_method_id' => $card->id]);
    $lesson = Lesson::factory()->create(['payment_method_id' => $card->id]);

    $this->actingAs($this->parent)->delete(route('payment-methods.destroy'))->assertRedirect();

    $card->refresh();
    expect($card->removed_at)->not->toBeNull()
        ->and($card->isUsable())->toBeFalse()
        ->and($payment->fresh()->payment_method_id)->toBe($card->id)
        ->and($lesson->fresh()->payment_method_id)->toBe($card->id)
        ->and($this->parent->paymentMethod()->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'payment_method.removed')->sole()->after)->toMatchArray(['gateway' => 'fake', 'last4' => '4242'])
        ->and($this->http->requests)->toBe([]);
});

it('shows no current card after removal, and a new card can be added at once while the old row stays', function () {
    PaymentGatewayAccount::query()->update(['is_active' => false]);
    PaymentGatewayAccount::factory()->fake()->active()->create();
    $old = PaymentMethod::factory()->create(['account_user_id' => $this->parent->id]);

    $this->actingAs($this->parent)->delete(route('payment-methods.destroy'));
    $this->actingAs($this->parent)->get(route('payment-methods.create'))->assertInertia(fn ($page) => $page->where('current', null)->where('active_slot', false));

    $new = app(SaveTestCard::class)($this->parent, TestCard::cases()[0]);

    expect($new->id)->not->toBe($old->id)
        ->and($old->fresh()->removed_at)->not->toBeNull()
        ->and($new->removed_at)->toBeNull()
        ->and(PaymentMethod::query()->where('account_user_id', $this->parent->id)->count())->toBe(2)
        ->and(PaymentMethod::query()->live()->where('account_user_id', $this->parent->id)->sole()->id)->toBe($new->id);
});

it('allows one live card per account only, in the database', function () {
    PaymentMethod::factory()->create(['account_user_id' => $this->parent->id, 'removed_at' => now()]);
    PaymentMethod::factory()->create(['account_user_id' => $this->parent->id, 'removed_at' => now()]);
    PaymentMethod::factory()->create(['account_user_id' => $this->parent->id]);

    expect(fn () => PaymentMethod::factory()->create(['account_user_id' => $this->parent->id]))->toThrow(QueryException::class);
});

it('refuses with a message when there is no card to remove', function () {
    $this->actingAs($this->parent)->from('/payment-methods/create')->delete(route('payment-methods.destroy'))
        ->assertRedirect('/payment-methods/create')
        ->assertSessionHasErrors(['card' => 'You have no saved card to remove.']);

    expect(AuditLog::query()->where('action', 'payment_method.removed')->count())->toBe(0);
});

it('asks for confirmation while a weekly slot is active, and removes on yes', function () {
    $card = PaymentMethod::factory()->create(['account_user_id' => $this->parent->id]);
    crSlot($this->parent);

    $this->actingAs($this->parent)->get(route('payment-methods.create'))->assertInertia(fn ($page) => $page->where('active_slot', true));

    $this->actingAs($this->parent)->from('/payment-methods/create')->delete(route('payment-methods.destroy'))
        ->assertSessionHasErrors('card');
    expect($card->fresh()->removed_at)->toBeNull();

    $this->actingAs($this->parent)->delete(route('payment-methods.destroy'), ['confirm' => true])->assertSessionHasNoErrors();
    expect($card->fresh()->removed_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'payment_method.removed')->sole()->after['active_slot'])->toBeTrue();
});

it('does not ask for confirmation for a paused or ended slot', function () {
    $card = PaymentMethod::factory()->create(['account_user_id' => $this->parent->id]);
    // One tutor for both slots: two bookable tutors each draw a curriculum code and can collide on it.
    $tutor = TutorProfile::factory()->bookable()->create();
    crSlot($this->parent, $tutor, 2)->update(['status' => RecurringSlotStatus::Paused]);
    crSlot($this->parent, $tutor, 3)->update(['status' => RecurringSlotStatus::Ended]);

    $this->actingAs($this->parent)->delete(route('payment-methods.destroy'))->assertSessionHasNoErrors();

    expect($card->fresh()->removed_at)->not->toBeNull();
});

it('only ever removes the signed-in account\'s own card', function () {
    $other = User::factory()->create();
    $theirs = PaymentMethod::factory()->create(['account_user_id' => $other->id]);

    $this->actingAs($this->parent)->delete(route('payment-methods.destroy'))->assertSessionHasErrors('card');

    expect($theirs->fresh()->removed_at)->toBeNull();
});

it('sends a guest to the login page instead of removing anything', function () {
    $card = PaymentMethod::factory()->create(['account_user_id' => $this->parent->id]);

    $this->delete(route('payment-methods.destroy'))->assertRedirect(route('login'));

    expect($card->fresh()->removed_at)->toBeNull();
});

it('keeps the card while a payment with it is in flight', function () {
    $card = PaymentMethod::factory()->create(['account_user_id' => $this->parent->id]);
    Payment::factory()->create(['payment_method_id' => $card->id, 'status' => PaymentStatus::Pending]);

    expect(fn () => app(RemoveSavedCard::class)($this->parent, true))->toThrow(CardRemovalException::class, 'in progress');
    expect($card->fresh()->removed_at)->toBeNull();
});

it('queues the Stripe detach and the job detaches the token once, counting an already-detached card as done', function () {
    Queue::fake();
    PaymentGatewayAccount::factory()->active()->create();
    $card = crStripeCard($this->parent);

    $this->actingAs($this->parent)->delete(route('payment-methods.destroy'))->assertRedirect();
    Queue::assertPushed(DetachRemovedCard::class, fn ($job) => $job->paymentMethodId === $card->id);

    $this->http->reply('POST /v1/payment_methods/pm_old_1/detach', ['id' => 'pm_old_1', 'object' => 'payment_method', 'customer' => null]);
    app()->call([new DetachRemovedCard($card->id), 'handle']);
    expect($this->http->requestsTo('POST /v1/payment_methods/pm_old_1/detach'))->toHaveCount(1);

    $this->http->reply('POST /v1/payment_methods/pm_old_1/detach', ['error' => ['type' => 'invalid_request_error', 'message' => 'The payment method you provided is not attached to a customer so detachment is impossible.']], 400);
    app()->call([new DetachRemovedCard($card->id), 'handle']);

    expect($this->http->requestsTo('POST /v1/payment_methods/pm_old_1/detach'))->toHaveCount(2);
});

it('lets the detach job fail, to be retried, on any other gateway error, and never calls Stripe for a live or fake card', function () {
    PaymentGatewayAccount::factory()->active()->create();
    $card = crStripeCard($this->parent);
    $card->update(['removed_at' => now()]);
    $this->http->reply('POST /v1/payment_methods/pm_old_1/detach', ['error' => ['type' => 'api_error', 'message' => 'boom']], 500);

    // The rethrown error has no Stripe exception behind it: its trace would carry the token into failed_jobs.
    try {
        app()->call([new DetachRemovedCard($card->id), 'handle']);
        $thrown = null;
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown->getPrevious())->toBeNull()
        ->and($thrown->getMessage())->not->toContain('pm_old_1');

    $live = crStripeCard(User::factory()->create(), 'pm_live_1');
    $fake = PaymentMethod::factory()->create(['removed_at' => now()]);
    $before = count($this->http->requests);
    app()->call([new DetachRemovedCard($live->id), 'handle']);
    app()->call([new DetachRemovedCard($fake->id), 'handle']);

    expect(count($this->http->requests))->toBe($before);
});

it('removes a Stripe card end to end: the detach is sent, the token never leaves the server', function () {
    PaymentGatewayAccount::factory()->active()->create();
    crStripeCard($this->parent);
    $this->http->reply('POST /v1/payment_methods/pm_old_1/detach', ['id' => 'pm_old_1', 'object' => 'payment_method', 'customer' => null]);

    $response = $this->actingAs($this->parent)->get(route('payment-methods.create'));
    expect($response->getContent())->not->toContain('pm_old_1');

    $this->actingAs($this->parent)->delete(route('payment-methods.destroy'))->assertRedirect();

    expect($this->http->requestsTo('POST /v1/payment_methods/pm_old_1/detach'))->toHaveCount(1)
        ->and(PaymentMethod::query()->where('account_user_id', $this->parent->id)->sole()->removed_at)->not->toBeNull();
});

it('adds a new Stripe card after removal without touching the removed row, and reuses its customer', function () {
    PaymentGatewayAccount::factory()->active()->create();
    $old = crStripeCard($this->parent);
    $old->update(['removed_at' => now()]);
    $this->http->reply('POST /v1/setup_intents', ['id' => 'seti_test_9', 'object' => 'setup_intent', 'client_secret' => 'seti_test_9_secret_abc']);

    $this->actingAs($this->parent)->postJson(route('payment-methods.setup-intent'))->assertOk();
    expect($this->http->requestsTo('POST /v1/customers'))->toBe([])
        ->and($this->http->requestsTo('POST /v1/setup_intents')[0]['params']['customer'])->toBe('cus_1');

    $this->http
        ->reply('GET /v1/setup_intents/seti_test_9', [
            'id' => 'seti_test_9', 'object' => 'setup_intent', 'status' => 'succeeded', 'customer' => 'cus_1',
            'metadata' => ['account_user_id' => (string) $this->parent->id],
            'payment_method' => ['id' => 'pm_new_9', 'object' => 'payment_method', 'customer' => null, 'card' => ['brand' => 'visa', 'last4' => '1111', 'exp_month' => 12, 'exp_year' => 2034]],
        ])
        ->reply('POST /v1/payment_methods/pm_new_9/attach', ['id' => 'pm_new_9', 'object' => 'payment_method', 'customer' => 'cus_1', 'card' => ['brand' => 'visa', 'last4' => '1111', 'exp_month' => 12, 'exp_year' => 2034]]);

    $new = app(SaveStripeCard::class)($this->parent, 'seti_test_9');

    expect($new->id)->not->toBe($old->id)
        ->and($old->fresh()->gateway_token)->toBe('pm_old_1')
        ->and($old->fresh()->removed_at)->not->toBeNull()
        ->and($this->http->requestsTo('POST /v1/payment_methods/pm_old_1/detach'))->toBe([]);
});

it('fails a weekly slot\'s next charge cleanly after the card is removed, with the existing failure email', function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-14 06:00:00', 'UTC'));
    app()->instance(PaymentGateway::class, new FakePaymentGateway);
    $card = PaymentMethod::factory()->create(['account_user_id' => $this->parent->id]);
    $slot = crSlot($this->parent);
    $start = CarbonImmutable::parse('2026-09-18 12:00:00', 'UTC');
    $lesson = Lesson::factory()->withStatus(LessonStatus::Reserved)->create([
        'tutor_profile_id' => $slot->tutor_profile_id,
        'learner_id' => $slot->learner_id,
        'booked_by_user_id' => $this->parent->id,
        'recurring_slot_id' => $slot->id,
        'starts_at' => $start,
        'ends_at' => $start->addHour(),
        'next_charge_at' => $start->subHours(48),
    ]);

    $this->actingAs($this->parent)->delete(route('payment-methods.destroy'), ['confirm' => true]);

    $this->travelTo(CarbonImmutable::parse('2026-09-16 12:00:00', 'UTC'));
    Artisan::call('recurring:charge');

    $payment = Payment::query()->where('lesson_id', $lesson->id)->sole();
    expect($payment->status)->toBe(PaymentStatus::Failed)
        ->and($payment->failure_reason)->toBe('no_usable_card')
        ->and($payment->payment_method_id)->toBeNull()
        ->and($lesson->fresh()->status)->toBe(LessonStatus::Reserved)
        ->and($card->fresh()->last_failed_at)->toBeNull();
    Mail::assertQueued(WeeklyLessonChargeFailedMail::class, fn ($mail) => $mail->hasTo($this->parent->email));

    // Adding a card again lets the retry charge it.
    $new = PaymentMethod::factory()->create(['account_user_id' => $this->parent->id]);
    $this->travelTo(CarbonImmutable::parse('2026-09-17 00:00:00', 'UTC'));
    Artisan::call('recurring:charge');

    expect($lesson->fresh()->status)->toBe(LessonStatus::Confirmed)
        ->and($lesson->fresh()->payment_method_id)->toBe($new->id)
        ->and(Payment::query()->where('lesson_id', $lesson->id)->count())->toBe(2);
});

it('resumes a pending charge with the card it began with even though that card was removed since', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-16 12:00:00', 'UTC'));
    $card = PaymentMethod::factory()->create(['account_user_id' => $this->parent->id, 'removed_at' => now()]);
    $slot = crSlot($this->parent);
    $start = CarbonImmutable::parse('2026-09-18 12:00:00', 'UTC');
    $lesson = Lesson::factory()->withStatus(LessonStatus::Reserved)->create([
        'tutor_profile_id' => $slot->tutor_profile_id,
        'learner_id' => $slot->learner_id,
        'booked_by_user_id' => $this->parent->id,
        'recurring_slot_id' => $slot->id,
        'starts_at' => $start,
        'ends_at' => $start->addHour(),
        'next_charge_at' => $start->subHours(48),
    ]);
    $payment = Payment::factory()->create([
        'lesson_id' => $lesson->id,
        'attempt_no' => 1,
        'payer_user_id' => $this->parent->id,
        'payment_method_id' => $card->id,
        'gateway' => 'fake',
        'amount' => $lesson->price,
        'status' => PaymentStatus::Pending,
    ]);

    $outcome = app(ChargeReservedLesson::class)($lesson->id, new FakePaymentGateway);

    expect($outcome->name)->toBe('Charged')
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Captured)
        ->and($lesson->fresh()->status)->toBe(LessonStatus::Confirmed);
});
