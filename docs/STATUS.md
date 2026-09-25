# STATUS — cycle 05 r4 (CP4+, autonomous programme R88) — written 2026-09-26 01:35 (machine clock; step boundary 4e→4f) — Context: not measured this write — **4a–4e merged; step 6 `cp/4f-fake-gateway` starting**

Tests: **1315/1315 passed, 6310 assertions** on `main` at `187c24f` (constituent steps, R110; +9 over the pre-fix-loop 1306/6223). `ledger:verify`: **Ledger OK**. Pint, PHPStan (0 errors), RTL: green; local `/`, `/login`, `/admin/login`: 200 (CYCLE-LOG VERIFICATION). PR #20 review verdict: first review's Medium (R104) and Lows 1, 6, 7 closed; re-review's 1 Low carried to CP5 (R108). Advisor: consulted 0 times so far this session (4e total 4, 4d 3, 4c 4). Resume count 0 of 8.

## §1 Git state
`origin/main` at the docs commit carrying this write, on top of `83cbcca`, `47d8c40` (PLAN r4), `f4494d0`, `187c24f` (squash merge of PR #20, R108), `d192404`, `db49bf6` (PLAN r3). Branch `cp/4e-auto-charge` left in place at `f4cc7f9` (no branch deletion, CLAUDE.md). No open PRs; no unpushed commits. PLAN r3 and r4 committed as their own docs-only commits (r4 picked up mid-run under R112).

## §2 Step map (cycle 05 r1 — programme R88)
1. `cp/4a-foundation` — **merged** as `6375469`; post-merge suite 1170/1170, 5267 assertions.
2. `cp/4b-slot-actions` — **merged** as `496a76d`; post-merge suite 1208/1208, 5500 assertions.
3. `cp/4c-generation` — **merged** as `dd34988`; post-merge suite 1233/1233, 5623 assertions.
4. `cp/4d-portal` — **merged** as `c0e2fc3` (squash, R89); post-merge suite 1272/1272, 6038 assertions; post-merge record `39b8782`.
5. `cp/4e-auto-charge` — **merged** as `187c24f` (squash, PR #20) on the owner's GO (R108, recorded as the owner's; the backend developer was not consulted); post-merge suite 1315/1315, 6310 assertions, Ledger OK.
6. `cp/4f-fake-gateway` (R107) — **in progress**.
7. Programme end and rehearsal deploy (CC deploys rehearsal per R111) — not started.

## §3 What changed this run
- **4e fix loop 1 (`f4cc7f9`, R104/R105)**: `recurring:charge` cancels a due `reserved` weekly lesson whose tutor is not `bookable()` (suspended, permit lapsed, account deleted) as `reserved → cancelled_by_tutor`, new reason `LessonCancelReason::TutorUnavailable` (`tutor_unavailable`), actor null, never charged, no ledger entry, no strike, slot left active (new `ChargeOutcome::TutorUnavailable`, counted in the command summary). Parent and tutor get a new queued `WeeklyLessonTutorUnavailableMail` (parent copy neutral — no suspension or permit reason — says the slot can be kept or ended, and "Nothing was charged" only when no Pending or Captured payment exists; tutor copy says no strike). A lesson with a Pending attempt already in flight is not cancelled (advisor-driven, see §6 item 2). Low 1: the missed-window email no longer says nothing was paid. Low 6: `ResumeRecurringSlot` re-checks the policy on the locked row (stale-model race test). Low 7: a real schedule assertion (`0 * * * *`, `onOneServer`, `withoutOverlapping`). 9 new tests; a test that fails without the change proves R104 (6 of 7 fail with the action change stashed).
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
- Owner (2026-09-26): R104 option 1 and fix loop 1 (`update — 1: option 1; 2: option 1`, PLAN r2 R104–R106).
- CC (4e fix loop): the R104 branch does not fire while a Pending attempt exists (a narrow departure from R104's "never charged", to avoid stranding a payment the gateway may have taken); no fix loop 2 and no self-merge for the re-review's Low (rule 6). Both logged as DECISIONs 2026-09-26.
- CC (4e review): **withdrew** the 4b–4d reading of HOW-WE-WORK rule 6 for anything touching money or product behaviour — the Medium is not fixed in a loop without an owner ruling (DECISION 18:41). The owner may reinstate it for Lows.
- CC (4d, 4c, 4b): as in the earlier STATUS — fake add-card gated on `APP_ENV != production`; permit hold and 24h rule; floor at today; skip mail keyed on `notified_at`; `SlotCalculator::local()` public; notice-end leaves the slot `active`/`paused` until 4c's daily run; the `lessons` recurring key excludes `slot_paused` rows so resume can refill. All advisor-consulted where the plan required it.
- Owner/planner rulings carried: R1–R85 except R82 (withdrawn), R86–R103.

## §5 Why stopping
Not stopping: step-boundary write (4e merged, 4f next). **Done:** 4e merged under R108; smoke green on `main`. **Next:** step 6 `cp/4f-fake-gateway` (R107), then step 7 (CC deploys rehearsal, R111). **Ruled out:** fix loop 2 on 4e; reverting the Pending-attempt exception (restores the stranding hazard); a gateway status lookup now (CP5). Resume count 0 of 8.

## §6 Mismatches
1. **R101 vs invariant 5 — closed in fix loop 1 (R104).** A due weekly lesson whose tutor is not `bookable()` is now cancelled uncharged as `tutor_unavailable`. **`composer test` could not run as one unit on this machine:** under PowerShell the `rtl:check` script's `bash` resolves to WSL (which has no bash), under Git Bash `composer` is not on PATH. Each constituent step was run instead (`config:clear`, Pint, PHPStan, `bash scripts/rtl-check.sh`, `php artisan test`, `php artisan ledger:verify --no-interaction`) — CYCLE-LOG VERIFICATION 01:10.
2. **Open Low from the re-review — carried to CP5 by the owner (R108).** (a) **In-flight exception:** if a run dies after `begin()` committed a Pending row but before the gateway call, and the tutor is suspended before the next run, the next run charges the lesson (Confirmed, HOLD, "charged" mail) — or on a decline counts a failure toward pausing the slot. The alternative (cancel over the Pending row) can strand a payment the gateway took with no ledger entry. The proper fix is a gateway status lookup by idempotency key, which the fake gateway cannot answer; a CP5 carry. (b) Note: a `Captured` payment with a failed hold (`NeedsReview`) leaves the lesson `reserved`, so a later run could cancel it `tutor_unavailable` over money taken; `ledger:verify` flags it and the email drops "Nothing was charged"; optionally exclude Captured at the guard. (c) Notes: the 'account deleted' test proves the scope's `deleted_at` clause only (the real `AnonymizeUser` path cannot reach R104); the tutor mail would go to a trashed user's address (skip when `$tutor->trashed()`); the "keep or end" wording is asserted only as "still active".
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
No owner action open. Owner actions 1–3 are answered and done. CC continues with step 6 (R107) without stopping (R109).
Carried to the CP5-remainder list (R108): the gateway status lookup by idempotency key (in-flight exception, §6 item 2), excluding `Captured` at the R104 guard, skipping the tutor mail for a trashed user; the CP5-remainder line for the registry replacing R107 is added by 4f.

## §8 Programme board — CP4+ (R88)
| Sub-cycle | State | Branch | PR | Review verdict | Merge |
|---|---|---|---|---|---|
| 4a foundation | **merged** | `cp/4a-foundation` | #16 | no Medium+ | `6375469` (R89) |
| 4b slot actions | **merged** | `cp/4b-slot-actions` | #17 | no Medium+; Lows fixed (round 1) | `496a76d` (R89) |
| 4c generation | **merged** | `cp/4c-generation` | #18 | no Medium+ after fix rounds 1–2; Lows carried | `dd34988` (R89) |
| 4d portal | **merged** | `cp/4d-portal` | #19 | no Medium+ after fix loops 1–2 (Lows fixed) | `c0e2fc3` (R89) |
| 4e auto-charge | **merged** (owner GO, R108) | `cp/4e-auto-charge` | #20 | first review: 1 Medium + Lows 1, 6, 7, all closed; re-review: 1 Low carried to CP5 | `187c24f` |
| 4f fake gateway | in progress | `cp/4f-fake-gateway` | — | — | — |
| Programme end + rehearsal deploy (R111) | not started | — | — | — | — |

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
