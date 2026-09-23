# STATUS — cycle 04 r7 (CP3) — written 2026-09-23 21:50 — Context: 93.3k/200k (47%, auto-compacts at 84%; measured via `get_usage` — v1.2/R67)
Tests: full `composer test` (`COMPOSER_PROCESS_TIMEOUT=1200 composer.bat test`) launched in background this run (task `blck01j8z`) per the standing lesson (background it from the start, don't wait for the tool's own 600s auto-background) — Pint and PHPStan and the RTL grep have already reported **passed** in its output; Pest/`ledger:verify` still running, not yet complete at this write. Last **fully completed** full-suite result, superseded by this run: 1029/1029 passed, 4668 assertions, `ledger:verify` green. Expected this run, not yet confirmed: **1033/1033** (net +2 — the R74 two-test merge to one, plus the new 3-case R53 regression dataset in `TutorOnboardingBankSubjectsRateTest.php`; individual narrow runs below already confirm both changes green in isolation). Advisor: 2 this sub-cycle (R53 Option A/B product-shape call; both logged in CYCLE-LOG with the model name and a quoted line) — cycle 04 total 17. Review: 3a done (0 Medium/High); 3b done (14 findings, 1 FAIL(Low, scope), resolved by R70, merged); 3c: `BookLesson` + sweep + `Payment` (from the prior write) plus this sub-cycle's R74 test-merge and R53 `Onboarding.vue` fix — built and independently green, not yet committed, not yet PR'd.

## §1 Git state
`main` unchanged since the last write. Branch `cp/3c-booking` carries uncommitted working-tree changes only (no new commits yet this sub-cycle): `app/Http/Controllers/Tutor/TutorOnboardingController.php`, `docs/CYCLE-LOG.md`, `resources/js/pages/tutor/Onboarding.vue`, `tests/Feature/Lessons/BookLessonTest.php`, `tests/Feature/Tutor/TutorOnboardingBankSubjectsRateTest.php` — confirmed via `git status --short`. **Rule 6 fix still owed at this write**: `docs/CYCLE-LOG.md` (four new entries this sub-cycle: ADVISOR, DECISION, NOTE, plus this STATUS rewrite about to be added) has been sitting uncommitted on the feature branch rather than moved to `main` immediately — next action after this write is the established recipe (commit docs-only on `cp/3c-booking` → `git switch main` → checkout both files from `cp/3c-booking` → commit `[skip ci]` on `main` → push → switch back → merge `main` into `cp/3c-booking`). The four code files above will be committed separately, as a code commit, once the docs split is done. PR #12 unchanged, merged. trustutor-rehearsal unchanged, behind `main` by design (R41).

