<?php

namespace App\Actions\Payments;

use App\Actions\RecordAuditLog;
use App\Actions\RecurringSlots\ChargeReservedLesson;
use App\Enums\LessonStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Events\Payments\UnverifiedCaptureNeedsRefund;
use App\Exceptions\OrphanedPaymentException;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Payments\ActiveStripe;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent;
use Throwable;

/**
 * 25c (R268): an admin settles a weekly-lesson charge attempt that was left `pending` for good. `ChargeReservedLesson`
 * writes the row before it calls the gateway and only closes it on a result; if the call dies without one (an outage,
 * a crash) and the lesson then leaves `reserved` (a parent skips it), nothing ever resumes the row, and it blocks the
 * parent's card from being removed (`RemoveSavedCard`). A booking's own pending payment carries no saved card, so this
 * never touches one.
 *
 * It never charges again. For Stripe it only reads: the customer's intents since the attempt began, matched by the
 * attempt's idempotency key (as `StripePaymentGateway` finds an intent it made earlier), then by what Stripe says:
 *  - succeeded: the money was taken for a lesson that no longer exists. The payment is recorded `captured` and the
 *    existing unverified-capture refund (R259/R260) gives it back; the ledger is never involved.
 *  - processing, or Stripe cannot be asked: refused, the row stays pending.
 *  - anything else, or no intent at all: no money is held, the attempt is closed `failed`.
 * A payment of another gateway (the fake) holds no real money and is closed `failed`.
 *
 * Only a payment older than the reconcile grace period, on a lesson that is not reserved and has no ledger entry, and
 * whose Stripe mode is the active one, is eligible. Audited with the admin's note; no card or parent detail.
 */
class ReleaseOrphanedPendingPayment
{
    public const REASON = 'orphan_released';

    /** The `kind` `StripePaymentGateway` writes onto a weekly charge's intent (private there; the driver is frozen). */
    private const KIND_RECURRING = 'recurring';

    public function __construct(
        private readonly RecordAuditLog $audit,
        private readonly ActiveStripe $stripe,
    ) {}

    /** Whether a payment looks like an orphan, from what is already loaded (cheap enough for a table row). */
    public static function looksOrphaned(?Payment $payment, LessonStatus $lessonStatus): bool
    {
        return $payment !== null
            && $payment->status === PaymentStatus::Pending
            && $payment->payment_method_id !== null
            && $lessonStatus !== LessonStatus::Reserved
            && $payment->created_at->lessThanOrEqualTo(now()->subMinutes(ReconcileUnrecordedPayments::AFTER_MINUTES));
    }

    /**
     * @return string `refund_started` or `failed`
     *
     * @throws OrphanedPaymentException
     */
    public function __invoke(User $admin, Payment $payment, string $note): string
    {
        if ($admin->role !== Role::Admin) {
            throw new OrphanedPaymentException('Only an admin can release a pending payment.');
        }

        $payment = Payment::query()->with('lesson')->findOrFail($payment->id);
        $this->assertEligible($payment);

        $outcome = $payment->gateway === 'stripe' ? $this->askStripe($payment) : null;

        $captured = DB::transaction(function () use ($admin, $payment, $note, $outcome): ?Payment {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $lesson = $locked->lesson()->lockForUpdate()->firstOrFail();

            // Someone else settled it, or the lesson came back to life, while the gateway was being asked.
            if ($locked->status !== PaymentStatus::Pending || $lesson->status === LessonStatus::Reserved) {
                throw new OrphanedPaymentException('This payment has already been settled.');
            }

            $this->assertNoLedger($locked);

            if ($outcome instanceof PaymentIntent) {
                $locked->update([
                    'gateway_ref' => $outcome->id,
                    'status' => PaymentStatus::Captured,
                    'raw_response' => [
                        'driver' => 'stripe',
                        'id' => $outcome->id,
                        'status' => $outcome->status,
                        'amount' => $outcome->amount,
                        'amount_received' => $outcome->amount_received,
                        'currency' => $outcome->currency,
                    ],
                ]);
            } else {
                $locked->update(['status' => PaymentStatus::Failed, 'failure_reason' => self::REASON]);
            }

            ($this->audit)($admin, 'payment.orphan_released', $locked, ['status' => PaymentStatus::Pending->value], [
                'status' => $locked->status->value,
                'lesson_id' => $locked->lesson_id,
                'attempt_no' => $locked->attempt_no,
                'outcome' => $outcome instanceof PaymentIntent ? 'refund_started' : 'failed',
                'note' => $note,
            ]);

            return $outcome instanceof PaymentIntent ? $locked : null;
        });

        if ($captured !== null) {
            UnverifiedCaptureNeedsRefund::dispatch($captured);
        }

        return $captured !== null ? 'refund_started' : 'failed';
    }

