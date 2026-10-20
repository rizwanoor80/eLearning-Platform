<?php

namespace App\Providers;

use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\Payments\CheckoutIntent;
use Illuminate\Support\ServiceProvider;

/**
 * Binds `PaymentGateway` to whichever driver the active `payment_gateways` row names (invariant 16,
 * R246). The binding is not a singleton and the manager caches nothing: each resolution reads the
 * registry, so deactivating a row takes effect for the next charge even in a long-lived worker.
 * With no active row, or a row that may not run here, resolving throws `NoActivePaymentGateway`;
 * callers that must degrade ask `PaymentGatewayManager::available()` first.
 *
 * `FAKE_ENVIRONMENTS` is the allow-list for the fake gateway and the fake video provider (R107,
 * ADR-016, R131): every environment not named, `production` above all, a typo and a missing
 * `APP_ENV` included, fails closed.
 */
class PaymentGatewayServiceProvider extends ServiceProvider
{
    /**
     * @var list<string>
     */
    public const FAKE_ENVIRONMENTS = ['local', 'testing', 'rehearsal'];

    /**
     * Whether this environment may run the fake gateway: the registry refuses to activate or
     * resolve the fake row elsewhere, and `SaveTestCard::available()` needs it for the test-card
     * screen.
     */
    public static function fakeGatewayAllowed(): bool
    {
        return app()->environment(self::FAKE_ENVIRONMENTS);
    }

    public function register(): void
    {
        $this->app->scoped(CheckoutIntent::class);
        $this->app->bind(PaymentGateway::class, fn ($app): PaymentGateway => $app->make(PaymentGatewayManager::class)->active());
    }
}
