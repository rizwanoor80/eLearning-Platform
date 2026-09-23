# STATUS — cycle 04 r6 (CP3) — written 2026-09-23 19:50 — Context: 120.2k/200k (60%, auto-compacts at 84%; measured via `get_usage`, not estimated — v1.2/R67)
Tests: **1005/1005 passed, 4588 assertions** (last full run: `main` head `8bc6850`, R70 item-6 smoke — no code written yet on `cp/3c-booking`, so this figure is unchanged) · `ledger:verify`: "Ledger OK: every lesson sums to zero." (same run) · Advisor: 3 this session (step-3 design consult; second consult — gateway resolution + commission-split correction + overlap-constraint fix; third consult — capture/freeze sequencing correction + concurrency-test mechanics + sanity check on the full file plan; all three carry the R63 phrase; cycle 04 total 13) · Review: 3a done (0 Medium/High); 3b done — 14 findings, 1 FAIL(Low, `phpunit.xml` scope), resolved by owner ruling R70, merged · 3c: pre-build investigation and design complete, no code written yet

## §1 Git state
`main` = `2a9bd86` (four commits since the last full rewrite, all pushed, `origin/main` matches — corrects the prior write's undercount, which listed `8bc6850` as current): `2bce2c9` STATUS R70 items 6-7, `25160fa` CYCLE-LOG R70 items 6-7 closed + step 3 started, `03dc1a8` CYCLE-LOG step-3 design consult + two NOTE corrections, `2a9bd86` CYCLE-LOG 3c pre-build investigation + second ADVISOR consult + three DECISIONs. Branch `cp/3c-booking` created from `main` at `2a9bd86`, currently **zero commits ahead** — this STATUS rewrite and its CYCLE-LOG entry are being made on `main` itself (docs-only, per protocol) before switching back to the branch for the first code commit. PR #12 — https://github.com/rizwanoor80/eLearning-Platform/pull/12 — **MERGED** (unchanged since last write). trustutor-rehearsal unchanged, behind `main` by design (push-to-deploy OFF, R41). Branch protection on `main`: not set (carried, R23).

## §2 Step map (cycle 04 r6 — CP3, an autonomous programme, R50)
1. `cp/3a-lows` — **merged** `b75d6e9` (PR #11).
2. `cp/3b-state-machine` — **merged** `5471bcb` (PR #12).
3. `cp/3c-booking` — **design complete, code not yet started.** Branch created, three advisor consults done (design, gateway/commission/overlap, capture-sequencing/concurrency), all findings logged in CYCLE-LOG as VERIFICATION/DECISION entries. Halts for the backend-dev GO before merge (R55) once the PR is opened and reviewed — expected, not a deviation.
4. `cp/3d-cancellation` — not started.
5. `cp/3e-dashboards-emails` — not started.
6. Programme end and rehearsal deploy — not started — halt with the Deploy action.

## §3 What changed this run
- Second advisor consult (money-touching: payment-gateway resolution for invariant 16): ruled `App\Services\Payments\PaymentGateway` interface + `FakePaymentGateway`, unbound in any service provider, bound only from the test side — the registry stays CP5 scope. Corrected the commission-split formula the first consult had floated: `tutor = intdiv(price × (100 − commission_pct), 100)`, `commission = price − tutor` (remainder fil to the platform by construction), replacing a half-up `percentage()` approach that could give the remainder to the tutor. Flagged and then (with `SlotCalculator.php:35`'s own overlap docblock as proof) confirmed a real gap: the merged tutor `(tutor_profile_id, starts_at)` unique index does not catch genuine interval overlap when a tutor's availability rules sit on different offsets — ruled a new forward-only migration adding an `EXCLUDE USING gist` constraint, additive to the existing index.
- Verified `btree_gist` is available on the local Herd PostgreSQL 18 instance (`psql` against the `postgres` maintenance DB). Logged all of the above as CYCLE-LOG VERIFICATION + ADVISOR + three DECISION entries, committed docs-only (`2a9bd86`), pushed.
- Third advisor consult (money-touching: `BookLesson` freezing/HOLD sequencing, plus a full file-plan sanity check before writing any code): **corrected two of the second consult's own conclusions.** (1) The overlap constraint must use `tsrange`, not `tstzrange` — confirmed via `php artisan db:table lessons` that `starts_at`/`ends_at` are `timestamp(0) without time zone`, and `tstzrange()` over those columns needs a STABLE implicit cast that Postgres refuses inside a GiST index expression. (2) The `payments` row must be written and committed in its own step immediately after capture, not inside `LessonStateMachine::transition()`'s `$work` closure — writing it there would let a refused transition (e.g. a sweep racing the confirm) roll back the only record of captured money, breaking R52. Both corrections logged as CYCLE-LOG NOTE-equivalent DECISION entries (`docs/CYCLE-LOG.md:752-758`) before any code was written, so no rework was needed.
- Investigated and closed the one open item the second consult had flagged: whether learner-side overlap needs its own DB constraint. `DATA_MODEL.md:130`'s "Overlap protection" section scopes the protection to the tutor side only; no domain invariant requires a learner-side constraint. Ruled, per the "When unsure" product-question default: no learner-side constraint in 3c, existing non-unique `(learner_id, starts_at)` index stands, logged as a non-blocking Owner action below for confirmation.
- No code has been written yet: `Money`'s commission-split method, the `payments` migration, the new overlap migration, `PaymentGateway`/`FakePaymentGateway`, the `BookLesson` action, the expiry sweep, and all tests are next, now that the design is settled and corrected.

## §4 Decisions and by whom
- Owner/planner rulings carried, unchanged from last write: R70, R71, R72, R73 (see prior STATUS revisions for full text; no new planner ruling this run).
- CC decision (second consult): payment-gateway resolution for invariant 16 — `PaymentGateway`/`FakePaymentGateway` added unbound, test-only binding; registry resolution stays deferred to CP5 (CYCLE-LOG `2026-09-23 19:20` DECISION, `docs/CYCLE-LOG.md:8`).
- CC decision (second consult): commission-split formula corrected to `intdiv` + subtraction, remainder to the platform, tested against 12345/25% and 101/25% (CYCLE-LOG `2026-09-23 19:20` DECISION, `docs/CYCLE-LOG.md:10`).
- CC decision (second consult): tutor-overlap enforced by a new additive `EXCLUDE USING gist` migration, not an edit to the merged 3b/CP2 migrations (CYCLE-LOG `2026-09-23 19:20` DECISION, `docs/CYCLE-LOG.md:12`).
- CC decision (third consult): the overlap constraint uses `tsrange`, not `tstzrange`, correcting the second consult's own design, confirmed against the actual column type before any migration was written (CYCLE-LOG `2026-09-23 19:45` VERIFICATION, `docs/CYCLE-LOG.md:754`).
- CC decision (third consult): `payments` row committed in its own step before `LessonStateMachine::transition()` runs, not inside its `$work` closure, to satisfy R52 under a transition-refused race (CYCLE-LOG `2026-09-23 19:45` DECISION, `docs/CYCLE-LOG.md:758`).
- CC decision (third consult, product-question default): no learner-side overlap constraint in 3c — DATA_MODEL scopes overlap protection to the tutor side only, no invariant requires the learner side, existing index stands (CYCLE-LOG `2026-09-23 19:45` DECISION, `docs/CYCLE-LOG.md:756`). Logged as Owner action below for confirmation, non-blocking.
- Three advisor consults this session so far (design, gateway/commission/overlap, capture-sequencing/concurrency) all carry the R63 phrase; a further consult is still owed once the `BookLesson` freezing/HOLD code is actually drafted if it raises any further open question, per the plan's money-trigger rule.

## §5 Why stopping
**Not stopping.** This STATUS rewrite marks the transition from design to build, done on `main` per the advisor's guidance (cheap while the branch has zero commits) so a mid-build auto-compaction cannot lose the corrected design. Work continues immediately after this write: switch back to `cp/3c-booking`, add the `Money` commission-split method and its unit test, then the two migrations, the gateway interface, `BookLesson`, the sweep job, and the full test list.

## §6 Mismatches
- **New, non-blocking:** invariant 16 / plan-repo conflict — the plan's "behind the CP5 driver interface" phrase is met by an unbound, test-only-bound `PaymentGateway` interface; the `payment_gateways` registry itself is deferred to CP5 by design. No booking path exists outside tests until then. This is a designed gap, not a defect.
- **New, non-blocking:** PLAN step 3's text ("overlap ... held by the DB indexes AND a transactional check") is read as referring to the new `EXCLUDE USING gist` constraint this session is adding, not the pre-existing `lessons_tutor_slot_unique` index alone — the existing index does not catch genuine interval overlap for tutors with multi-offset availability rules (proven via `SlotCalculator.php:35`'s own docblock). The new migration is additive; nothing merged in 3b/CP2 is edited.
- **New, non-blocking, deployment-only:** the new overlap migration runs `CREATE EXTENSION IF NOT EXISTS btree_gist`. Confirmed available on local Herd Postgres 18. Not independently confirmed on CI (`postgres:18` Docker image, standard contrib bundle expected) or on trustutor-rehearsal — CC cannot check the `forge` Postgres role's extension-creation rights there without raw SQL, which is on the never-list. CI will prove or refute this for real the first time the migration runs there (a loud migration error, not a silent gap); rehearsal is out of scope for CC per R61 (no remote migration by CC) and will only be exercised when the owner presses Deploy and runs the migration there.
- Carried, unchanged: rehearsal server behind `main` by design (R41); branch protection on `main` not set (R23, Owner action A, non-blocking).

## §7 Next step / Owner actions
No owner action blocks continuing. One recommended-default decision is open for confirmation, non-blocking (CC is proceeding on the recommended default per the "When unsure" rule and will not wait on it):

- **Owner action C (recommended, non-blocking):** confirm no learner-side overlap DB constraint is needed in v1 — only the tutor side is enforced (the plan's stated scope, and the only side any domain invariant requires). A parent could otherwise book two different tutors at overlapping times for two different learners, or (less usefully) double-book themselves across siblings; nothing in the 16 invariants or DATA_MODEL treats this as a defect. **Recommended: leave as-is (Recommended)** — the simplest option that keeps invariants intact; reply `update` when reviewed, or say if this should be revisited before 3c's PR.

Carried, optional, non-blocking: Owner action A (R7 branch protection on `main`); Owner action B (`.claude/settings.local.json` install, ADR-002).

The standing clear action is **not** raised here — v1.2/R67 clears only at cycle END, and CP3 (cycle 04) is still in progress.

## §8 Programme board — CP3 (R50)
| Sub-cycle | State | Branch | PR | Review verdict | Merge |
|---|---|---|---|---|---|
| 3a Lows pass | **merged** | `cp/3a-lows` | [#11](https://github.com/rizwanoor80/eLearning-Platform/pull/11) | 0 Medium/High | `b75d6e9` (R50) |
| 3b state machine + ledger | **merged** | `cp/3b-state-machine` | [#12](https://github.com/rizwanoor80/eLearning-Platform/pull/12) | 14 findings, 1 FAIL(Low, scope) — resolved by R70 | `5471bcb` (R50, item 12 closed by owner ruling) |
| 3c booking | design complete (3 advisor consults), code starting next — halts for backend-dev GO (R55) before merge | `cp/3c-booking` | — | — | — |
| 3d cancellation | not started | `cp/3d-cancellation` | — | — | — |
| 3e dashboards, emails, deletion | not started | `cp/3e-dashboards-emails` | — | — | — |
| Programme end + rehearsal deploy | not started — halt | — | — | — | — |

Resume count: **0 of 8** (unchanged — no stop condition hit this session).

## Deferred (out of v1 scope — do not build)
- Lesson packs / subscriptions / credits
- Group classes
- Courses / self-paced content
- Learning plans, homework, assessments
- B2B organisations / tenancy
- Multi-currency
- WhatsApp notifications
- Meilisearch

## Carried to CP8 hardening checklist (R71)
- Search pagination: tutor search pages in memory after the slot check; revisit at "a few hundred approved tutors" (same trigger as the Meilisearch item).
- Refunding a captured-but-expired lesson (the payments-row-before-transition race, §3 above) needs the real payment gateway — `FakePaymentGateway` cannot refund. Revisit when CP5 wires a real driver.
