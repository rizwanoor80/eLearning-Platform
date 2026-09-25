<?php

namespace App\Providers;

use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\PaymentGateway;
use Illuminate\Support\ServiceProvider;

/**
 * Binds `FakePaymentGateway` to `PaymentGateway` on development-like environments only (R107,
 * ADR-016) — a stopgap for invariant 16 until CP5's `payment_gateways` registry replaces it. It
 * is an allow-list: every environment not named below, `production` above all, a typo and a
 * missing `APP_ENV` included, leaves the interface unbound, so resolving it fails loudly.
 */
class PaymentGatewayServiceProvider extends ServiceProvider
{
    /**
     * @var list<string>
     */
    public const FAKE_ENVIRONMENTS = ['local', 'testing', 'rehearsal'];

    /**
     * Whether this environment runs on the fake gateway: it decides the binding, the "Test mode"
     * banner and whether a test card may be saved (`SaveTestCard::available()`).
     */
    public static function fakeGatewayAllowed(): bool
    {
        return app()->environment(self::FAKE_ENVIRONMENTS);
    }

    public function register(): void
    {
        if (self::fakeGatewayAllowed()) {
            $this->app->bind(PaymentGateway::class, FakePaymentGateway::class);
        }
    }
}
