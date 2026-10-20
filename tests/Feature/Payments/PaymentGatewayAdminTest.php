<?php

use App\Enums\PaymentGatewayMode;
use App\Enums\UserStatus;
use App\Filament\Resources\PaymentGateways\Pages\EditPaymentGateway;
use App\Filament\Resources\PaymentGateways\Pages\ListPaymentGateways;
use App\Models\AuditLog;
use App\Models\PaymentGatewayAccount;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->stripe = PaymentGatewayAccount::query()->where('code', 'stripe')->firstOrFail();
    $this->fake = PaymentGatewayAccount::query()->where('code', 'fake')->firstOrFail();
});

afterEach(function () {
    config(['payments.live_mode_allowed' => false]);
});

it('lists the registry for an admin', function () {
    Livewire::actingAs($this->admin)
        ->test(ListPaymentGateways::class)
        ->assertCanSeeTableRecords(PaymentGatewayAccount::query()->get())
        ->assertDontSeeHtml('data-test="no-active-gateway"');
});

it('tells the admin when no gateway is active', function () {
    PaymentGatewayAccount::query()->update(['is_active' => false]);

    Livewire::actingAs($this->admin)
        ->test(ListPaymentGateways::class)
        ->assertSeeHtml('data-test="no-active-gateway"');
});

it('refuses a non-admin and a suspended admin', function () {
    foreach ([User::factory()->create(), User::factory()->tutor()->create(), User::factory()->admin()->create(['status' => UserStatus::Suspended])] as $outsider) {
        test()->actingAs($outsider)->get(ListPaymentGateways::getUrl())->assertForbidden();
        test()->actingAs($outsider)->get(EditPaymentGateway::getUrl(['record' => $this->stripe]))->assertForbidden();
    }
});

it('saves credentials write-only and never puts them in the page or component state', function () {
    $component = Livewire::actingAs($this->admin)
        ->test(EditPaymentGateway::class, ['record' => $this->stripe->getRouteKey()])
        ->fillForm(['secret_publishable_key' => 'pk_test_typed_one', 'secret_secret_key' => 'sk_test_typed_two', 'secret_webhook_secret' => 'whsec_typed_three'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->stripe->fresh()->credentials)->toBe(['publishable_key' => 'pk_test_typed_one', 'secret_key' => 'sk_test_typed_two', 'webhook_secret' => 'whsec_typed_three'])
        ->and($this->stripe->fresh()->updated_by)->toBe($this->admin->id)
        ->and(json_encode($component->instance()->data))->not->toContain('typed_')
        ->and($component->html())->not->toContain('typed_');

    $fresh = Livewire::actingAs($this->admin)->test(EditPaymentGateway::class, ['record' => $this->stripe->getRouteKey()]);

    expect($fresh->html())->not->toContain('typed_')
        ->and(json_encode($fresh->instance()->data))->not->toContain('typed_')
        ->and($fresh->html())->toContain('Set — leave blank to keep');
});

it('keeps a stored credential when its input is left blank', function () {
    $this->stripe->credentials = ['publishable_key' => 'pk_test_keep', 'secret_key' => 'sk_test_keep', 'webhook_secret' => 'whsec_keep'];
    $this->stripe->save();

    Livewire::actingAs($this->admin)
        ->test(EditPaymentGateway::class, ['record' => $this->stripe->getRouteKey()])
        ->fillForm(['name' => 'Stripe Payments', 'secret_secret_key' => '', 'secret_webhook_secret' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->stripe->fresh()->credentials)->toBe(['publishable_key' => 'pk_test_keep', 'secret_key' => 'sk_test_keep', 'webhook_secret' => 'whsec_keep'])
        ->and($this->stripe->fresh()->name)->toBe('Stripe Payments');
});

it('audits the names of changed credentials and never a value', function () {
    Livewire::actingAs($this->admin)
        ->test(EditPaymentGateway::class, ['record' => $this->stripe->getRouteKey()])
        ->fillForm(['secret_secret_key' => 'sk_test_audit_probe'])
        ->call('save');

    $log = AuditLog::query()->where('action', 'payment_gateway.updated')->sole();

    expect($log->after)->toBe(['credentials_changed' => ['secret_key']])
        ->and(json_encode($log->toArray()))->not->toContain('sk_test_audit_probe');
});

it('refuses the live mode selection with a notification while the owner-only flag is off', function () {
    Livewire::actingAs($this->admin)
        ->test(EditPaymentGateway::class, ['record' => $this->stripe->getRouteKey()])
        ->fillForm(['mode' => PaymentGatewayMode::Live->value])
        ->call('save')
        ->assertNotified('Not saved');

    expect($this->stripe->fresh()->mode)->toBe(PaymentGatewayMode::Test)
        ->and(AuditLog::query()->where('action', 'payment_gateway.updated')->count())->toBe(0);
});

it('clears a typed secret from component state when the save is refused', function () {
    $component = Livewire::actingAs($this->admin)
        ->test(EditPaymentGateway::class, ['record' => $this->stripe->getRouteKey()])
        ->fillForm(['mode' => PaymentGatewayMode::Live->value, 'secret_secret_key' => 'sk_test_refused_probe'])
        ->call('save')
        ->assertNotified('Not saved');

    expect($this->stripe->fresh()->credentials)->toBeNull()
        ->and(json_encode($component->instance()->data))->not->toContain('sk_test_refused_probe')
        ->and($component->html())->not->toContain('sk_test_refused_probe');
});

it('activates and deactivates from the table, and refuses a gateway with no driver', function () {
    Livewire::actingAs($this->admin)
        ->test(ListPaymentGateways::class)
        ->callTableAction('deactivate', $this->fake);

    expect(PaymentGatewayAccount::query()->active()->count())->toBe(0);

    Livewire::actingAs($this->admin)
        ->test(ListPaymentGateways::class)
        ->callTableAction('activate', $this->stripe->fresh())
        ->assertNotified('Not activated');

    expect(PaymentGatewayAccount::query()->active()->count())->toBe(0);

    Livewire::actingAs($this->admin)
        ->test(ListPaymentGateways::class)
        ->callTableAction('activate', $this->fake->fresh());

    expect($this->fake->fresh()->is_active)->toBeTrue()
        ->and(PaymentGatewayAccount::query()->active()->count())->toBe(1);
});

it('does not let a parent, a tutor or a suspended admin run Deactivate or change credentials', function () {
    foreach ([User::factory()->create(), User::factory()->tutor()->create(), User::factory()->admin()->create(['status' => UserStatus::Suspended])] as $outsider) {
        try {
            Livewire::actingAs($outsider)->test(ListPaymentGateways::class)->callTableAction('deactivate', $this->fake->fresh());
            Livewire::actingAs($outsider)->test(EditPaymentGateway::class, ['record' => $this->stripe->getRouteKey()])
                ->fillForm(['secret_secret_key' => 'sk_test_outsider'])->call('save');
        } catch (Throwable) {
            // a refusal by any route is fine; what matters is the state below
        }

        expect($this->fake->fresh()->is_active)->toBeTrue()
            ->and($this->stripe->fresh()->credentials)->toBeNull();
    }
});