    /**
     * @throws OrphanedPaymentException
     */
    private function assertEligible(Payment $payment): void
    {
        if (! self::looksOrphaned($payment, $payment->lesson->status)) {
            throw new OrphanedPaymentException('This payment is not a pending charge left behind on a lesson that is no longer reserved.');
        }

        $this->assertNoLedger($payment);
    }

    private function assertNoLedger(Payment $payment): void
    {
        // Any ledger entry means the platform holds, or held, this money and its own rules settle it.
        if (DB::table('ledger_entries')->where('lesson_id', $payment->lesson_id)->exists()) {
            throw new OrphanedPaymentException('This lesson has ledger entries; its money is settled by the ledger, not here.');
        }
    }

    /**
     * Reads Stripe only. Returns the succeeded intent, or null when no money is held.
     *
     * @throws OrphanedPaymentException when the answer cannot be had or is not final
     */
    private function askStripe(Payment $payment): ?PaymentIntent
    {
        $row = $this->stripe->row();

        if ($row === null) {
            throw new OrphanedPaymentException('Stripe is not the active payment gateway, so the attempt cannot be looked up.');
        }

        // R266: a reference from the other mode is never sent to this account.
        if ($row->mode !== $payment->gatewayMode()) {
            throw new OrphanedPaymentException('This payment was made in the other Stripe mode; switch back to look it up.');
        }

        $method = PaymentMethod::query()->find($payment->payment_method_id);

        if ($method === null || $method->gateway_customer_ref === null) {
            return null;
        }

        $key = ChargeReservedLesson::idempotencyKey($payment->lesson_id, $payment->attempt_no);

        try {
            $found = null;

            foreach ($this->stripe->client()->paymentIntents->all([
                'customer' => $method->gateway_customer_ref,
                'created' => ['gte' => max(0, $payment->created_at->copy()->subHour()->getTimestamp())],
                'limit' => 100,
            ])->autoPagingIterator() as $candidate) {
                $meta = $candidate->metadata;

                if (($meta['idempotency_key'] ?? null) === $key
                    || (($meta['kind'] ?? null) === self::KIND_RECURRING
                        && (string) ($meta['lesson_id'] ?? '') === (string) $payment->lesson_id
                        && (string) ($meta['attempt'] ?? '') === (string) $payment->attempt_no)) {
                    $found = $candidate;
                    break;
                }
            }
        } catch (InvalidRequestException $e) {
            // A deleted customer holds nothing.
            if ($e->getStripeCode() === 'resource_missing') {
                return null;
            }

            throw new OrphanedPaymentException('Stripe could not be asked right now. Try again in a few minutes.');
        } catch (Throwable) {
            throw new OrphanedPaymentException('Stripe could not be asked right now. Try again in a few minutes.');
        }

        if ($found === null) {
            return null;
        }

        return match ($found->status) {
            PaymentIntent::STATUS_SUCCEEDED => $found,
            PaymentIntent::STATUS_PROCESSING, PaymentIntent::STATUS_REQUIRES_CAPTURE => throw new OrphanedPaymentException('Stripe says this payment is still in progress. Try again later.'),
            default => null,
        };
    }
}
