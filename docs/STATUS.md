# STATUS — cycle 05 r1 (CP4+, autonomous programme R88) — written 2026-09-25 14:04 — Context: not measured this write — 4a **merged** (`6375469`); 4b starting

Tests: **1170/1170 passed, 5267 assertions** (`composer --no-interaction test`; baseline `main` 1147/5175 — grew by 23). `ledger:verify`: **Ledger OK**. Pint, phpstan (0 errors), RTL grep, `npm run build`: green. Review verdict: **no Medium or High open** (fresh subagent, 19 numbered verdicts, CYCLE-LOG REVIEW entry). Advisor: **3 this cycle** (design, mid-build, pre-PR; R63 line on each entry).

## §1 Git state
`origin/main` at `6375469` (PR #16 squash-merged) + the docs commit carrying this write. Branch `cp/4a-foundation` merged; 4b branch not yet cut.

## §2 Step map (cycle 05 r1 — programme R88)
1. `cp/4a-foundation` — **merged** as `6375469`; post-merge suite 1170/1170, 5267 assertions, Ledger OK, smoke 200 ×3.
2. `cp/4b-slot-actions` — not started (advisor consult on R99's cancellation-state mapping before the first edit).
3. `cp/4c-generation` — not started.
4. `cp/4d-portal` — not started.
5. `cp/4e-auto-charge` — not started (halt for backend-dev GO, R90).
6. Programme end and rehearsal deploy — not started.

## §3 What changed this run
- Migrations: `payment_methods` (+ `lessons.payment_method_id` FK), `recurring_slots` extension (learner/curriculum/subject FKs, `price`, pause/end columns, failure counter, `generated_until`, `created_by_user_id`; live-slot unique index), `recurring_slot_skips` and the `lessons` recurring-slot key. Enums, models, factories, `SlotCalculator` (holding statuses, `effectiveEndDate()`), local-only demo seeder.
- Docs riding in the PR (plan step 1): CLAUDE.md rule 13 (R86d), ADR-012, DATA_MODEL v1.5, CHECKPOINTS CP4 notes.
- Reverted before push: a reserved-reason guard in `CancelLesson`/`SkipLesson` (outside R89 scope); `isReserved()` stays.
- FK safety for the step-6 deploy verified: rehearsal `lessons` has 0 rows and nothing in `app/` writes `lessons.payment_method_id`.

## §4 Decisions and by whom
- Owner/planner rulings carried: R1–R85 except R82 (withdrawn), R86–R103.
- CC: the `lessons` recurring key excludes `cancel_reason = 'slot_paused'` rows so resume can refill (advisor-confirmed) — §6 item 1.
- CC: `lessons.payment_method_id` FK in 4a with `payment_methods`; `payments.payment_method_id` (column already exists) gets its FK with 4e.
- CC: demo weekly slot + fake card in a local-only seeder, never on rehearsal.
- CC: reason-guard revert per advisor and R89 diff scope.

## §5 Why stopping
Not stopping — step-boundary record. Programme resume count 0 of 8; R86: no stop only to clear.

## §6 Mismatches
1. **R98's literal lessons index vs R99's resume.** R98: `unique (recurring_slot_id, starts_at) WHERE recurring_slot_id IS NOT NULL`. That would keep pause-cancelled rows' keys and block resume's refill. 4a builds it with `AND cancel_reason IS DISTINCT FROM 'slot_paused'` (parent skips keep the key). For 4b/4c: resume must reset `generated_until`; 4c's generator, if it uses `ON CONFLICT`, must repeat the exact predicate.
2. `docs/DATA_MODEL.md` said the `lessons.payment_method_id` FK arrives "in CP5"; R100 pulls `payment_methods` into 4a, so the FK moved with it (v1.5 corrected).
3. **Carried Lows from the 4a review (none affects 4a's schema):**
   - `SlotCalculator.php:35-36` class docblock still says "active … until `ends_on`" — fix in 4b (code file, so not in a docs pass).
   - `RecurringSlot::learner()` needs `withTrashed()`; `DeleteLearner` checks lessons only and should guard live slots — 4b.
   - `RecurringSlotSkip` has no factory — 4c, where it first gets real writes.
   - Any typed-reason input added in 4b/4d must refuse `LessonCancelReason::isReserved()` values; the cancelled email prints `cancel_reason` verbatim.
   - 4b must decide when a tutor-ended slot flips to `ended`; until then it stays `active` and `recurring_slots_live_unique` keeps holding its weekday/time after `end_effective_on`.
   - `$guarded = ['id']` on `RecurringSlot`: 4b actions must never pass request data straight in.
   - 4b migrations must be dated after `2026_09_26_*`.
   - Optional tests: `local` actually calls the demo seeder; the NOT NULL loop test asserts nothing about the error message.

## §7 Next step / Owner actions
None pending. CC starts 4b (advisor consult on R99 first).

## §8 Programme board — CP4+ (R88)
| Sub-cycle | State | Branch | PR | Review verdict | Merge |
|---|---|---|---|---|---|
| 4a foundation | **merged** | `cp/4a-foundation` | #16 | no Medium+ | `6375469` (R89) |
| 4b slot actions | not started | `cp/4b-slot-actions` | — | — | — |
| 4c generation | not started | `cp/4c-generation` | — | — | — |
| 4d portal | not started | `cp/4d-portal` | — | — | — |
| 4e auto-charge | not started (halt: backend-dev GO) | `cp/4e-auto-charge` | — | — | — |
| Programme end + rehearsal deploy | not started (halt) | — | — | — | — |

Resume count: **0 of 8**.

## CP4 carried list (from cycle 04)
1. ~~`composer.lock` drift~~ — fixed in `b4e36d9` (R103a).
2. PR #15 finding 14 — stays on the CP8 list unless 4b touches `AnonymizeUser` (R103b).
3. Owner action A (branch protection) — optional, open. Owner action E — revisit CP8.

## Deferred (out of v1 scope — do not build)
- Lesson packs / subscriptions / credits
- Group classes
- Courses / self-paced content
- Learning plans, homework, assessments
- B2B organisations / tenancy
- Multi-currency
- WhatsApp notifications
- Meilisearch
- Gateway `refund()` call in `CancelLesson` — CP5 with D-02; until then the `refund` ledger account is the record of money owed back.

## Carried to CP8 hardening checklist (R71)
- Search pagination: tutor search pages in memory after the slot check; revisit at "a few hundred approved tutors" (same trigger as the Meilisearch item).
- Refunding a captured-but-expired lesson (the payments-row-before-transition race) needs the real payment gateway — `FakePaymentGateway` cannot refund. Revisit when CP5 wires a real driver.
- The R53 onboarding live-as-you-type trial-price preview (Owner action E) — revisit if the UX loss is judged to matter.
- Three latent round-2 PASS-WITH-NOTE observations — after-commit-callback misreporting on a confirmed-and-paid lesson; `VerifyLedger` crashing (not failing cleanly) against a database missing `payments`; non-`PaymentCaptureException` gateway errors leaving a payment `pending` with no reconciliation path.
- A multi-process booking race test (R74) — box 3's concurrency proof is bounded to in-process tests only.
- Learner-side overlap constraint (R76 — not in v1).
- A reconciliation path for a `SuspendTutorForStrikes` failure after the triggering cancel/skip already committed — now built as swallow-and-log in both `CancelLesson`/`SkipLesson`; the reconciliation command itself is still not built, carried forward.
- PR #14 re-review's 5 PASS WITH NOTE items (stale docblock reference, minor code duplication between `CancelLesson`/`SkipLesson`'s guard pattern, an asymmetric test-coverage gap) — non-blocking, revisit at CP8.
- PR #15 review finding 14: `AnonymizeUser`'s admin-only check is Action-internal, not behind a route-level Policy/Gate — add an outer authorization layer when an admin UI/Filament resource is wired up around it.
- `payments.lesson_id` UNIQUE vs. the 3-retry recurring-charge design (R78) — a CP5-plan item, not CP8; listed for the planner's visibility.
- `btree_gist` on `trustutor-production` — check when R41's recipe builds it at CP8 (R85).
