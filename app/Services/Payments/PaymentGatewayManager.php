<?php

namespace App\Services\Payments;

use App\Enums\PaymentGatewayCode;
use App\Enums\PaymentGatewayMode;
use App\Models\PaymentGatewayAccount;
use App\Providers\PaymentGatewayServiceProvider;

/**
 * Resolves a `PaymentGateway` driver from the `payment_gateways` registry (invariant 16) — never
 * hard-wired. It holds no state: every call reads the active row, so a long-lived queue worker
 * stops charging through a gateway the moment an admin deactivates it.
 *
 * `forRow()` re-checks the fake environment allow-list (R131, ADR-022) and the owner-only live
 * flag independently of `PaymentGatewayAccount`'s saving guard: that guard only runs on a save, so
 * a row written by a raw update, or one that was active before the environment or the flag
 * changed, would otherwise still resolve. Every resolution path funnels through it.
 */
class PaymentGatewayManager
{
    public function activeRow(): ?PaymentGatewayAccount
    {
        return PaymentGatewayAccount::query()->active()->first();
    }

    /**
     * Whether an active row resolves to a usable driver. Booking, the weekly charge job and the
     * test-card screen ask this instead of whether the interface is bound.
     */
    public function available(): bool
    {
        return $this->usableRow() !== null;
    }

    /**
     * True when the gateway that would take a payment right now is in test mode; the "Test mode"
     * banner and notices follow it.
     */
    public function testMode(): bool
    {
        return $this->usableRow()?->mode === PaymentGatewayMode::Test;
    }

    /**
     * The active row, but only if it resolves to a driver here (the fake gateway outside its
     * environments, live without the flag and a code with no driver all return null).
     */
    public function usableRow(): ?PaymentGatewayAccount
    {
        $row = $this->activeRow();

        if ($row === null) {
            return null;
        }

        try {
            $this->forRow($row);
        } catch (NoActivePaymentGateway) {
            return null;
        }

        return $row;
    }

    /**
     * @throws NoActivePaymentGateway
     */
    public function active(): PaymentGateway
    {
        $row = $this->activeRow();

        if ($row === null) {
            throw new NoActivePaymentGateway('No payment gateway is active. An admin must activate one under Payment gateways.');
        }

        return $this->forRow($row);
    }

    /**
     * @throws NoActivePaymentGateway
     */
    public function forCode(string $code): PaymentGateway
    {
        $row = PaymentGatewayAccount::query()->where('code', $code)->first();

        if ($row === null) {
            throw new NoActivePaymentGateway("No payment gateway is registered as {$code}.");
        }

        return $this->forRow($row);
    }

    /**
     * @throws NoActivePaymentGateway
     */
    public function forRow(PaymentGatewayAccount $row): PaymentGateway
    {
        $code = $row->gatewayCode();

        if ($code === PaymentGatewayCode::Fake && ! PaymentGatewayServiceProvider::fakeGatewayAllowed()) {
            throw new NoActivePaymentGateway('The fake gateway cannot run outside local, testing and rehearsal.');
        }

        if ($row->mode === PaymentGatewayMode::Live && ($problem = $row->liveModeProblem()) !== null) {
            throw new NoActivePaymentGateway($problem);
        }

        return match ($code) {
            PaymentGatewayCode::Fake => new FakePaymentGateway,
            default => throw new NoActivePaymentGateway("There is no driver for {$row->code}."),
        };
    }
}
