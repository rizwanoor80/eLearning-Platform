# STATUS — cycle 05 r1 (CP4+, autonomous programme R88) — written 2026-09-25 17:40 — Context: not measured this write — 4a, 4b **merged** (`496a76d`); 4c built locally, PR next

Tests: **1229/1229 passed, 5605 assertions** on branch `cp/4c-generation` (`composer.bat --no-interaction test`; merged-`main` baseline 1208/5500). `ledger:verify`: **Ledger OK** (also asserted inside the horizon test over generated lessons). Pint, phpstan (0 errors), RTL grep, `npm run build`: green. Review verdict: PR #17 no Medium/High; 4c review pending. Advisor: 4 consults for 4c (design, mid-build, money, pre-PR — all answered; results are stored redacted so no quoted line for the first two, disclosed in the log).

## §1 Git state
`origin/main` at the docs commit carrying this write (on top of `496a76d`). Branch `cp/4c-generation` (local, not yet pushed): `a5af9e0`, `0ad2579`, on `main` `d0a09dd`.

## §2 Step map (cycle 05 r1 — programme R88)
1. `cp/4a-foundation` — **merged** as `6375469`; post-merge suite 1170/1170, 5267 assertions, Ledger OK, smoke 200 ×3.
2. `cp/4b-slot-actions` — **merged** as `496a76d` (CI pass on `3a7632a`); post-merge suite 1208/1208, 5500 assertions, Ledger OK, smoke 200 ×3.
3. `cp/4c-generation` — **built and verified locally** (1229/1229, 5605 assertions); PR, fresh review and merge next.
4. `cp/4d-portal` — not started.
5. `cp/4e-auto-charge` — not started (halt for backend-dev GO, R90).
6. Programme end and rehearsal deploy — not started.

