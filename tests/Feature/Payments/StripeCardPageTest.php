<?php

use App\Enums\PaymentMethodStatus;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\PaymentGatewayAccount;
use App\Models\PaymentMethod;
use App\Models\User;
use Tests\Support\Payments\FakeStripeHttp;

/**
 * 23c (R248): the parent's add-card page under Stripe. The card number goes from the browser to
 * Stripe; this server creates the SetupIntent, then stores the brand, last four and expiry of the card
 * a confirmed one produced. Every Stripe call is the faked HTTP client.
 */
beforeEach(function () {
    $this->http = FakeStripeHttp::install();
    $this->parent = User::factory()->create();
});

afterEach(function () {
    FakeStripeHttp::uninstall();
});

function scardStripeActive(): void
{
    PaymentGatewayAccount::factory()->active()->create();
}

/** A confirmed SetupIntent for this account whose card is not yet attached to the customer. */
function scardConfirmed(User $account, string $id = 'seti_test_1', string $pm = 'pm_new_1', string $last4 = '4242', string $account_id = ''): void
{
    test()->http
        ->reply("GET /v1/setup_intents/{$id}", [
            'id' => $id, 'object' => 'setup_intent', 'status' => 'succeeded', 'customer' => 'cus_test_1',
            'metadata' => ['account_user_id' => $account_id !== '' ? $account_id : (string) $account->id],
            'payment_method' => ['id' => $pm, 'object' => 'payment_method', 'customer' => null, 'card' => ['brand' => 'visa', 'last4' => $last4, 'exp_month' => 12, 'exp_year' => 2034]],
        ])
        ->reply("POST /v1/payment_methods/{$pm}/attach", [
            'id' => $pm, 'object' => 'payment_method', 'customer' => 'cus_test_1',
            'card' => ['brand' => 'visa', 'last4' => $last4, 'exp_month' => 12, 'exp_year' => 2034],
        ]);
}

