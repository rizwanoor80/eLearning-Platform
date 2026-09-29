<?php

namespace App\Actions\Lessons;

use App\Actions\RecordAuditLog;
use App\Enums\LessonCancelReason;
use App\Enums\LessonStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Exceptions\AttendanceException;
use App\Exceptions\LessonTransitionException;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use App\Services\Lessons\LessonSettlement;
use App\Services\Lessons\LessonStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * Admin only (PRD §7, CP8 task 2, R151): cancels a `reserved` or `confirmed` lesson the platform
 * itself would never have cancelled on its own — a wrong booking, a duplicate, or any other case an
 * admin judges should not go ahead. No strike either way; mirrors `CancelSuspendedTutorLessons`'s two
 * branches (that action's own reserved/confirmed shape, not `CancelLesson`'s, which carries strike
 * logic this action must not inherit) with a machine `cancel_reason` (`LessonCancelReason::Admin`)
 * instead of a copied free-text note — the admin's note goes only into the audit log.
 *
 * `reserved`: free, no ledger call (never charged) — refused if a `Payment` row for the lesson is
 * `pending` (an attempt may be mid-flight at the gateway right now, same guard
 * `CancelSlotReservedLessons`/`ChargeReservedLesson::begin()` already use) or `captured` with no hold
 * (the `ChargeReservedLesson::NeedsReview` outcome's own persisted shape — money already taken,
 * nothing here to reverse it against; `ledger:verify`'s existing stranded-payment check is the route
 * for that case, not this action).
 *
 * `confirmed`: full refund to the parent via `LessonSettlement::refundParent()` (mirrors
 * `MarkProviderFailure`'s ledger shape).
 *
 * Both branches require the lesson's `starts_at` to still be in the future (R151 is silent on
 * timing; `CancelLesson` already makes "past start = attendance matter" the one place that invariant
 * lives — every past-start case has its own named action: `MarkProviderFailure` and
 * `SettleEndedLesson::unattended()` for a `confirmed` lesson nobody joined, `ChargeWindowMissed` for a
 * `reserved` one, no cancel edge at all from `in_progress`).
 */
class ForceCancelLesson
{
    public function __construct(private LessonSettlement $settlement) {}

    /**
     * @throws AttendanceException
     * @throws LessonTransitionException
     */
    public function __invoke(User $admin, Lesson $lesson, string $note): Lesson
    {
        if ($admin->role !== Role::Admin || $admin->status !== UserStatus::Active) {
            throw new AttendanceException('Only an active admin may force-cancel a lesson.');
        }

        $note = trim($note);

        if ($note === '') {
            throw new AttendanceException('A note is required to force-cancel a lesson.');
        }

        if (! in_array($lesson->status, [LessonStatus::Reserved, LessonStatus::Confirmed], true)) {
            throw new AttendanceException('Only a reserved or confirmed lesson can be force-cancelled.');
        }

        return DB::transaction(function () use ($admin, $lesson, $note): Lesson {
            $before = $lesson->status->value;

            $lesson = LessonStateMachine::transition($lesson, LessonStatus::CancelledByTutor, function (Lesson $locked) use ($admin): void {
                if ($locked->starts_at->isPast()) {
                    throw new AttendanceException('This lesson has already started; it can no longer be force-cancelled.');
                }

                if ($locked->status === LessonStatus::Reserved) {
                    if ($this->hasBlockingPayment($locked)) {
                        throw new AttendanceException('This lesson has a payment attempt in flight or already captured; it cannot be force-cancelled here.');
                    }
                } else {
                    $this->settlement->refundParent($locked, $admin);
                }

                $locked->forceFill([
                    'cancelled_at' => now(),
                    'cancelled_by_user_id' => $admin->id,
                    'cancel_reason' => LessonCancelReason::Admin->value,
                ]);
            });

            app(RecordAuditLog::class)($admin, 'lesson.force_cancel', $lesson, ['status' => $before], ['status' => $lesson->status->value, 'note' => $note]);

            return $lesson;
        });
    }

    private function hasBlockingPayment(Lesson $lesson): bool
    {
        return Payment::query()
            ->where('lesson_id', $lesson->id)
            ->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Captured])
            ->exists();
    }
}
