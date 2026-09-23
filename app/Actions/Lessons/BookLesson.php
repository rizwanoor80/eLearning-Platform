<?php

namespace App\Actions\Lessons;

use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Enums\PaymentStatus;
use App\Exceptions\BookingException;
use App\Exceptions\LessonTransitionException;
use App\Exceptions\PaymentCaptureException;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\TutorProfile;
use App\Models\TutorSubject;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Lessons\LessonStateMachine;
use App\Services\Payments\PaymentGateway;
use App\Services\Scheduling\Slot;
use App\Services\Scheduling\SlotCalculator;
use App\Services\Tutors\TutorRateBands;
use App\Support\Facades\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * R53/R56: validates the slot, the tutor's bookability and band, and the
 * requested subject; decides trial vs regular from the learner-tutor pair's
 * history; freezes price and policy on the lesson row; captures payment and
 * moves the lesson to `confirmed` + HOLD, or to `expired` on a declined
 * capture. `pending_payment` -> `confirmed`/`expired` is the only edge
 * `LessonStateMachine` allows from here (invariant #2); the overlap and
 * one-trial-per-pair constraints are the database's (invariant #12), this
 * action only translates a constraint violation into a readable exception.
 */
class BookLesson
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * @param  array{curriculum_id: int, subject_id: int, starts_at: \DateTimeInterface}  $data
     *
     * @throws BookingException
     * @throws PaymentCaptureException
     */
    public function __invoke(User $bookedBy, Learner $learner, TutorProfile $tutor, array $data): Lesson
    {
        if ($learner->trashed() || $learner->account_user_id !== $bookedBy->id) {
            throw new BookingException('A lesson can only be booked for one of your own learners.');
        }

        $freshTutor = TutorProfile::query()->bookable()->whereKey($tutor->id)->first();

        if ($freshTutor === null) {
            throw new BookingException('This tutor is not currently bookable.');
        }

        if (($problem = app(TutorRateBands::class)->problemWithRate($freshTutor)) !== null) {
            throw new BookingException("This tutor cannot be booked right now: {$problem}.");
        }

        $teachesSubject = TutorSubject::query()
            ->where('tutor_profile_id', $freshTutor->id)
            ->where('curriculum_id', $data['curriculum_id'])
            ->where('subject_id', $data['subject_id'])
            ->exists();

        if (! $teachesSubject) {
            throw new BookingException('This tutor does not teach the requested curriculum and subject.');
        }

        $startsAt = Carbon::instance($data['starts_at'])->utc();

        $slots = app(SlotCalculator::class)->forTutor($freshTutor, 'UTC');
        $slot = collect($slots)->first(
            fn (Slot $candidate): bool => $candidate->startsAt->utc()->getTimestamp() === $startsAt->getTimestamp()
        );

        if ($slot === null) {
            throw new BookingException('The requested slot is no longer available.');
        }

        $endsAt = $slot->endsAt->utc();

        $isTrial = ! Lesson::query()
            ->where('learner_id', $learner->id)
            ->where('tutor_profile_id', $freshTutor->id)
            ->whereNotIn('status', LessonStatus::freeingSlotValues())
            ->exists();

        $price = $isTrial ? $freshTutor->trialPrice() : $freshTutor->hourly_rate;

        if ($price === null) {
            throw new BookingException('This tutor has no hourly rate set.');
        }

        $commissionPct = (int) Settings::get('commission_pct');
        $split = $price->splitCommission($commissionPct);

        $attributes = [
            'type' => $isTrial ? LessonType::Trial : LessonType::Regular,
            'learner_id' => $learner->id,
            'tutor_profile_id' => $freshTutor->id,
            'booked_by_user_id' => $bookedBy->id,
            'curriculum_id' => $data['curriculum_id'],
            'subject_id' => $data['subject_id'],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'duration_minutes' => 60,
            'price' => $price,
            'commission_pct' => $commissionPct,
            'commission_amount' => $split['commission'],
            'tutor_amount' => $split['tutor'],
            'cancel_window_hours' => (int) Settings::get('cancel_window_hours'),
            'student_grace_min' => (int) Settings::get('student_grace_min'),
            'tutor_grace_min' => (int) Settings::get('tutor_grace_min'),
        ];

        try {
            $lesson = LessonStateMachine::open($attributes, LessonStatus::PendingPayment);
        } catch (QueryException $e) {
            throw $this->translateConstraintViolation($e);
        }

        try {
            $result = $this->gateway->capture($lesson, $price, (string) $lesson->id);
        } catch (PaymentCaptureException $e) {
            Payment::query()->create([
                'lesson_id' => $lesson->id,
                'payer_user_id' => $bookedBy->id,
                'gateway' => $this->gateway->driver(),
                'gateway_ref' => null,
                'amount' => $price,
                'status' => PaymentStatus::Failed,
                'failure_reason' => $e->getMessage(),
            ]);

            try {
                LessonStateMachine::transition($lesson, LessonStatus::Expired);
            } catch (LessonTransitionException) {
                // Already swept to `expired` (or otherwise moved on) — nothing left to undo.
            }

            throw $e;
        }

        Payment::query()->create([
            'lesson_id' => $lesson->id,
            'payer_user_id' => $bookedBy->id,
            'gateway' => $this->gateway->driver(),
            'gateway_ref' => $result->gatewayRef,
            'amount' => $price,
            'status' => PaymentStatus::Captured,
            'raw_response' => $result->rawResponse,
        ]);

        try {
            $lesson = LessonStateMachine::transition(
                $lesson,
                LessonStatus::Confirmed,
                fn (Lesson $locked) => app(LedgerService::class)->hold($locked, $bookedBy),
            );
        } catch (LessonTransitionException $e) {
            throw new BookingException(
                "Payment was captured but lesson {$lesson->id} could no longer be confirmed (it was likely already expired by the unpaid sweep); this needs manual review.",
                previous: $e,
            );
        }

        return $lesson;
    }

    private function translateConstraintViolation(QueryException $e): BookingException
    {
        $message = $e->getMessage();

        if (str_contains($message, 'lessons_tutor_no_overlap')) {
            return new BookingException('This slot now overlaps another booking for the tutor.', previous: $e);
        }

        if (str_contains($message, 'lessons_tutor_slot_unique')) {
            return new BookingException('This slot was just booked by someone else.', previous: $e);
        }

        if (str_contains($message, 'lessons_one_trial_per_pair')) {
            return new BookingException('This learner already has a trial lesson booked with this tutor.', previous: $e);
        }

        throw $e;
    }
}
