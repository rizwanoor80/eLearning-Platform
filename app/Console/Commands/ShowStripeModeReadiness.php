<?php

namespace App\Console\Commands;

use App\Enums\PaymentGatewayMode;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\Payments\StripeModeReadiness;
use Illuminate\Console\Command;

/**
 * R266 (4): the one-line check before the Stripe mode is switched. It counts the gateway references of each mode and
 * what the switch to the other mode would leave behind. Read-only: it changes nothing and never calls Stripe.
 */
class ShowStripeModeReadiness extends Command
{
    protected $signature = 'payments:mode-readiness {--target= : test or live; default is the mode Stripe is not in now}';

    protected $description = 'Count Stripe references by mode and what switching the Stripe mode would leave behind (read-only)';

    public function handle(PaymentGatewayManager $gateways, StripeModeReadiness $readiness): int
    {
        $active = $gateways->activeRow();
        $current = $active?->code === 'stripe' ? $active->mode : null;
        $option = $this->option('target');
        $target = $option === null
            ? ($current === PaymentGatewayMode::Live ? PaymentGatewayMode::Test : PaymentGatewayMode::Live)
            : PaymentGatewayMode::tryFrom((string) $option);

        if ($target === null) {
            $this->error('The target must be test or live.');

            return self::INVALID;
        }

        $r = $readiness->for($target);

        $this->line(sprintf(
            'Stripe is %s; cards test %d / live %d; payments test %d / live %d.',
            $current->value ?? 'not active',
            $r['cards']['test'], $r['cards']['live'], $r['payments']['test'], $r['payments']['live'],
        ));
        $this->line(sprintf(
            'Switching to %s: %d cards will need adding again, %d active weekly slots affected, %d payments pending, %d refunds still open on the mode being left.',
            $target->value, $r['unusable_cards'], $r['slots_needing_new_card'], $r['pending_payments'], $r['open_refunds'],
        ));
        $this->line($r['pending_payments'] === 0 && $r['open_refunds'] === 0 ? 'Nothing is in flight.' : 'Wait until nothing is in flight before switching.');

        return self::SUCCESS;
    }
}
