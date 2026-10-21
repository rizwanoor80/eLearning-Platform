<?php

namespace App\Actions\Payments;

use App\Enums\PaymentStatus;
use App\Events\Payments\PaymentNeedsAttention;
use App\Exceptions\PaymentCaptureException;
use App\Models\Payment;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * R259 (2): a booking whose payment the gateway captured but which could not be confirmed (the capture
 * came back unclean, or the hold could not be written) leaves a `captured` payment with no ledger entry
 * at all. The platform never held that money, so there is nothing for the ledger to reverse
 * (`LedgerService` is untouched): the parent's card is refunded at the gateway and the payment row
 * records it, which also takes it off `ledger:verify`'s stranded list. Admins are told once it is done,
 * or when the gateway refuses.
 *
 * The state is a marker in `payments.raw_response` (`unverified_refund`), written before the gateway is
 * asked and finished after Stripe confirms; the payment is never set `refunded` first. The refund key is
 * `unverified-{gateway_ref}`, so the queue, the hourly sweep and an admin cannot refund twice, and the
 * marker also tells the `charge.refunded` webhook this refund is the platform's own. A refusal is
 * permanent and listed to admins; any other failure propagates so the queue retries.
 */
class RefundUnverifiedCapture
{
    public const MARKER = 'unverified_refund';

    public function __construct(private readonly PaymentGatewayManager $gateways) {}

    /**
     * @return bool whether this call made the refund
     */
    public function __invoke(Payment $payment): bool
    {
        $owed = DB::transaction(function () use ($payment): ?Money {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->first();

            if ($locked === null || $locked->gateway_ref === null || $locked->status !== PaymentStatus::Captured) {
                return null;
            }

            $raw = $locked->raw_response ?? [];

            if (in_array($raw[self::MARKER]['status'] ?? null, ['succeeded', 'refused'], true)) {
                return null;
            }

            // Any ledger entry means the platform holds (or held) this money and its own rules refund it.
            if (DB::table('ledger_entries')->where('lesson_id', $locked->lesson_id)->exists()) {
                return null;
            }

            $received = $raw['amount_received'] ?? null;
            $fils = (is_int($received) ? $received : $locked->amount->toFils()) - ($locked->refunded_amount?->toFils() ?? 0);

            if ($fils <= 0) {
                return null;
            }

            $raw[self::MARKER] = ['status' => 'requested', 'amount_fils' => $fils, 'at' => now()->toIso8601String()];
            $locked->update(['raw_response' => $raw]);

            return Money::fils($fils);
        });

        if ($owed === null) {
            return false;
        }

        try {
            $result = $this->gateways->forCode($payment->gateway)->refund($payment, $owed, "unverified-{$payment->gateway_ref}");
        } catch (PaymentCaptureException $e) {
            $this->record($payment, ['status' => 'refused', 'code' => $e->getMessage()], null);
            PaymentNeedsAttention::dispatch($payment->fresh() ?? $payment, PaymentNeedsAttention::REFUND_FAILED);

            return false;
        }

        $this->record($payment, ['status' => 'succeeded', 'id' => $result->gatewayRef], $owed);
        PaymentNeedsAttention::dispatch($payment->fresh() ?? $payment, PaymentNeedsAttention::UNVERIFIED_CAPTURE_REFUNDED);

        return true;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function record(Payment $payment, array $state, ?Money $refunded): void
    {
        DB::transaction(function () use ($payment, $state, $refunded): void {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $raw = $locked->raw_response ?? [];
            $raw[self::MARKER] = [...$raw[self::MARKER] ?? [], ...$state, 'at' => now()->toIso8601String()];
            $locked->raw_response = $raw;

            if ($refunded !== null) {
                $total = Money::fils(min(($locked->refunded_amount?->toFils() ?? 0) + $refunded->toFils(), $locked->amount->toFils()));
                $locked->refunded_amount = $total;
                $locked->status = PaymentStatus::Refunded;
            }

            $locked->save();
        });
    }
}
