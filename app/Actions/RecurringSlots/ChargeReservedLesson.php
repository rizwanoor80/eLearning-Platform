<?php

namespace App\Actions\RecurringSlots;

use App\Enums\ChargeOutcome;
use App\Enums\LessonCancelReason;
use App\Enums\LessonStatus;
use App\Enums\PaymentStatus;
use App\Enums\RecurringSlotPauseReason;
use App\Enums\RecurringSlotStatus;
use App\Events\Lessons\LessonChargeFailed;
use App\Exceptions\LedgerException;
use App\Exceptions\LessonTransitionException;
use App\Exceptions\PaymentCaptureException;
use App\Exceptions\RecurringSlotException;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\RecurringSlot;
use App\Models\TutorProfile;
use App\Services\Ledger\LedgerService;
use App\Services\Lessons\LessonStateMachine;
use App\Services\Payments\PaymentGateway;
use App\Support\Facades\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Charges one `reserved` weekly lesson from the parent's saved card (R101), in the same three
 * steps `BookLesson` uses, so a crash at any point leaves something `ledger:verify` can see:
 *
 * 1. Under the slot lock, then the lesson lock (the order `PauseRecurringSlot` and
 *    `EndRecurringSlot` use — slot first), re-check that the lesson is still `reserved`, due and
 *    ahead of its start and the slot `active`, and write a `pending` `payments` row for attempt
 *    `charge_attempts + 1`. `unique (lesson_id, attempt_no)` means two runs cannot both create it;
 *    a `pending` row left by a crashed run is picked up again with the same idempotency key.
 * 2. Call the gateway outside any database transaction, key `lesson:{id}:attempt:{n}`. Only a
 *    `PaymentCaptureException` is a decline; any other failure leaves the row `pending` for the
 *    next run (the same key makes the repeat safe).
 * 3. In one transaction record the result. Success: payment `captured`, `reserved -> confirmed`
 *    with the ledger HOLD, the slot's failure counter back to zero. Failure: payment `failed`, and
 *    either the next retry time or the final `cancelled_payment_failed`, the slot's counter +1 and,
 *    at `recurring_pause_after_failures`, a `payment_failed` pause. `charge_attempts` advances
 *    only here, once the attempt's result is recorded, so a second run finds nothing to do.
 *
 * A due lesson whose tutor is no longer `bookable()` is never charged (R104): `cancelled_by_tutor`
 * with `tutor_unavailable`, no strike, no ledger entry, the slot untouched.
 *
 * A lesson whose start has passed is never charged (the 4c carry): it is cancelled as
 * `cancelled_payment_failed` with `charge_window_missed`, and that does not count against the slot
 * (the platform's timing, not the parent's card).
 */
class ChargeReservedLesson
{
    public function __construct(private readonly PauseRecurringSlot $pause) {}

    public function __invoke(int $lessonId, ?PaymentGateway $gateway): ChargeOutcome
    {
        $begun = $this->begin($lessonId, $gateway);

        if ($begun instanceof ChargeOutcome) {
            return $begun;
        }

        [$paymentId, $attempt, $methodId] = $begun;
        $lesson = Lesson::query()->findOrFail($lessonId);
        $method = $methodId === null ? null : PaymentMethod::query()->find($methodId);

        if ($method === null || ! $method->isUsable()) {
            return $this->fail($lessonId, $paymentId, $attempt, null, 'no_usable_card');
        }

        try {
            $result = $gateway->chargeSavedCard($lesson, $lesson->price, $method, self::idempotencyKey($lessonId, $attempt));
        } catch (PaymentCaptureException $e) {
            return $this->fail($lessonId, $paymentId, $attempt, $method, $e->getMessage());
        }

        return $this->succeed($lessonId, $paymentId, $attempt, $method, $result->gatewayRef, $result->rawResponse);
    }

    /**
     * Derived from the lesson id (invariant 14) with the attempt number, so a real gateway's cached
     * result for a failed attempt cannot block the retry.
     */
    public static function idempotencyKey(int $lessonId, int $attempt): string
    {
        return "lesson:{$lessonId}:attempt:{$attempt}";
    }

