# STATUS — cycle 05 r1 (CP4+, autonomous programme R88) — written 2026-09-25 18:45 (machine clock) — Context: not measured this write — 4a–4d merged; **4e built, PR #20 open, review done: 1 Medium open — HALT for owner rulings, then the backend-dev GO (R90)**

Tests: **1306/1306 passed, 6223 assertions** on `cp/4e-auto-charge` at `d5f6209` (`composer test` under Git Bash with `COMPOSER_PROCESS_TIMEOUT=0`; the reviewer re-ran it independently with the same counts; merged-`main` baseline at `c0e2fc3` was 1272/6038; +34 tests, all in `tests/Feature/Payments/AutoChargeTest.php`, plus one existing test rewritten). `ledger:verify`: **Ledger OK**. Pint, PHPStan (0 errors), RTL grep, `npm run build`: green. CI on PR #20 at `d5f6209`: **passing** (1 of 1, mergeable CLEAN). Review verdict (PR #20, fresh subagent): **1 Medium open** (a suspended tutor's weekly lesson is still charged and confirmed, `ChargeReservedLesson.php:120`), 0 High, Lows 1, 6, 7 (email wording, resume re-check under the lock, weak schedule test) — fix loop 0 of 2 used. Advisor: 2 consults for 4e (mid-build and closing; both answered; the mid-build one covered four money decisions by name — see CYCLE-LOG NOTE 18:16). 4d: 3 consults; 4c: 4.

## §1 Git state
`origin/main` at the docs commit carrying this write (on top of `8055f84`, `000c88e`, `39b8782`). Branch `cp/4e-auto-charge` at `d5f6209` (two commits on `39b8782`: `c98c390`, `d5f6209`; 35+ files), pushed, working tree clean; **PR #20 open** (https://github.com/rizwanoor80/eLearning-Platform/pull/20). The branch does not contain `main`'s docs-only commits after `39b8782` (`000c88e`, `8055f84` and this one). 4a–4d merged.

## §2 Step map (cycle 05 r1 — programme R88)
1. `cp/4a-foundation` — **merged** as `6375469`; post-merge suite 1170/1170, 5267 assertions.
2. `cp/4b-slot-actions` — **merged** as `496a76d`; post-merge suite 1208/1208, 5500 assertions.
3. `cp/4c-generation` — **merged** as `dd34988`; post-merge suite 1233/1233, 5623 assertions.
4. `cp/4d-portal` — **merged** as `c0e2fc3` (squash, R89); post-merge suite 1272/1272, 6038 assertions; post-merge record `39b8782`.
5. `cp/4e-auto-charge` — built, verified, pushed, PR #20 open, CI passing, adversarial review done (1 Medium open). **Halted**: owner rulings needed (Owner actions 1 and 2), then fix loop 1, re-review, then the backend-dev GO (R90) and merge.
6. Programme end and rehearsal deploy — not started (after the 4e merge; the owner presses Deploy in Forge).

