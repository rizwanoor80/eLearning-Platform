<?php

namespace App\Actions\Lessons;

use App\Actions\RecordAuditLog;
use App\Enums\DisputeStatus;
use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Exceptions\DisputeException;
use App\Exceptions\LessonTransitionException;
use App\Models\Dispute;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Lessons\LessonStateMachine;

/**
 * CP8 (R150): an admin resolves an open dispute with two independent dials (parent-refund %,
 * tutor-pay % — invariant 13, never one derived from the other) plus a required note. Per the 9b
 * design consult's own point 5 ("resolve runs `settle()` + dispute update + audit row inside one
 * `transition(..., Settled, $work)`"): money moves only through `LedgerService::settle()`
 * (invariant 1), the lesson moves `disputed -> settled` only through
 * `LessonStateMachine::transition()` (invariant 2), and both happen — with the dispute row update
 * and the audit row — inside the single transaction/lock `transition()` itself opens on the
 * lesson row, so a failure at any point leaves nothing committed.
 *
 * The `disputed -> settled` edge assert fires before `$work` runs — the same ordering that let a
 * stale `OpenDispute` POST reach `DisputeController::store()` uncaught (CYCLE-LOG `03:09`). An
 * admin double-submitting an already-resolved dispute's form hits that same assert here (the
 * lesson is already `settled` by the first submit) and gets `LessonTransitionException`, not
 * `DisputeException` — so every caller must catch both, not just the latter. The dispute row's own
 * `status !== Open` check below is a second, clearer-message guard for the same race, inside the
 * lock, matching `OpenDispute::problemFor()`'s "clearer message before the lower-level guard"
 * precedent; `LedgerService::settle()`'s own `alreadySettled()` check is a third, ledger-side
 * guard, defense in depth against a divergent caller, not this action's own path.
 */
class ResolveDispute
{
    public function __construct(private LedgerService $ledger) {}

    /**
     * @throws DisputeException
     * @throws LessonTransitionException
     */
    public function __invoke(User $admin, Dispute $dispute, int $parentRefundPct, int $tutorPayPct, string $note): Lesson
    {
        if ($admin->role !== Role::Admin || $admin->status !== UserStatus::Active) {
            throw new DisputeException('Only an active admin may resolve a dispute.');
        }

        $note = trim($note);

        if ($note === '') {
            throw new DisputeException('A note is required to resolve a dispute.');
        }

        if ($parentRefundPct < 0 || $parentRefundPct > 100 || $tutorPayPct < 0 || $tutorPayPct > 100) {
            throw new DisputeException('Both dials must be 0-100.');
        }

        $lesson = $dispute->lesson;

        return LessonStateMachine::transition(
            $lesson,
            LessonStatus::Settled,
            function (Lesson $locked) use ($admin, $dispute, $parentRefundPct, $tutorPayPct, $note): void {
                // The lesson row is already locked by `transition()`; lock the dispute row too,
                // inside the same transaction, so two admins resolving the same dispute
                // concurrently cannot both succeed — the second sees `Resolved` once it gets the
                // lock this call waits on.
                $lockedDispute = Dispute::query()->whereKey($dispute->getKey())->lockForUpdate()->firstOrFail();

                if ($lockedDispute->status !== DisputeStatus::Open) {
                    throw new DisputeException('This dispute has already been resolved.');
                }

                if ($lockedDispute->lesson_id !== $locked->id) {
                    throw new DisputeException('This dispute does not belong to this lesson.');
                }

                $legs = ($this->ledger)->settle($locked, $lockedDispute, $parentRefundPct, $tutorPayPct, $admin);

                $before = ['status' => $lockedDispute->status->value];

                $lockedDispute->forceFill([
                    'status' => DisputeStatus::Resolved,
                    'parent_refund_pct' => $parentRefundPct,
                    'tutor_pay_pct' => $tutorPayPct,
                    'refund_amount' => $legs['refund'],
                    'tutor_paid_amount' => $legs['tutor'],
                    'platform_delta' => $legs['platform_delta'],
                    'admin_note' => $note,
                    'resolved_by' => $admin->id,
                    'resolved_at' => now(),
                ])->save();

                app(RecordAuditLog::class)($admin, 'dispute.resolved', $lockedDispute, $before, [
                    'status' => DisputeStatus::Resolved->value,
                    'parent_refund_pct' => $parentRefundPct,
                    'tutor_pay_pct' => $tutorPayPct,
                    'refund_amount' => $legs['refund'],
                    'tutor_paid_amount' => $legs['tutor'],
                    'platform_delta' => $legs['platform_delta'],
                    'note' => $note,
                ]);
            }
        );
    }

    /**
     * PRD §2.10's prefill defaults — a pure helper so each is independently testable. Returns
     * `[parentRefundPct, tutorPayPct]`, each nullable: `null` means "no detectable default, admin
     * must choose" (R150's "otherwise empty" bucket) — deliberately not `0`, since `0` is itself a
     * legitimate dial value and would be indistinguishable from "not decided yet" in the form.
     *
     * Only two of R150/PRD §2.10's three named scenarios are detectable from stored data (9b
     * design consult point 6, `docs/CYCLE-LOG.md` 01:41 DECISION, corrected by the 02:10 NOTE):
     * `trial` (lesson->type) -> **100/0**, exactly as R150 states — the 01:41 DECISION had left
     * `tutorPayPct` empty on a trial, reading PRD line 48's "tutor payment on that trial is decided
     * separately by admin" as "starts blank"; the 02:05 ADVISOR consult found that misapplied
     * CLAUDE.md's PRD-vs-`docs/reference/` precedence rule to a PRD-vs-PLAN question, and that there
     * is no real conflict: prefilling 100/0 is still admin-editable before resolution, which is all
     * PRD line 48 asks for (02:10 NOTE) — and the bare `no_show` reason (both dials empty — student
     * vs. tutor no-show cannot be told apart post hoc, 02:11 NOTE). `no_show_tutor` as its own
     * default is unreachable: `MarkNoShow::tutor()` auto-advances `no_show_tutor -> refunded`
     * (terminal) before a dispute could ever open on it, so no stored signal for it exists to
     * prefill from.
     *
     * @return array{0: int|null, 1: int|null}
     */
    public static function prefillFor(Dispute $dispute): array
    {
        if ($dispute->lesson->type === LessonType::Trial) {
            return [100, 0];
        }

        return [null, null];
    }
}