## §3 What changed this run
- 4c (local, `cp/4c-generation`): `recurring:generate` (`GenerateSlotLessons`, daily 05:00, `onOneServer` + `withoutOverlapping`) — extends each active slot to today + `recurring_horizon_weeks` in one locked transaction per slot, creates `reserved` regular lessons with price/commission/policy frozen and `next_charge_at = starts_at − charge lead`, records skips (`tutor_unavailable`, `tutor_blocked`, `lesson_collision`), flips slots past their effective end date to `ended`; `SendSlotSkipNotices` + `RecurringSlotSkippedMail` (one mail per slot per run, `notified_at`); `RecurringSlotSkipFactory`; `SlotCalculator::local()` made public and `RecurringSlotSkip` `HasFactory` (both named as R89 exposures); 21 generation tests. No money moves at generation.
- 4b (PR #17): `CreateRecurringSlot`, `EndRecurringSlot` (parent/admin now; tutor with notice, no strike), `PauseRecurringSlot`, `ResumeRecurringSlot`, `CancelSlotReservedLessons`, `RecurringSlotPolicy`, Filament `RecurringSlots` resource (list, view, create-with-override, pause, resume, end; all admin actions audited), `RecurringSlotException`, `LessonCancelReason::SlotEnded`. DATA_MODEL v1.5 gained the slot-end cancellation mapping (on `main`, `0ef33df`).
- Migrations: `payment_methods` (+ `lessons.payment_method_id` FK), `recurring_slots` extension (learner/curriculum/subject FKs, `price`, pause/end columns, failure counter, `generated_until`, `created_by_user_id`; live-slot unique index), `recurring_slot_skips` and the `lessons` recurring-slot key. Enums, models, factories, `SlotCalculator` (holding statuses, `effectiveEndDate()`), local-only demo seeder.
- Docs riding in the PR (plan step 1): CLAUDE.md rule 13 (R86d), ADR-012, DATA_MODEL v1.5, CHECKPOINTS CP4 notes.
- Reverted before push: a reserved-reason guard in `CancelLesson`/`SkipLesson` (outside R89 scope); `isReserved()` stays.
- FK safety for the step-6 deploy verified: rehearsal `lessons` has 0 rows and nothing in `app/` writes `lessons.payment_method_id`.

## §4 Decisions and by whom
- CC (4c): floor at today (past occurrences dropped silently); a skip row is final; an ended-by-date slot flips to `ended` in the daily run; generated lessons are `regular`, booked-by the learner's account, `payment_method_id` null (4e resolves the card at charge time); skip mail sent from an Action keyed on `notified_at`; `SlotCalculator::local()` public. All logged as DECISIONs, advisor-consulted.
- Owner/planner rulings carried: R1–R85 except R82 (withdrawn), R86–R103.
- CC: the `lessons` recurring key excludes `cancel_reason = 'slot_paused'` rows so resume can refill (advisor-confirmed) — §6 item 1.
- CC: `lessons.payment_method_id` FK in 4a with `payment_methods`; `payments.payment_method_id` (column already exists) gets its FK with 4e.
- CC: demo weekly slot + fake card in a local-only seeder, never on rehearsal.
- CC: reason-guard revert per advisor and R89 diff scope.
- CC (4b): a tutor's notice end leaves the slot `active`/`paused` with `end_effective_on` set; 4c's daily run flips it to `ended`. Parent/admin end is immediate. Advisor-approved.
- CC (4b): fixed the review's Low code/test findings inside the fix loop rather than stopping (HOW-WE-WORK rule 6 reading, logged as DECISION; the owner may overrule).
- CC (4b): `RecurringSlotException` and `LessonCancelReason::SlotEnded` read as inside R89 scope (reviewer concurred); logged as NOTE.

## §5 Why stopping
Not stopping — step-boundary record after the 4c build. Programme resume count 0 of 8; R86: no stop only to clear.

## §6 Mismatches
1. **R98's literal lessons index vs R99's resume.** R98: `unique (recurring_slot_id, starts_at) WHERE recurring_slot_id IS NOT NULL`. That would keep pause-cancelled rows' keys and block resume's refill. 4a builds it with `AND cancel_reason IS DISTINCT FROM 'slot_paused'` (parent skips keep the key). For 4b/4c: resume must reset `generated_until`; 4c's generator, if it uses `ON CONFLICT`, must repeat the exact predicate.
2. `docs/DATA_MODEL.md` said the `lessons.payment_method_id` FK arrives "in CP5"; R100 pulls `payment_methods` into 4a, so the FK moved with it (v1.5 corrected).
3. **Carried from 4c to 4e:** `recurring:charge` must not charge a lesson whose `starts_at` has already passed (generation drops past occurrences but a charge lag could still cross the start); `next_charge_at` may already be in the past at generation by design and is charged on 4e's first hourly tick.
4. **Carried Lows from the 4a review (none affects 4a's schema):**
   - `SlotCalculator.php:35-36` class docblock still says "active … until `ends_on`" — fix in 4b (code file, so not in a docs pass).
   - `RecurringSlot::learner()` needs `withTrashed()`; `DeleteLearner` checks lessons only and should guard live slots — 4b.
   - `RecurringSlotSkip` has no factory — 4c, where it first gets real writes.
   - Any typed-reason input added in 4b/4d must refuse `LessonCancelReason::isReserved()` values; the cancelled email prints `cancel_reason` verbatim.
   - 4b must decide when a tutor-ended slot flips to `ended`; until then it stays `active` and `recurring_slots_live_unique` keeps holding its weekday/time after `end_effective_on`.
   - `$guarded = ['id']` on `RecurringSlot`: 4b actions must never pass request data straight in.
   - 4b migrations must be dated after `2026_09_26_*`.
   - **Carried from the 4b review:** (a) 4d — bulk pause/end fires `SendLessonSkippedMail`/`SendLessonCancelledMail` per lesson (2×N mails); suppress by `cancel_reason` in `app/Listeners`, outside 4b's scope. (b) 4c/4e — lock the slot first, then its lessons, and re-check slot status under that lock; 4e must catch a repeat `PaymentFailed` pause (invariant 14). (c) 4c/4d — go by `status` and `end_effective_on`, never `ended_at`, to mean "ended". (d) When availability/timezone editing ships, add an explicit slot-vs-slot check across timezones (`recurring_slots_live_unique` only matches identical `(weekday, start_time, timezone)`). (e) Out of 4b's named areas, still open from 4a: the `SlotCalculator` docblock, `RecurringSlot::learner()` `withTrashed()`, `DeleteLearner` guarding live slots. (f) The PR-checklist box 4 `confirmed`-follows-§4 half is exercised end to end only from 4e.
   - Optional tests: `local` actually calls the demo seeder; the NOT NULL loop test asserts nothing about the error message.

## §7 Next step / Owner actions
None pending. CC pushes `cp/4c-generation`, opens the PR, runs the fresh review and merges under R89.

## §8 Programme board — CP4+ (R88)
| Sub-cycle | State | Branch | PR | Review verdict | Merge |
|---|---|---|---|---|---|
| 4a foundation | **merged** | `cp/4a-foundation` | #16 | no Medium+ | `6375469` (R89) |
| 4b slot actions | **merged** | `cp/4b-slot-actions` | #17 | no Medium+; Lows fixed (round 1) | `496a76d` (R89) |
| 4c generation | built locally, PR next | `cp/4c-generation` | — | — | — |
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