it('renders the Stripe card page with the publishable key and no test cards', function () {
    scardStripeActive();

    $this->actingAs($this->parent)->get(route('payment-methods.create', ['tutor' => 7]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('payment-methods/Create')
            ->where('driver', 'stripe')
            ->where('stripe.publishable_key', 'pk_test_example')
            ->has('cards', 0)
            ->where('current', null)
            ->where('tutor', 7)
            ->missing('number')->missing('cvc'));

    expect($this->http->requests)->toBe([]);
});

it('keeps the fake page as it was while the fake gateway is active', function () {
    $this->actingAs($this->parent)->get(route('payment-methods.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('driver', 'fake')->where('stripe', null)->has('cards', 2));
});

it('404s the card page and every Stripe card route when no gateway is active', function () {
    PaymentGatewayAccount::query()->update(['is_active' => false]);

    $this->actingAs($this->parent)->get(route('payment-methods.create'))->assertNotFound();
    $this->actingAs($this->parent)->postJson(route('payment-methods.setup-intent'))->assertNotFound();
    $this->actingAs($this->parent)->post(route('payment-methods.store-stripe'), ['setup_intent_id' => 'seti_test_1'])->assertNotFound();
});

it('404s the Stripe card routes while the fake gateway is active', function () {
    $this->actingAs($this->parent)->postJson(route('payment-methods.setup-intent'))->assertNotFound();
    $this->actingAs($this->parent)->post(route('payment-methods.store-stripe'), ['setup_intent_id' => 'seti_test_1'])->assertNotFound();

    expect($this->http->requests)->toBe([]);
});

it('keeps the Stripe card routes to signed-in parents', function () {
    scardStripeActive();

    $this->postJson(route('payment-methods.setup-intent'))->assertUnauthorized();
    $this->post(route('payment-methods.store-stripe'), ['setup_intent_id' => 'seti_test_1'])->assertRedirect(route('login'));
});

it('creates a customer and an off-session card SetupIntent that carry only our account id', function () {
    scardStripeActive();
    $this->http
        ->reply('POST /v1/customers', ['id' => 'cus_test_1', 'object' => 'customer'])
        ->reply('POST /v1/setup_intents', ['id' => 'seti_test_1', 'object' => 'setup_intent', 'client_secret' => 'seti_test_1_secret_abc']);

    $this->actingAs($this->parent)->postJson(route('payment-methods.setup-intent'))
        ->assertOk()
        ->assertExactJson(['setup_intent_id' => 'seti_test_1', 'client_secret' => 'seti_test_1_secret_abc']);

    $customer = $this->http->requestsTo('POST /v1/customers')[0];
    $setup = $this->http->requestsTo('POST /v1/setup_intents')[0];

    expect($customer['params'])->toBe(['metadata' => ['account_user_id' => (string) $this->parent->id]])
        ->and($this->http->idempotencyKeyOf($customer))->toBe('customer-account-'.$this->parent->id)
        ->and($setup['params']['customer'])->toBe('cus_test_1')
        ->and($setup['params']['usage'])->toBe('off_session')
        ->and($setup['params']['payment_method_types'])->toBe(['card'])
        ->and($setup['params']['metadata'])->toBe(['kind' => 'card_setup', 'account_user_id' => (string) $this->parent->id])
        ->and(json_encode($this->http->requests))->not->toContain($this->parent->email);
});

it('reuses the customer on the saved Stripe card instead of making another', function () {
    scardStripeActive();
    PaymentMethod::factory()->create(['account_user_id' => $this->parent->id, 'gateway' => 'stripe', 'gateway_customer_ref' => 'cus_existing', 'gateway_token' => 'pm_old_1']);
    $this->http->reply('POST /v1/setup_intents', ['id' => 'seti_test_2', 'object' => 'setup_intent', 'client_secret' => 'seti_test_2_secret_abc']);

    $this->actingAs($this->parent)->postJson(route('payment-methods.setup-intent'))->assertOk();

    expect($this->http->requestsTo('POST /v1/customers'))->toBe([])
        ->and($this->http->requestsTo('POST /v1/setup_intents')[0]['params']['customer'])->toBe('cus_existing');
});

it('saves the confirmed card as the account\'s only card and audits it', function () {
    scardStripeActive();
    scardConfirmed($this->parent);

    $this->actingAs($this->parent)
        ->post(route('payment-methods.store-stripe'), ['setup_intent_id' => 'seti_test_1'])
        ->assertRedirect(route('learners.index'));

    $card = PaymentMethod::query()->where('account_user_id', $this->parent->id)->sole();

    expect($card->gateway)->toBe('stripe')
        ->and($card->gateway_customer_ref)->toBe('cus_test_1')
        ->and($card->gateway_token)->toBe('pm_new_1')
        ->and($card->brand)->toBe('Visa')
        ->and($card->last4)->toBe('4242')
        ->and([$card->exp_month, $card->exp_year])->toBe([12, 2034])
        ->and($card->status)->toBe(PaymentMethodStatus::Active)
        ->and(AuditLog::query()->where('action', 'payment_method.saved')->count())->toBe(1)
        ->and($this->http->requestsTo('POST /v1/payment_methods/pm_old'))->toBe([]);
});

it('sends the parent on to the weekly slot or back to the learner they came from', function () {
    scardStripeActive();
    $learner = Learner::factory()->create(['account_user_id' => $this->parent->id]);

    scardConfirmed($this->parent);
    $this->actingAs($this->parent)
        ->post(route('payment-methods.store-stripe'), ['setup_intent_id' => 'seti_test_1', 'tutor' => 5, 'learner' => $learner->id])
        ->assertRedirect(route('weekly-slots.create', ['learner' => $learner->id, 'tutor' => 5]));

    scardConfirmed($this->parent, 'seti_test_2', 'pm_new_2');
    $this->actingAs($this->parent)
        ->post(route('payment-methods.store-stripe'), ['setup_intent_id' => 'seti_test_2', 'learner' => $learner->id])
        ->assertRedirect(route('learners.show', $learner));
});

it('replaces the saved card in place and detaches the old Stripe card', function () {
    scardStripeActive();
    $old = PaymentMethod::factory()->create(['account_user_id' => $this->parent->id, 'gateway' => 'stripe', 'gateway_customer_ref' => 'cus_test_1', 'gateway_token' => 'pm_old_1', 'last4' => '1111']);
    scardConfirmed($this->parent, last4: '4444');
    $this->http->reply('POST /v1/payment_methods/pm_old_1/detach', ['id' => 'pm_old_1', 'object' => 'payment_method', 'customer' => null]);

    $this->actingAs($this->parent)->post(route('payment-methods.store-stripe'), ['setup_intent_id' => 'seti_test_1'])->assertRedirect();

    $card = PaymentMethod::query()->where('account_user_id', $this->parent->id)->sole();

    expect($card->id)->toBe($old->id)
        ->and($card->gateway_token)->toBe('pm_new_1')
        ->and($card->last4)->toBe('4444')
        ->and($this->http->requestsTo('POST /v1/payment_methods/pm_old_1/detach'))->toHaveCount(1);
});

it('still saves the new card when the old one cannot be detached', function () {
    scardStripeActive();
    PaymentMethod::factory()->create(['account_user_id' => $this->parent->id, 'gateway' => 'stripe', 'gateway_customer_ref' => 'cus_test_1', 'gateway_token' => 'pm_old_1']);
    scardConfirmed($this->parent);
    $this->http->reply('POST /v1/payment_methods/pm_old_1/detach', ['error' => ['type' => 'invalid_request_error', 'message' => 'No such PaymentMethod']], 404);

    $this->actingAs($this->parent)->post(route('payment-methods.store-stripe'), ['setup_intent_id' => 'seti_test_1'])->assertRedirect();

    expect(PaymentMethod::query()->where('account_user_id', $this->parent->id)->sole()->gateway_token)->toBe('pm_new_1');
});

it('does not try to detach a fake card when a Stripe card replaces it', function () {
    scardStripeActive();
    PaymentMethod::factory()->create(['account_user_id' => $this->parent->id]);
    scardConfirmed($this->parent);

    $this->actingAs($this->parent)->post(route('payment-methods.store-stripe'), ['setup_intent_id' => 'seti_test_1'])->assertRedirect();

    expect($this->http->requestsTo('POST /v1/payment_methods/fake'))->toBe([])
        ->and(PaymentMethod::query()->where('account_user_id', $this->parent->id)->sole()->gateway)->toBe('stripe');
});

it('refuses a SetupIntent that belongs to another account and stores nothing', function () {
    scardStripeActive();
    scardConfirmed($this->parent, account_id: '999999');

    $this->actingAs($this->parent)
        ->post(route('payment-methods.store-stripe'), ['setup_intent_id' => 'seti_test_1'])
        ->assertSessionHasErrors('setup_intent_id');

    expect(PaymentMethod::query()->count())->toBe(0);
});

it('refuses a SetupIntent that has not completed and one Stripe does not know', function () {
    scardStripeActive();
    $this->http
        ->reply('GET /v1/setup_intents/seti_pending', ['id' => 'seti_pending', 'object' => 'setup_intent', 'status' => 'requires_action', 'customer' => 'cus_test_1', 'metadata' => ['account_user_id' => (string) $this->parent->id]])
        ->reply('GET /v1/setup_intents/seti_missing', ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such setupintent']], 404);

    $this->actingAs($this->parent)->post(route('payment-methods.store-stripe'), ['setup_intent_id' => 'seti_pending'])->assertSessionHasErrors('setup_intent_id');
    $this->actingAs($this->parent)->post(route('payment-methods.store-stripe'), ['setup_intent_id' => 'seti_missing'])->assertSessionHasErrors('setup_intent_id');

    expect(PaymentMethod::query()->count())->toBe(0);
});

it('rejects anything that is not a SetupIntent id, typed card numbers included', function (array $input) {
    scardStripeActive();

    $this->actingAs($this->parent)->post(route('payment-methods.store-stripe'), $input)->assertSessionHasErrors('setup_intent_id');

    expect($this->http->requests)->toBe([])->and(PaymentMethod::query()->count())->toBe(0);
})->with([
    'missing' => [[]],
    'a card number' => [['setup_intent_id' => '4242424242424242']],
    'a payment intent' => [['setup_intent_id' => 'pi_test_1']],
    'a path' => [['setup_intent_id' => 'seti_1/../customers']],
]);

it('says what is wrong with a card that has expired, failed or is about to expire', function () {
    $this->travelTo('2026-09-14 06:00:00');
    $card = PaymentMethod::factory()->create(['account_user_id' => $this->parent->id]);

    expect($card->expiryNotice())->toBeNull();

    $card->forceFill(['exp_month' => 9, 'exp_year' => 2026])->save();
    expect($card->expiryNotice())->toContain('expires soon');

    $card->forceFill(['exp_month' => 8, 'exp_year' => 2026])->save();
    expect($card->expiryNotice())->toContain('has expired');

    $card->forceFill(['exp_month' => 12, 'exp_year' => 2030, 'status' => PaymentMethodStatus::Failed])->save();
    expect($card->expiryNotice())->toContain('last charge');
});

it('shows the notice on the card page', function () {
    $this->travelTo('2026-09-14 06:00:00');
    PaymentMethod::factory()->create(['account_user_id' => $this->parent->id, 'exp_month' => 9, 'exp_year' => 2026]);

    $this->actingAs($this->parent)->get(route('payment-methods.create'))
        ->assertInertia(fn ($page) => $page->where('current.notice', fn ($notice) => str_contains($notice, 'expires soon')));
});

it('has no field for typing card details on the Stripe page', function () {
    expect(file_get_contents(resource_path('js/pages/payment-methods/Create.vue')))
        ->not->toMatch('/cvc|cvv|card-number|type="number"|autocomplete="cc-/i');
});