    /**
     * @return array{0: int, 1: int, 2: int|null}|ChargeOutcome [payment id, attempt number, card id]
     */
    private function begin(int $lessonId, ?PaymentGateway $gateway): array|ChargeOutcome
    {
        return DB::transaction(function () use ($lessonId, $gateway): array|ChargeOutcome {
            $slotId = Lesson::query()->whereKey($lessonId)->value('recurring_slot_id');

            if ($slotId === null) {
                return ChargeOutcome::Skipped;
            }

            $slot = RecurringSlot::query()->whereKey($slotId)->lockForUpdate()->firstOrFail();
            $lesson = Lesson::query()->whereKey($lessonId)->lockForUpdate()->firstOrFail();

            if ($lesson->status !== LessonStatus::Reserved) {
                return ChargeOutcome::Skipped;
            }

            $due = ($lesson->next_charge_at !== null && ! $lesson->next_charge_at->isFuture()) || $lesson->starts_at->lessThanOrEqualTo(now());

            // R104, invariant 5: a lesson that falls due after its tutor stopped being bookable is never
            // charged. It is the tutor's side that failed, so it is `cancelled_by_tutor` with no actor and
            // no strike, and the slot stays active. This needs no gateway, so it runs before that check.
            // An attempt already in flight (a run that died after `begin()`) is left to finish first: the gateway
            // may have taken the money, and the same idempotency key returns that result, so confirming it puts
            // the money on the ledger where cancelling would strand it.
            if ($due && $slot->status === RecurringSlotStatus::Active && ! $this->hasPendingAttempt($lesson->id) && ! $this->tutorIsBookable($lesson->tutor_profile_id)) {
                LessonStateMachine::transition($lesson, LessonStatus::CancelledByTutor, function (Lesson $locked): void {
                    $locked->forceFill([
                        'next_charge_at' => null,
                        'cancelled_at' => now(),
                        'cancelled_by_user_id' => null,
                        'cancel_reason' => LessonCancelReason::TutorUnavailable->value,
                    ]);
                });

                return ChargeOutcome::TutorUnavailable;
            }

            if ($lesson->starts_at->lessThanOrEqualTo(now())) {
                LessonStateMachine::transition($lesson, LessonStatus::CancelledPaymentFailed, function (Lesson $locked): void {
                    $locked->forceFill([
                        'next_charge_at' => null,
                        'cancelled_at' => now(),
                        'cancelled_by_user_id' => null,
                        'cancel_reason' => LessonCancelReason::ChargeWindowMissed->value,
                    ]);
                });

                return ChargeOutcome::Missed;
            }

            if ($gateway === null || $slot->status !== RecurringSlotStatus::Active || $lesson->next_charge_at === null || $lesson->next_charge_at->isFuture()) {
                return ChargeOutcome::Skipped;
            }

            $attempt = $lesson->charge_attempts + 1;
            $account = $lesson->learner->account_user_id;
            $method = PaymentMethod::query()->where('account_user_id', $account)->first();

            $payment = Payment::query()->where('lesson_id', $lesson->id)->where('attempt_no', $attempt)->first();

            if ($payment === null) {
                $payment = Payment::query()->create([
                    'lesson_id' => $lesson->id,
                    'attempt_no' => $attempt,
                    'payer_user_id' => $account,
                    'payment_method_id' => $method?->id,
                    'gateway' => $gateway->driver(),
                    'amount' => $lesson->price,
                    'status' => PaymentStatus::Pending,
                ]);
            } elseif ($payment->status !== PaymentStatus::Pending) {
                return ChargeOutcome::Skipped;
            }

            return [$payment->id, $attempt, $method?->id];
        });
    }

    /**
     * Invariant 5: the one place a tutor is judged chargeable is the `bookable()` scope, read at charge time.
     */
    private function hasPendingAttempt(int $lessonId): bool
    {
        return Payment::query()->where('lesson_id', $lessonId)->where('status', PaymentStatus::Pending)->exists();
    }

    private function tutorIsBookable(int $tutorProfileId): bool
    {
        return TutorProfile::query()->bookable()->whereKey($tutorProfileId)->exists();
    }

