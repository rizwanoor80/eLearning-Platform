<?php

namespace App\Actions\Payments;

use App\Enums\LessonStatus;
use App\Enums\PaymentStatus;
use App\Events\Payments\UnverifiedCaptureNeedsRefund;
use App\Exceptions\PaymentGatewayUnavailable;
use App\Models\Lesson;
use App\Models\Payment;
use App\Services\Lessons\LessonStateMachine;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\StripePaymentGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Throwable;

/**
 * R259 (5): a booking records its payment as `pending` before it asks the gateway to capture, so a
 * request that dies in between (or one that could not reach the gateway, `gateway_unavailable`) leaves a
 * payment with no `gateway_ref` that `ledger:verify` would report as stranded for ever. This finds those
 * rows after a delay, asks the gateway what became of the PaymentIntent the booking was using (kept in
 * `raw_response.checkout_intent`) and settles the row:
 *
 * - the intent was captured and another payment already carries it: this row is superseded (a retry won);
 * - the intent was captured and nothing else carries it: it is recorded here and refunded through
 *   `RefundUnverifiedCapture`, the lesson having been expired;
 * - the intent was an open hold: it is cancelled, so the card is released;
 * - anything else: the attempt failed.
 *
 * A row is left alone while a newer payment on the same intent is young (a retry may be running).
 * Idempotent: a settled row gets a new `failure_reason` and is not picked again.
 */
class ReconcileUnrecordedPayments
{
    /** A booking request that has not finished in this long is dead. */
    public const AFTER_MINUTES = 30;

    public const REASON_SUPERSEDED = 'superseded';

    public const REASON_RECONCILED = 'reconciled';

    public function __construct(private readonly PaymentGatewayManager $gateways) {}

    /**
     * @return int how many payments were settled
     */
    public function __invoke(): int
    {
        $settled = 0;

        $this->candidates()->each(function (Payment $payment) use (&$settled): void {
            try {
                $settled += $this->settle($payment) ? 1 : 0;
            } catch (PaymentGatewayUnavailable) {
                // Still unreachable: the next sweep asks again.
            } catch (Throwable $e) {
                // One bad row (a lost race on the reference, no active gateway) must not stop the batch or
                // the refund sweep that follows it; it is reported and the next sweep tries again.
                report($e);
            }
        });

        return $settled;
    }

    /**
     * @return LazyCollection<int, Payment>
     */
    private function candidates(): LazyCollection
    {
        return Payment::query()
            ->whereNull('gateway_ref')
            ->whereRaw("jsonb_exists(raw_response::jsonb, 'checkout_intent')")
            ->where('created_at', '<=', now()->subMinutes(self::AFTER_MINUTES))
            ->where(function ($query): void {
                $query->where('status', PaymentStatus::Pending->value)
                    ->orWhere(fn ($q) => $q->where('status', PaymentStatus::Failed->value)->where('failure_reason', PaymentGatewayUnavailable::REASON));
            })
            ->orderBy('id')
            ->lazyById();
    }

    private function settle(Payment $payment): bool
    {
        $intentId = $payment->raw_response['checkout_intent'] ?? null;
        $gateway = $this->gateways->forCode($payment->gateway);

        if (! is_string($intentId) || ! $gateway instanceof StripePaymentGateway) {
            return false;
        }

        if ($this->retryIsYoung($payment, $intentId)) {
            return false;
        }

        $state = $gateway->settleStrandedIntent($intentId, (string) $payment->payer_user_id);

        $refund = DB::transaction(function () use ($payment, $intentId, $state): bool {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->gateway_ref !== null) {
                return false;
            }

            if ($state['status'] !== 'succeeded') {
                $locked->update(['status' => PaymentStatus::Failed, 'failure_reason' => self::REASON_RECONCILED]);

                return false;
            }

            $owned = Payment::query()->where('gateway', $locked->gateway)->where('gateway_ref', $intentId)->exists();

            if ($owned) {
                $locked->update(['status' => PaymentStatus::Failed, 'failure_reason' => self::REASON_SUPERSEDED]);

                return false;
            }

            $raw = $locked->raw_response ?? [];
            $raw['amount_received'] = $state['amount_received'];
            $locked->update(['gateway_ref' => $intentId, 'status' => PaymentStatus::Captured, 'failure_reason' => null, 'raw_response' => $raw]);

            return true;
        });

        if ($refund) {
            $this->expire($payment->lesson_id);
            UnverifiedCaptureNeedsRefund::dispatch($payment->fresh() ?? $payment);
        }

        return true;
    }

    /** A newer payment on the same intent, made recently, means the parent is retrying. */
    private function retryIsYoung(Payment $payment, string $intentId): bool
    {
        return Payment::query()
            ->where('id', '>', $payment->id)
            ->where('created_at', '>', now()->subMinutes(self::AFTER_MINUTES))
            ->whereRaw("raw_response::jsonb->>'checkout_intent' = ?", [$intentId])
            ->exists();
    }

    private function expire(int $lessonId): void
    {
        $lesson = Lesson::query()->find($lessonId);

        if ($lesson === null || $lesson->status !== LessonStatus::PendingPayment) {
            return;
        }

        try {
            LessonStateMachine::transition($lesson, LessonStatus::Expired);
        } catch (Throwable) {
            // Moved on already: nothing left to undo.
        }
    }
}
