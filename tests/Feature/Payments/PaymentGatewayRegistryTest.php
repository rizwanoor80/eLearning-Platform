<?php

use App\Actions\Payments\ActivatePaymentGateway;
use App\Actions\Payments\DeactivatePaymentGateway;
use App\Enums\PaymentGatewayMode;
use App\Models\AuditLog;
use App\Models\PaymentGatewayAccount;
use App\Models\RecurringSlot;
use App\Models\User;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\NoActivePaymentGateway;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\StripePaymentGateway;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function pgStripe(): PaymentGatewayAccount
{
    return PaymentGatewayAccount::query()->where('code', 'stripe')->firstOrFail();
}

function pgFake(): PaymentGatewayAccount
{
    return PaymentGatewayAccount::query()->where('code', 'fake')->firstOrFail();
}

function pgRunMigrationIn(string $environment): void
{
    Schema::dropIfExists('payment_gateways');

    app()->detectEnvironment(fn () => $environment);

    try {
        (require glob(database_path('migrations/*_create_payment_gateways_table.php'))[0])->up();
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
}

afterEach(function () {
    config(['payments.live_mode_allowed' => false]);
});

it('migrates with fake active and stripe inactive, in test mode, with no credentials', function () {
    expect(pgFake()->is_active)->toBeTrue()
        ->and(pgStripe()->is_active)->toBeFalse()
        ->and(pgStripe()->mode)->toBe(PaymentGatewayMode::Test)
        ->and(pgStripe()->credentials)->toBeNull()
        ->and(pgStripe()->hasCompleteCredentials())->toBeFalse();
});

it('leaves no row active when the migration runs in production, staging or an unknown environment', function (string $environment) {
    pgRunMigrationIn($environment);

    expect(PaymentGatewayAccount::query()->count())->toBe(2)
        ->and(PaymentGatewayAccount::query()->active()->count())->toBe(0);
})->with(['production', 'staging', 'prod', 'a-typo']);

it('activates the fake row where the migration runs in rehearsal', function () {
    pgRunMigrationIn('rehearsal');

    expect(pgFake()->is_active)->toBeTrue();
});

it('refuses a second active row at the database', function () {
    expect(fn () => DB::table('payment_gateways')->where('code', 'stripe')->update(['is_active' => true]))->toThrow(QueryException::class);
});

it('resolves the driver from the active row and nothing when none is active', function () {
    $manager = app(PaymentGatewayManager::class);

    expect($manager->available())->toBeTrue()
        ->and($manager->testMode())->toBeTrue()
        ->and($manager->active())->toBeInstanceOf(FakePaymentGateway::class)
        ->and(app(PaymentGateway::class))->toBeInstanceOf(FakePaymentGateway::class);

    app(DeactivatePaymentGateway::class)(pgFake(), null);

    expect($manager->available())->toBeFalse()
        ->and($manager->testMode())->toBeFalse()
        ->and(fn () => $manager->active())->toThrow(NoActivePaymentGateway::class)
        ->and(fn () => app(PaymentGateway::class))->toThrow(NoActivePaymentGateway::class);
});

it('re-checks the fake allow-list on every resolution, not only when the row is saved', function () {
    app()['env'] = 'production';

    $manager = app(PaymentGatewayManager::class);

    expect(pgFake()->is_active)->toBeTrue()
        ->and($manager->available())->toBeFalse()
        ->and($manager->testMode())->toBeFalse()
        ->and(fn () => $manager->active())->toThrow(NoActivePaymentGateway::class)
        ->and(fn () => $manager->forCode('fake'))->toThrow(NoActivePaymentGateway::class);
});

it('lets a stripe row with complete credentials become the active driver, and switches the driver with it', function () {
    $stripe = PaymentGatewayAccount::factory()->create();

    expect($stripe->hasCompleteCredentials())->toBeTrue()
        ->and($stripe->activationProblem())->toBeNull()
        ->and(app(PaymentGatewayManager::class)->forCode('stripe'))->toBeInstanceOf(StripePaymentGateway::class);

    app(DeactivatePaymentGateway::class)(pgFake(), null);
    app(ActivatePaymentGateway::class)($stripe, null);

    $manager = app(PaymentGatewayManager::class);

    expect($manager->active())->toBeInstanceOf(StripePaymentGateway::class)
        ->and(app(PaymentGateway::class))->toBeInstanceOf(StripePaymentGateway::class)
        ->and($manager->testMode())->toBeTrue();

    app(DeactivatePaymentGateway::class)($stripe->fresh(), null);
    app(ActivatePaymentGateway::class)(pgFake(), null);

    expect(app(PaymentGateway::class))->toBeInstanceOf(FakePaymentGateway::class);
});

it('refuses to activate a stripe row that has no credentials', function () {
    $stripe = pgStripe();

    expect($stripe->activationProblem())->toContain('missing credentials')
        ->and(fn () => app(ActivatePaymentGateway::class)($stripe, null))->toThrow(LogicException::class, 'missing credentials')
        ->and(fn () => $stripe->forceFill(['is_active' => true])->save())->toThrow(LogicException::class);

    expect(pgFake()->is_active)->toBeTrue();
});

it('refuses live mode on any row while the owner-only flag is off, active or not', function () {
    expect(config('payments.live_mode_allowed'))->toBeFalse();

    $stripe = pgStripe();
    $stripe->mode = PaymentGatewayMode::Live;

    expect(fn () => $stripe->save())->toThrow(LogicException::class, 'Live mode is not allowed');
    expect(pgStripe()->mode)->toBe(PaymentGatewayMode::Test);

    $fake = pgFake();
    $fake->mode = PaymentGatewayMode::Live;

    expect(fn () => $fake->save())->toThrow(LogicException::class);
});

it('lets a non-fake row go live only with the flag on, and never the fake row', function () {
    config(['payments.live_mode_allowed' => true]);

    $stripe = pgStripe();
    $stripe->mode = PaymentGatewayMode::Live;
    $stripe->save();

    expect(pgStripe()->mode)->toBe(PaymentGatewayMode::Live);

    $fake = pgFake();
    $fake->mode = PaymentGatewayMode::Live;

    expect(fn () => $fake->save())->toThrow(LogicException::class, 'fake gateway cannot run in live mode');
});

it('does not resolve a row that was raw-updated to live', function () {
    DB::table('payment_gateways')->where('code', 'fake')->update(['mode' => 'live']);

    $manager = app(PaymentGatewayManager::class);

    expect($manager->available())->toBeFalse()
        ->and(fn () => $manager->active())->toThrow(NoActivePaymentGateway::class);
});

it('still lets a live row that the flag no longer allows be saved without changing its mode', function () {
    config(['payments.live_mode_allowed' => true]);
    $stripe = pgStripe();
    $stripe->mode = PaymentGatewayMode::Live;
    $stripe->save();
    config(['payments.live_mode_allowed' => false]);

    $stripe->refresh();
    $stripe->name = 'Stripe (renamed)';

    expect(fn () => $stripe->save())->not->toThrow(LogicException::class);
});

it('refuses to switch saved cards off on the active row while a weekly slot is active', function () {
    $fake = pgFake();

    RecurringSlot::factory()->create();

    $fake->supports_saved_cards = false;

    expect(fn () => $fake->save())->toThrow(LogicException::class, 'weekly slots are active');
    expect(pgFake()->supports_saved_cards)->toBeTrue();
});

it('allows switching saved cards off when no weekly slot is active', function () {
    RecurringSlot::factory()->paused()->create();

    $fake = pgFake();
    $fake->supports_saved_cards = false;
    $fake->save();

    expect(pgFake()->supports_saved_cards)->toBeFalse();
});

it('records who activated and deactivated, naming codes and nothing else', function () {
    $admin = User::factory()->admin()->create();

    app(DeactivatePaymentGateway::class)(pgFake(), $admin);

    expect(PaymentGatewayAccount::query()->active()->count())->toBe(0);

    app(ActivatePaymentGateway::class)(pgFake(), $admin);

    $deactivated = AuditLog::query()->where('action', 'payment_gateway.deactivated')->sole();
    $activated = AuditLog::query()->where('action', 'payment_gateway.activated')->sole();

    expect($deactivated->before)->toBe(['active' => 'fake'])
        ->and($deactivated->after)->toBe(['active' => null])
        ->and($activated->before)->toBe(['active' => null])
        ->and($activated->after)->toBe(['active' => 'fake'])
        ->and(pgFake()->updated_by)->toBe($admin->id)
        ->and(pgFake()->is_active)->toBeTrue();
});

it('encrypts credentials at rest and keeps them out of every serialisation and the audit rows', function () {
    $stripe = pgStripe();
    $stripe->credentials = ['publishable_key' => 'pk_test_leak_probe_1', 'secret_key' => 'sk_test_leak_probe_2', 'webhook_secret' => 'whsec_leak_probe_3'];
    $stripe->save();

    $raw = (string) DB::table('payment_gateways')->where('code', 'stripe')->value('credentials');

    expect($raw)->not->toContain('leak_probe')
        ->and(json_encode($stripe->fresh()->toArray()))->not->toContain('leak_probe')
        ->and($stripe->fresh()->toJson())->not->toContain('leak_probe')
        ->and((string) $stripe->fresh())->not->toContain('leak_probe')
        ->and(json_encode(PaymentGatewayAccount::query()->get()->toArray()))->not->toContain('leak_probe');

    $admin = User::factory()->admin()->create();

    app(DeactivatePaymentGateway::class)(pgFake(), $admin);

    expect(json_encode(AuditLog::query()->get()->toArray()))->not->toContain('leak_probe');
});