    /**
     * @param  array<string, mixed>  $rawResponse
     */
    private function succeed(int $lessonId, int $paymentId, int $attempt, PaymentMethod $method, string $gatewayRef, array $rawResponse): ChargeOutcome
    {
        try {
            return DB::transaction(function () use ($lessonId, $paymentId, $attempt, $method, $gatewayRef, $rawResponse): ChargeOutcome {
                $slotId = Lesson::query()->whereKey($lessonId)->value('recurring_slot_id');
                $slot = RecurringSlot::query()->whereKey($slotId)->lockForUpdate()->firstOrFail();
                $payment = Payment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();

                if ($payment->status !== PaymentStatus::Pending) {
                    return ChargeOutcome::Skipped;
                }

                $lesson = Lesson::query()->findOrFail($lessonId);

                LessonStateMachine::transition($lesson, LessonStatus::Confirmed, function (Lesson $locked) use ($payment, $attempt, $method, $gatewayRef, $rawResponse): void {
                    $payment->update(['gateway_ref' => $gatewayRef, 'status' => PaymentStatus::Captured, 'raw_response' => $rawResponse]);

                    $locked->forceFill(['charge_attempts' => $attempt, 'next_charge_at' => null, 'payment_method_id' => $method->id]);

                    app(LedgerService::class)->hold($locked, null);
                });

                $slot->forceFill(['consecutive_charge_failures' => 0])->save();

                return ChargeOutcome::Charged;
            });
        } catch (LessonTransitionException|LedgerException $e) {
            // The gateway already took the money but the lesson can no longer be confirmed (a parent
            // skipped it, or the slot was ended, between the two steps). There is no refund path until
            // CP5, so record what happened and leave `ledger:verify` (captured, no hold) to flag it.
            Payment::query()->whereKey($paymentId)->where('status', PaymentStatus::Pending)->first()
                ?->update(['gateway_ref' => $gatewayRef, 'status' => PaymentStatus::Captured, 'raw_response' => $rawResponse]);

            report(new RuntimeException("Weekly lesson {$lessonId} was charged (payment {$paymentId}) but could not be confirmed; this needs manual review.", previous: $e));

            return ChargeOutcome::NeedsReview;
        }
    }

    private function fail(int $lessonId, int $paymentId, int $attempt, ?PaymentMethod $method, string $reason): ChargeOutcome
    {
        return DB::transaction(function () use ($lessonId, $paymentId, $attempt, $method, $reason): ChargeOutcome {
            $slotId = Lesson::query()->whereKey($lessonId)->value('recurring_slot_id');
            $slot = RecurringSlot::query()->whereKey($slotId)->lockForUpdate()->firstOrFail();
            $lesson = Lesson::query()->whereKey($lessonId)->lockForUpdate()->firstOrFail();
            $payment = Payment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();

            if ($payment->status !== PaymentStatus::Pending) {
                return ChargeOutcome::Skipped;
            }

            $payment->update(['status' => PaymentStatus::Failed, 'failure_reason' => $reason]);

            // A card that was never usable is not a decline by the bank: only a real decline is remembered.
            $method?->forceFill(['last_failed_at' => now()])->save();

            if ($lesson->status !== LessonStatus::Reserved) {
                return ChargeOutcome::Skipped;
            }

            $retryAt = $this->nextChargeAt($lesson, $attempt);

            if ($retryAt !== null) {
                $lesson->forceFill(['charge_attempts' => $attempt, 'next_charge_at' => $retryAt])->save();

                DB::afterCommit(fn () => LessonChargeFailed::dispatch($lesson, $retryAt));

                return ChargeOutcome::RetryScheduled;
            }

            LessonStateMachine::transition($lesson, LessonStatus::CancelledPaymentFailed, function (Lesson $locked) use ($attempt): void {
                $locked->forceFill([
                    'charge_attempts' => $attempt,
                    'next_charge_at' => null,
                    'cancelled_at' => now(),
                    'cancelled_by_user_id' => null,
                ]);
            });

            $slot->forceFill(['consecutive_charge_failures' => $slot->consecutive_charge_failures + 1])->save();

            if ($slot->status === RecurringSlotStatus::Active && $slot->consecutive_charge_failures >= (int) Settings::get('recurring_pause_after_failures')) {
                try {
                    ($this->pause)(null, $slot, RecurringSlotPauseReason::PaymentFailed);
                } catch (RecurringSlotException) {
                    // Already paused or ended by someone else under the same lock order: nothing left to pause.
                }
            }

            return ChargeOutcome::Cancelled;
        });
    }

    /**
     * The next attempt's time, or null when that was the last one. Attempt 1 ran at the charge lead
     * (`starts_at - recurring_charge_lead_hours`); after attempt `n` fails the next runs at
     * `starts_at - recurring_retry_hours[n-1]` (36h, then 24h). A late run never fires two attempts
     * in the same hour: the next attempt is at least an hour away, and one that would not land
     * before the lesson starts is not made — the failure is final.
     */
    private function nextChargeAt(Lesson $lesson, int $attempt): ?CarbonImmutable
    {
        $retryHours = array_values(array_filter(array_map('intval', (array) Settings::get('recurring_retry_hours')), fn (int $hours): bool => $hours > 0));
        rsort($retryHours);

        if ($attempt > count($retryHours)) {
            return null;
        }

        $startsAt = $lesson->starts_at->toImmutable();
        $next = $startsAt->subHours($retryHours[$attempt - 1])->max(now()->toImmutable()->addHour());

        return $next->lessThan($startsAt) ? $next : null;
    }
}