## §2 Step map (cycle 04 r7 — CP3, an autonomous programme, R50)
1. `cp/3a-lows` — **merged** `b75d6e9` (PR #11).
2. `cp/3b-state-machine` — **merged** `5471bcb` (PR #12).
3. `cp/3c-booking` — **in progress.** From the prior write: `BookLesson` action, `Payment` model, `ExpireUnpaidLessons` sweep, 19+2 tests — built and green. This sub-cycle, newly done: (a) R74's box-3 concurrency proof — the two draft tests merged into the one in-process test R74 asks for (stale-`SlotCalculator`-stub design, decided and built in the prior window), confirmed via `--filter="R74"` (1 passed, 9 assertions) and a full `BookLessonTest.php` run (15 passed, 65 assertions, was 16 tests before the merge); (b) R53's `Onboarding.vue` trial-price preview unification — deleted the independent `100 − pct` JS formula (wrong-direction rounding on an odd fil), replaced with a server-computed `trialPriceFils` prop backed by `TutorProfile::trialPrice()` itself (Option A, decided via advisor + R53's literal PLAN wording over Option B — see §4), with a new 3-case regression dataset pinning the exact bug (10001 fils @ 50% → 5000, not the old formula's 5001), confirmed via `TutorOnboardingBankSubjectsRateTest.php` (17 passed, 85 assertions, was 14 before). `npm run build` green, RTL grep clean on the changed files. Still open: DATA_MODEL v1.4 deviation note (R57, blocks PR), the rule 6 docs-move (§1), the code commit, then PR #13 + review + halt for backend-dev GO (R55).
4. `cp/3d-cancellation` — not started.
5. `cp/3e-dashboards-emails` — not started.
6. Programme end and rehearsal deploy — not started — halt with the Deploy action.

## §3 What changed this run
- **R74 (box-3 concurrency proof)**: merged the prior window's two draft tests into the single in-process test R74's literal wording asks for — one learner books a slot via `BookLesson` using a deliberately stale `SlotCalculator` stub (so the app-level slot check cannot itself catch the race), a second learner races the same tutor/slot through the real action, the database exclusion/unique constraints (not the app-level check) reject the second attempt, and the test asserts exactly one `Confirmed` lesson, one `Payment` row, and a correctly-summed HOLD ledger entry survive. `tests/Feature/Lessons/BookLessonTest.php` net 16→15 tests (two merged into one), full file green (15 passed, 65 assertions).
- **R53 (Onboarding.vue trial-price unification)**: `App\Http\Controllers\Tutor\TutorOnboardingController.php` now passes `trialPriceFils` (from `$profile->trialPrice()?->toFils()`, the one method invariant 6/R53 designate) instead of `trialDiscountPct`; the dead `Settings` facade import was removed. `resources/js/pages/tutor/Onboarding.vue` had its independent `trialPriceFils()` JS function (a `100 − pct` half-up formula that rounds the *wrong* direction from `trialPrice()`'s own half-up-on-the-discount-side rule) deleted outright; the template now reads the server prop directly, with copy explaining the preview reflects the tutor's last *saved* rate, not an unsaved in-progress one. New 3-case dataset test in `tests/Feature/Tutor/TutorOnboardingBankSubjectsRateTest.php` proves the Inertia prop agrees with `trialPrice()` exactly at a band minimum, a band maximum, and the docblock's own odd-fils worked example. File net 14→17 tests, full file green (17 passed, 85 assertions).
- Checked `resources/js/pages/tutors/Index.vue`, `tutors/Show.vue`, `match-requests/Index.vue` for the same independent-formula pattern (grep for `trial_price|trialPrice|trial_discount`) — all three only display the server-precomputed `TutorPresenter::trial_price` string, no client-side recomputation; no changes needed.
- `npm run build`: green, no TypeScript errors. RTL grep: clean (both the project-wide `--filter="RTL"` suite, 3/3, and a manual grep of the changed Vue file for physical-direction utilities).
- Full `composer test` (Pint, PHPStan, RTL, Pest, `ledger:verify`) launched in background this run (task `blck01j8z`) to avoid the composer 300s default timeout / this machine's noisy 185s–541s full-suite duration (§6, carried mismatch) — Pint and PHPStan and RTL already report **passed**; Pest/`ledger:verify` not yet complete at this write. Will be logged as its own VERIFICATION entry in CYCLE-LOG the moment it finishes.

## §4 Decisions and by whom
- Owner/planner rulings carried, unchanged from last write: R70, R71, R72, R73.
- CC decisions carried from the design phase (unchanged): gateway resolution unbound/test-only (invariant 16, CP5-deferred), commission-split formula, tutor overlap via additive `EXCLUDE USING gist`, `tsrange` not `tstzrange`, `payments` row committed before `LessonStateMachine::transition()` runs, no learner-side overlap constraint in v1, a declined capture writes its own `failed` `payments` row before the lesson expires.
- **New CC decision this sub-cycle** (advisor consult + R53's literal PLAN text, both logged in CYCLE-LOG with the model name and a quoted line): chose Option A (server-computed prop reflecting only the tutor's saved rate) over Option B (a new preview endpoint calling `trialPrice()` on a transient unsaved-rate instance, to keep live-as-you-type preview). R53 says the independent formula is "deleted and replaced by a call to it" — nothing in R53 asks for a new endpoint to preserve live typing, and Option B would add route/Form-Request/JS-debounce surface CLAUDE.md's scope-discipline rules don't support absent an explicit ask. **Logged UX loss**: a tutor typing a new hourly rate no longer sees the trial price update live, keystroke by keystroke; they see it only after the rate step's own redirect reloads the page with the newly saved rate. Carried to §6 for the planner/owner to weigh as a possible CP8 polish item.
- Two advisor consults this sub-cycle (both on the R53 decision above), cycle 04 total now 17.

## §5 Why stopping
Not stopping — this is a step-boundary STATUS rewrite while the background `composer test` (task `blck01j8z`) finishes, per rule 5 ("rewritten at every step boundary... when in doubt, STATUS.md first"). Immediately after this write: check the background test's completed output, log its VERIFICATION entry with the literal count, execute the rule 6 docs-move recipe (§1), commit the four code files as a separate code commit, write the DATA_MODEL v1.4 deviation note (R57), then open PR #13 and run the pre-review advisor consult + fresh-subagent adversarial review + fix loop (cap 2), halting only for the backend-dev GO (R55) per PLAN step 3's own text. No plan or code blocker at this boundary; context is 47%, well short of the 84% auto-compact threshold, so this session continues straight through per rule 13/14 (one cycle per session, auto-compaction mid-cycle is not a stop reason).

## §6 Mismatches
- **New, non-blocking, product/UX**: the R53 fix removes the onboarding rate step's live-as-you-type trial-price preview (§4). Flagged for the planner/owner to weigh restoring as a CP8 polish item (Option B, a transient-rate preview endpoint) if the UX loss matters in practice; CC is not building Option B without that instruction, per the "When unsure" default (simplest option that keeps invariants intact).
- Carried, unchanged: `composer test`'s `"test"` script lacks `disableProcessTimeout`, so it is subject to composer's default 300s timeout; this machine's full-suite duration is noisy (185s–541s observed) and has tripped the timeout before as the suite grew. Worked around with `COMPOSER_PROCESS_TIMEOUT=1200`, and this run additionally backgrounds the command from the start rather than letting the Bash tool's own 600s limit be the first sign of a long run — no code or CI change needed, noting here in case CI's own timeout needs the same override later.
- Carried, unchanged: invariant 16 / plan-repo conflict (gateway unbound outside tests, registry deferred to CP5 — designed gap); PLAN step 3's overlap text read as the new exclusion constraint; the new migration's `CREATE EXTENSION IF NOT EXISTS btree_gist` confirmed on local Herd Postgres 18 only, not yet on CI or rehearsal; rehearsal behind `main` by design (R41); branch protection on `main` not set (R23, Owner action A, non-blocking).
- **Still open, non-blocking, carried:** Owner action C (confirm no learner-side overlap DB constraint needed in v1) — CC is proceeding on the recommended default (leave as-is) per the "When unsure" rule.

## §7 Next step / Owner actions
No owner action blocks continuing. Next steps, in order, no halt between them: (1) confirm the background `composer test` result and log VERIFICATION; (2) execute the rule 6 docs-move recipe for `docs/CYCLE-LOG.md` + this file; (3) commit the four code files as a code commit on `cp/3c-booking`; (4) write the DATA_MODEL v1.4 deviation note (R57); (5) open PR #13; (6) pre-review advisor consult; (7) fresh-subagent adversarial review; (8) fix loop (cap 2); (9) halt for the backend-dev GO (R55) — this is the one real halt point, since R55's condition (PR opened + reviewed) will finally be met.

- **Owner action C (recommended, non-blocking, carried):** confirm no learner-side overlap DB constraint is needed in v1 — only the tutor side is enforced. **Recommended: leave as-is (Recommended)** — the simplest option that keeps invariants intact; reply `update` when reviewed.
- **Owner action E (recommended, non-blocking, new):** confirm whether the R53 UX loss (§4/§6 — no live-as-you-type trial-price preview during rate entry) should be restored later as a CP8 polish item (Option B, a transient-rate preview endpoint). **Recommended: leave as Option A for v1, revisit at CP8 if it matters in practice (Recommended)** — matches R53's literal wording and avoids scope creep now; reply `update` when reviewed.

Carried, optional, non-blocking: Owner action A (R7 branch protection on `main`); Owner action B (`.claude/settings.local.json` install, ADR-002).

The standing clear action is **not** raised here — v1.2/R67 clears only at cycle END, and CP3 (cycle 04) is still in progress.

## §8 Programme board — CP3 (R50)
| Sub-cycle | State | Branch | PR | Review verdict | Merge |
|---|---|---|---|---|---|
| 3a Lows pass | **merged** | `cp/3a-lows` | [#11](https://github.com/rizwanoor80/eLearning-Platform/pull/11) | 0 Medium/High | `b75d6e9` (R50) |
| 3b state machine + ledger | **merged** | `cp/3b-state-machine` | [#12](https://github.com/rizwanoor80/eLearning-Platform/pull/12) | 14 findings, 1 FAIL(Low, scope) — resolved by R70 | `5471bcb` (R50, item 12 closed by owner ruling) |
| 3c booking | `BookLesson` + sweep + `Payment` + R74 concurrency test + R53 `Onboarding.vue` fix — all built and green, uncommitted until the next commit; DATA_MODEL note still open — halts for backend-dev GO (R55) before merge | `cp/3c-booking` | — | — | — |
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
- Refunding a captured-but-expired lesson (the payments-row-before-transition race) needs the real payment gateway — `FakePaymentGateway` cannot refund. Revisit when CP5 wires a real driver.
- **New this write**: the R53 onboarding live-as-you-type trial-price preview (Owner action E above) — revisit if the UX loss is judged to matter.