## §3 What changed this run
- **4e (`cp/4e-auto-charge`, PR #20)** — R100, R101, R102:
  - Gateway: `PaymentGateway::saveCard` and `chargeSavedCard`, `SavedCard` DTO, `FakePaymentGateway` (outcome by card token; the declining test card throws `PaymentCaptureException('card_declined')`). `app/Services/Payments/*` touched under R100 (frozen only after CP5). `LedgerService` and `LessonStateMachine` are called, not edited (R94).
  - `payments` is one row per charge attempt: migration `2026_09_27_100000_restructure_payments_for_attempts` (`attempt_no`, `unique(lesson_id, attempt_no)`, partial unique `payments_one_live_per_lesson` WHERE `status <> 'failed'`, FK `payment_method_id` restrict); `Lesson::payment()` is the latest attempt; idempotency key `lesson:{id}:attempt:{n}` (ADR-013). Rehearsal has 0 rows in `payments`, `lessons`, `ledger_entries` and `recurring_slots`, so the migration converts nothing.
  - `recurring:charge` (hourly, `onOneServer`, `withoutOverlapping`): attempt 1 at T−48h, retries T−36h and T−24h (at least an hour apart, only before the start); success = payment captured + `reserved → confirmed` with the HOLD through `LedgerService::hold()` in the transition closure + counter reset; third failure = `cancelled_payment_failed`, counter +1, slot paused with `payment_failed` at 2 consecutive; a reserved lesson whose start has passed is cancelled `charge_window_missed` and does not count against the slot (the 4c carry; this sweep runs with no gateway bound).
  - Parent resume of a `payment_failed` pause after replacing the declined card (`RecurringSlotPolicy::resume`, `ResumeRecurringSlot`, `WeeklySlotController::resume`, `learners/Show.vue`); `SaveTestCard` goes through the fake driver and clears `last_failed_at`.
  - R102 emails: charged (parent), charge failed with the retry time in the parent's timezone (parent), cancelled for payment (parent and tutor), paused for payment (parent, tutor and now the admin — closes the 4d review note). `SendLessonConfirmedMail` skips the parent copy on `reserved → confirmed`; the first version suppressed the tutor's too and the suite caught it (1304 of 1305), fixed in code; the one existing test rewritten is `LessonLifecycleMailTest` "reserved lesson is confirmed" (tutor only, parent asserted not mailed).
  - `BookLessonTest`: three anonymous gateway doubles gained stubs.
  - Docs on `main` (`[skip ci]`): DATA_MODEL v1.5 `payments` note, DECISIONS ADR-013..015, CHECKPOINTS 4e bullet and two acceptance annotations, CYCLE-LOG (advisor, DECISIONs, VERIFICATION, REVIEW, BLOCKER, HANDOFF, a timestamp-correction NOTE).
- 4d (PR #19, `c0e2fc3`): portal — weekly-slot screens, fake add-card, slot emails. 4c (PR #18, `dd34988`): `recurring:generate`. 4b (PR #17, `496a76d`): slot actions and the Filament resource. 4a (PR #16, `6375469`): foundation migrations, enums, models, `SlotCalculator`. Detail: CYCLE-LOG.

## §4 Decisions and by whom
- CC (4e): retry arithmetic; HOLD written inside the confirm transition; missed-start sweep with `charge_window_missed`; email rules; parent resume needs a usable card with `last_failed_at` null; `payments` uniqueness and key — all logged as DECISIONs 2026-09-25 and covered by ADR-013..015; the four money decisions were consulted in one mid-build consult, the others were not put to the advisor separately (disclosed).
- CC (4e review): **withdrew** the 4b–4d reading of HOW-WE-WORK rule 6 for anything touching money or product behaviour — the Medium is not fixed in a loop without an owner ruling (DECISION 18:41). The owner may reinstate it for Lows.
- CC (4d, 4c, 4b): as in the earlier STATUS — fake add-card gated on `APP_ENV != production`; permit hold and 24h rule; floor at today; skip mail keyed on `notified_at`; `SlotCalculator::local()` public; notice-end leaves the slot `active`/`paused` until 4c's daily run; the `lessons` recurring key excludes `slot_paused` rows so resume can refill. All advisor-consulted where the plan required it.
- Owner/planner rulings carried: R1–R85 except R82 (withdrawn), R86–R103.

## §5 Why stopping
Handoff halt. **Done:** 4e is built, verified (1306/1306, 6223 assertions, Ledger OK, CI passing), pushed as PR #20, and reviewed once by a fresh subagent. **Why stopping:** the review found one Medium that R101 and invariant 5 disagree about (what to do with a weekly lesson whose tutor is no longer bookable when it falls due). Rule 6 returns Medium+ in code to the owner, the choice is a product decision, and my scripted attempt to apply a fix was refused by the permission classifier (nothing was written; CYCLE-LOG BLOCKER). R90 also halts 4e for the backend-dev GO. **Next:** the owner rules on Owner actions 1 and 2; then fix loop 1 (the Medium plus Lows 1, 6 and 7), re-review, the GO, merge under R89/R90 with `--match-head-commit`, the post-merge record on `main`, then step 6 (rehearsal deploy). **Ruled out:** fixing in-loop without a ruling; merging with an open Medium; any other route round the refused edit; a silent skip as the fix (leaves the parent uninformed and mails "could not be charged in time"). Programme resume count 0 of 8.

## §6 Mismatches
1. **R101's charge conditions vs invariant 5 (new, open — the Medium).** R101 lets `recurring:charge` charge a lesson that is reserved, due and on an active slot; it never mentions the tutor. Invariant 5 says a tutor is bookable only via `TutorProfile::bookable()`, and the PRD says suspend takes effect immediately. A tutor suspended (or whose permit or account lapses) after generation still gets charged and confirmed at T−48h. Probe by the reviewer confirmed a suspended tutor gives a Confirmed lesson and a captured payment. Stays with the owner (Owner action 1).
2. **No gateway is bound outside tests until CP5 (invariant 16 deferred, the 3c DECISION).** After the step-6 deploy `recurring:charge` prints "No payment gateway is configured: nothing will be charged.", exits 0, and cancels every reserved weekly lesson `charge_window_missed` at its start time; **nothing is ever confirmed on rehearsal until CP5.** The step-6 verification expectations must say so (and that no weekly lesson exists there anyway: 0 `recurring_slots`, 0 `lessons`).
3. **NeedsReview exposure (disclosed):** if a parent skips a lesson between the gateway taking the money and the confirmation, the payment stays `captured` with no hold, an exception is reported and `ledger:verify` flags it after five minutes; there is no refund path until CP5. The reviewer's related wording point: the skip email says the skip was free although the parent was charged.
4. **Idempotency rests on the real gateway (review 1, 9):** two overlapping runs would both call the gateway with the same key; and a card replaced while an attempt is pending reuses that attempt with the same key and a different card, which a real gateway will likely reject each hour until the start. CP5 must decide this (key per card, or void the pending attempt on card replace).
5. **R102 paused-recipient note — closed in 4e** (the admin now gets the paused email for `payment_failed`); review note: that mail reads reason and counter at send time, so a quick resume can suppress it or show stale numbers.
6. **Test-count history for 4e:** first full run 1304 of 1305 (the confirmed-mail regression), then 1305/1305 on `c98c390`, now 1306/1306 on `d5f6209` (+ the §4 test). A one-time timestamp error in my 4e CYCLE-LOG entries was corrected by a NOTE (they read 18:10/18:40 when the clock was about 17:55–18:05).
7. **Carried Lows from the 4c review:** (a) a missed daily run can still lose an occurrence inside the permit-hold window; widening the 24h no-hold window to `recurring_charge_lead_hours` would survive one missed run (`GenerateSlotLessons.php` `walk()`); (b) resume sets `generated_until` to today; (c) the skip mail is queued inside the transaction; (d) tests: `generated_until` after the 29th run, a second run on the 30th, non-default grace and charge lead, west-of-UTC continuity, per-slot mail-failure isolation; (e) anonymising an account does not end its active slots; (f) approved tutors have no permit-renewal path in the product (CP1 finding 11).
8. **Carried from the 4d review (accepted):** clearing `end_effective_on` on an immediate end empties the admin infolist "Tutor notice ends"; a parent ending the slot before a queued tutor-notice mail is sent gets two mails; switching the time choice resets a hand-typed start date.
9. **Earlier mismatches, resolved:** R98's literal lessons index vs resume (built with `AND cancel_reason IS DISTINCT FROM 'slot_paused'`); the `lessons.payment_method_id` FK moved to 4a with `payment_methods`; `payments.payment_method_id` FK now in 4e; the 4a and 4b carried Lows all dispositioned by 4d/4e.
10. **Review artefact, not a finding:** the reviewer saw no ADR-013..015 on the branch; they are on `main` (`git show origin/main:docs/DECISIONS.md | grep -c "ADR-01[345]"` = 3).

## §7 Next step / Owner actions
**Owner action 1:** rule what `recurring:charge` does with a `reserved` weekly lesson whose tutor is no longer bookable (suspended, permit lapsed or account deleted) when it falls due, and what the parent and tutor are told. Options: (1) **Cancel it at charge time with a new reason (for example `tutor_unavailable`), never charge, no strike, tell parent and tutor, and leave the slot active so the next weeks are held until the tutor is reinstated or the parent ends the slot (Recommended — the parent learns immediately and can rebook, nothing is charged, and it needs no new state edge because `reserved → cancelled_by_tutor` exists)**; (2) skip the charge and leave the lesson reserved until it lapses at the start as `charge_window_missed` (no new reason, but the parent hears nothing until then and the current lapse email says "could not be charged in time"); (3) pause the whole slot for `tutor_unavailable` (heaviest; also stops generation and needs a new pause reason and a resume rule). Reply with 1, 2 or 3.
**Owner action 2:** authorise fix loop 1 in code on `cp/4e-auto-charge` (the permission classifier refused the edit this session, so the session needs to be allowed to edit tracked files under `app/`, `resources/` and `tests/` again): the ruling from Owner action 1; Low 1 (drop "Nothing was paid and nothing is owed" from the missed-window email, since a pending attempt may exist); Low 6 (re-check the resume policy under the slot lock in `ResumeRecurringSlot`); Low 7 (a real schedule assertion for `recurring:charge`, replacing the name-only test) plus tests for the non-bookable tutor and the resume race; then a fresh re-review. Options: (1) **Yes, run fix loop 1 as described, then re-review (Recommended — a bounded change, cap 2 loops, the R90 GO still follows)**; (2) fix the Medium only and leave the Lows carried; (3) no fix loop: carry the Medium to CP5 and merge as is (not recommended: it charges a suspended tutor's lesson, invariant 5).
**Owner action 3 (after the fix loop and re-review pass; will be restated then):** the backend-dev GO for 4e (R90), then merge under R89/R90.
When done, then `/clear` this session and reply `update — <answers to 1 and 2, e.g. "1: option 1; 2: option 1">`.

## §8 Programme board — CP4+ (R88)
| Sub-cycle | State | Branch | PR | Review verdict | Merge |
|---|---|---|---|---|---|
| 4a foundation | **merged** | `cp/4a-foundation` | #16 | no Medium+ | `6375469` (R89) |
| 4b slot actions | **merged** | `cp/4b-slot-actions` | #17 | no Medium+; Lows fixed (round 1) | `496a76d` (R89) |
| 4c generation | **merged** | `cp/4c-generation` | #18 | no Medium+ after fix rounds 1–2; Lows carried | `dd34988` (R89) |
| 4d portal | **merged** | `cp/4d-portal` | #19 | no Medium+ after fix loops 1–2 (Lows fixed) | `c0e2fc3` (R89) |
| 4e auto-charge | built, CI passing, **1 Medium open** (halt: rulings, then backend-dev GO) | `cp/4e-auto-charge` | #20 | 1 Medium (suspended tutor charged), Lows 1, 6, 7 | — |
| Programme end + rehearsal deploy | not started (halt) | — | — | — | — |

Resume count: **0 of 8**.

## CP4 carried list (from cycle 04)
1. ~~`composer.lock` drift~~ — fixed in `b4e36d9` (R103a).
2. PR #15 finding 14 — stays on the CP8 list unless 4b touches `AnonymizeUser` (R103b).
3. Owner action A (branch protection) — optional, open. Owner action E — revisit CP8.
4. At the end of the programme (R103d): D-02 (payment gateway), D-04 and D-09 are listed once for the owner.

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
- Refunding a captured-but-expired lesson (the payments-row-before-transition race) needs the real payment gateway — `FakePaymentGateway` cannot refund. Revisit when CP5 wires a real driver; the 4e NeedsReview exposure (§6 item 3) is the same family.
- The R53 onboarding live-as-you-type trial-price preview (Owner action E) — revisit if the UX loss is judged to matter.
- Three latent round-2 PASS-WITH-NOTE observations — after-commit-callback misreporting on a confirmed-and-paid lesson; `VerifyLedger` crashing (not failing cleanly) against a database missing `payments`; non-`PaymentCaptureException` gateway errors leaving a payment `pending` with no reconciliation path.
- A multi-process booking race test (R74) — box 3's concurrency proof is bounded to in-process tests only.
- Learner-side overlap constraint (R76 — not in v1).
- A reconciliation path for a `SuspendTutorForStrikes` failure after the triggering cancel/skip already committed — built as swallow-and-log in both `CancelLesson`/`SkipLesson`; the reconciliation command itself is still not built.
- PR #14 re-review's 5 PASS WITH NOTE items (stale docblock reference, minor code duplication between `CancelLesson`/`SkipLesson`'s guard pattern, an asymmetric test-coverage gap) — non-blocking, revisit at CP8.
- PR #15 review finding 14: `AnonymizeUser`'s admin-only check is Action-internal, not behind a route-level Policy/Gate — add an outer authorization layer when an admin UI/Filament resource is wired up around it.
- ~~`payments.lesson_id` UNIQUE vs. the 3-retry recurring-charge design (R78)~~ — resolved in 4e (ADR-013): one row per attempt, `unique (lesson_id, attempt_no)` plus a partial unique index for one live row.
- `btree_gist` on `trustutor-production` — check when R41's recipe builds it at CP8 (R85).
