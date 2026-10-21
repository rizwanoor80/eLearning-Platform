<?php

namespace App\Actions\Payments;

use App\Actions\RecordAuditLog;
use App\Enums\PaymentRefundKind;
use App\Enums\PaymentRefundStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Events\Payments\PaymentNeedsAttention;
use App\Exceptions\PaymentGatewayUnavailable;
use App\Exceptions\RefundRequeueException;
use App\Jobs\Payments\ProcessPaymentRefund;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Services\Payments\NoActivePaymentGateway;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\StripePaymentGateway;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\ApiErrorException;

/**
 * 24b (R260): an admin asks again for a refund the gateway refused. It is a new attempt (`attempt` + 1) with its own
 * idempotency key, never the old row reopened: the failed row stays as the record of what the gateway said. Only the
 * latest attempt of an entry (or, for a capture the platform never held, of the payment) that failed can be re-queued.
 *
 * The amount is capped at what the gateway can still return: what was received, less every refund that succeeded
 * (including one made at the gateway by someone else, found by asking the gateway first), every attempt still in flight, and the payment's own `refunded_amount`. Nothing left means the
 * new attempt is recorded `failed` (`exceeds_remaining`) and nothing is sent. The ledger is never touched.
 *
 * Lock order: the payment row, then the attempts.
 */
class RequeueFailedRefund
{
    public const EXCEEDS_REMAINING = 'exceeds_remaining';

    public function __construct(
        private readonly RecordAuditLog $recordAuditLog,
        private readonly RecordExternalRefunds $recordExternalRefunds,
        private readonly PaymentGatewayManager $gateways,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws RefundRequeueException
     */
    public function __invoke(User $admin, PaymentRefund $refund): PaymentRefund
    {
        if ($admin->role !== Role::Admin || $admin->status !== UserStatus::Active) {
            throw new AuthorizationException('Only an active admin can re-queue a refund.');
        }

        // A refund made at the gateway whose webhook is late or lost would not be in the cap below, so the gateway is
        // asked first (with no lock held) and anything the platform did not make is recorded.
        $payment = Payment::query()->findOrFail($refund->payment_id);

        // R266: a payment made in the other Stripe mode cannot be refunded on this account, so asking again would only
        // fail again and loop.
        try {
            $mode = StripePaymentGateway::modeOf($this->gateways->forCode($payment->gateway));
        } catch (NoActivePaymentGateway $e) {
            throw new RefundRequeueException('The payment gateway is not available, so this refund cannot be asked for again.', 0, $e);
        }

        if ($mode !== null && $payment->gatewayMode() !== $mode) {
            throw new RefundRequeueException('This payment was made in Stripe '.$payment->gatewayMode()?->value.' mode, which is not the mode Stripe is in now, so it cannot be refunded here. Refund it in the Stripe dashboard of that mode.');
        }

        try {
            $recorded = ($this->recordExternalRefunds)($payment);
        } catch (ApiErrorException|PaymentGatewayUnavailable|NoActivePaymentGateway $e) {
            throw new RefundRequeueException('The gateway could not be reached to check what it has already refunded. Try again shortly.', 0, $e);
        }

        // Money that left at the gateway outside the platform is the admins' decision, so a refund found here is said
        // aloud exactly as the webhook's reconcile job would have (that job will now find nothing new to record).
        if ($recorded !== null && $recorded > 0) {
            PaymentNeedsAttention::dispatch($payment, PaymentNeedsAttention::REFUNDED_AT_GATEWAY);
        }

        $new = DB::transaction(function () use ($admin, $refund): PaymentRefund {
            $payment = Payment::query()->whereKey($refund->payment_id)->lockForUpdate()->firstOrFail();
            $failed = PaymentRefund::query()->whereKey($refund->getKey())->lockForUpdate()->firstOrFail();

            if ($failed->kind === PaymentRefundKind::External || $failed->status !== PaymentRefundStatus::Failed) {
                throw new RefundRequeueException('Only a refund the gateway refused can be asked for again.');
            }

            $latest = PaymentRefund::query()
                ->where('payment_id', $payment->id)
                ->where('kind', $failed->kind->value)
                ->when($failed->ledger_entry_id !== null, fn ($q) => $q->where('ledger_entry_id', $failed->ledger_entry_id))
                ->max('attempt');

            if ((int) $latest !== $failed->attempt) {
                throw new RefundRequeueException('A newer attempt for this refund already exists.');
            }

            $attempt = $failed->attempt + 1;
            $remaining = $this->remaining($payment);
            $fils = min($failed->amount->toFils(), $remaining);

            $new = PaymentRefund::query()->create([
                'payment_id' => $payment->id,
                'ledger_entry_id' => $failed->ledger_entry_id,
                'kind' => $failed->kind,
                'attempt' => $attempt,
                'idempotency_key' => $this->keyFor($payment, $failed, $attempt),
                'amount' => $fils > 0 ? $fils : $failed->amount->toFils(),
                'status' => $fils > 0 ? PaymentRefundStatus::Requested : PaymentRefundStatus::Failed,
                'failure_reason' => $fils > 0 ? null : self::EXCEEDS_REMAINING,
                'requested_at' => now(),
                'completed_at' => $fils > 0 ? null : now(),
            ]);

            ($this->recordAuditLog)($admin, 'payment_refund.requeued', $new, ['refund_id' => $failed->id, 'status' => PaymentRefundStatus::Failed->value], [
                'attempt' => $attempt,
                'amount_fils' => $new->amount->toFils(),
                'status' => $new->status->value,
            ]);

            return $new;
        });

        if ($new->status === PaymentRefundStatus::Requested) {
            ProcessPaymentRefund::dispatch($new->id)->afterCommit();
        }

        return $new;
    }

    /** What the gateway can still return on this payment, in fils. */
    private function remaining(Payment $payment): int
    {
        $received = $payment->raw_response['amount_received'] ?? null;
        $paid = is_int($received) ? min($received, $payment->amount->toFils()) : $payment->amount->toFils();

        $succeeded = (int) PaymentRefund::query()
            ->where('payment_id', $payment->id)
            ->whereIn('status', [PaymentRefundStatus::Succeeded->value, PaymentRefundStatus::Requested->value])
            ->sum('amount');

        $refunded = max($succeeded, $payment->refunded_amount?->toFils() ?? 0);

        return max(0, $paid - $refunded);
    }

    private function keyFor(Payment $payment, PaymentRefund $failed, int $attempt): string
    {
        return $failed->kind === PaymentRefundKind::Ledger
            ? "refund-{$payment->gateway_ref}-{$failed->ledger_entry_id}-a{$attempt}"
            : "unverified-{$payment->gateway_ref}-a{$attempt}";
    }
}
