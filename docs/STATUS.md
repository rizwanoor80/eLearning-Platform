# STATUS — cycle 05 r1 (CP4+, autonomous programme R88) — written 2026-09-25 13:40 — Context: not measured this write — step 1 (4a) **in progress**

Tests: not yet run this cycle (last known: `main` at `ef43a08` **1147/1147 passed, 5175 assertions**). `ledger:verify`: not run this cycle. Review verdict: none yet. Advisor: **1 this cycle** (4a design consult; counted; R63 line on the entry).

## §1 Git state
`origin/main` at `405275f` (PLAN r1, docs-only, `[skip ci]`) + the docs commit carrying the START/ADVISOR/DEVIATION/DECISION log entries + this STATUS. Branch `cp/4a-foundation` (from `405275f`) carries `63a55a6` (composer.lock refresh, R103a), not yet pushed.

## §2 Step map (cycle 05 r1 — programme R88)
1. `cp/4a-foundation` — **in progress.** Done: R103(a) lock fix (`63a55a6`); design consult; row counts. Next: migrations, enums, models, factories, seeder, SlotCalculator, CLAUDE.md rule 13 (R86d), ADR-012, DATA_MODEL v1.5, tests.
2. `cp/4b-slot-actions` — not started.
3. `cp/4c-generation` — not started.
4. `cp/4d-portal` — not started.
5. `cp/4e-auto-charge` — not started (halt for backend-dev GO, R90).
6. Programme end and rehearsal deploy — not started.

## §3 What changed this run
- `update` received; PLAN cycle 05 r1 committed as `405275f`.
- `composer.lock` drift diagnosed: content-hash and platform php (`^8.3`→`^8.4`) only, no package moved; fixed with `composer update --lock` (background task exit 0), committed alone as `63a55a6`; `composer validate` valid.
- Row counts local and rehearsal (read-only): `recurring_slots`, `lessons`, `payments` all 0 — no backfill needed.
- Advisor consult on the 4a design; DEVIATION on R98's lessons key logged (§6 item 1); two DECISIONs logged (`payment_method_id` FK on `lessons`; demo seed local-only).

## §4 Decisions and by whom
- Owner/planner rulings carried: R1–R85 except R82 (withdrawn), R86–R103 (this plan).
- CC: the `lessons` recurring key excludes `cancel_reason = 'slot_paused'` rows so resume can refill (advisor-confirmed) — §6 item 1.
- CC: `lessons.payment_method_id` FK added in 4a with `payment_methods`; `payments.payment_method_id` FK goes with 4e's payments migration.
- CC: demo weekly slot + fake card seeded in a separate local-only seeder, never on rehearsal.

## §5 Why stopping
Not stopping — mid-step 4a. This write is a step-boundary record. Programme resume count 0 of 8; R86: no stop only to clear.

## §6 Mismatches
1. **R98's literal lessons index vs R99's resume.** R98: `unique (recurring_slot_id, starts_at) WHERE recurring_slot_id IS NOT NULL`. That would make pause-cancelled rows keep their keys and block resume's refill. 4a builds the key with the extra predicate `AND cancel_reason IS DISTINCT FROM 'slot_paused'` (parent skips keep the key). Note for 4b/4c: resume must reset `generated_until` or generation never revisits those dates.
2. `docs/DATA_MODEL.md` says the `lessons.payment_method_id` FK arrives with `payment_methods` "in CP5"; the plan pulls `payment_methods` into 4a (R100), so the FK moves with it.

## §7 Next step / Owner actions
None pending. CC continues 4a.

## §8 Programme board — CP4+ (R88)
| Sub-cycle | State | Branch | PR | Review verdict | Merge |
|---|---|---|---|---|---|
| 4a foundation | in progress | `cp/4a-foundation` | — | — | — |
| 4b slot actions | not started | `cp/4b-slot-actions` | — | — | — |
| 4c generation | not started | `cp/4c-generation` | — | — | — |
| 4d portal | not started | `cp/4d-portal` | — | — | — |
| 4e auto-charge | not started (halt: backend-dev GO) | `cp/4e-auto-charge` | — | — | — |
| Programme end + rehearsal deploy | not started (halt) | — | — | — | — |

Resume count: **0 of 8**.

## CP4 carried list (from cycle 04)
1. ~~`composer.lock` drift~~ — fixed in `63a55a6` (R103a).
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
