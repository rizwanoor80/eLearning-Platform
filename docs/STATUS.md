# STATUS — cycle 04 r8 (CP3) — written 2026-09-24 16:39 — Context: continued from prior session (summarized); size not measured this write via `get_usage`

Tests: full suite on `main` at `ddb31ac` (post-merge smoke, quoted in CYCLE-LOG `16:32`): **1078/1078 passed, 4906 assertions** — unchanged from the branch's pre-merge run, suite did not shrink. `ledger:verify` → "Ledger OK: every lesson sums to zero." Advisor: 2 this cycle so far (design consult `14:35`, fix-loop consult `16:14`) — a fresh design consult is due next, before step 5's first edit, per PLAN.md line 4. Review: 3a/3b/3c/3d all merged (see §8); PR #14 re-review came back **14 PASS, 5 PASS WITH NOTE, 0 FAIL** (CYCLE-LOG `16:26`).

## §1 Git state
`origin/main` and local `main` both at `75c0b02` (fast-forwarded to `ddb31ac` via PR #14's merge, then one further docs-only commit `75c0b02` recording the merge/smoke-check in CYCLE-LOG — both pushed).
No feature branch currently checked out; `cp/3d-cancellation` is merged and still present remotely (not deleted, per the merge command's `--delete-branch=false`). `cp/3e-dashboards-emails` not yet created.
Working tree: clean as of this write, about to add this STATUS.md commit (docs-only, `[skip ci]`, direct to `main` per R79 — no feature branch exists yet for 3e).

## §2 Step map (cycle 04 r8 — CP3, an autonomous programme, R50)
1. `cp/3a-lows` — **merged** `b75d6e9` (PR #11).
2. `cp/3b-state-machine` — **merged** `5471bcb` (PR #12).
3. `cp/3c-booking` — **merged** `df0aa88` (PR #13).
4. `cp/3d-cancellation` — **merged** `ddb31ac` (PR #14). Fix-loop round 1 (Medium: money-guard on `SkipLesson`/`CancelLesson`; Low: `SuspendTutorForStrikes` failure isolation) closed; re-review 0 Medium/High; CI green; merged under R50/R55 (self-merge, no additional owner GO). Post-merge smoke green (suite, `ledger:verify`, HTTP checks — CYCLE-LOG `16:32`). CP3 acceptance boxes for "cancel", "skip", "tutor strikes/suspension" now `[x]` in CHECKPOINTS.md (landed as part of the PR #14 diff itself).
5. `cp/3e-dashboards-emails` — **starting now**, per PLAN.md step 4's "halt: no" and R55. Branch not yet created.
6. Programme end and rehearsal deploy — not started — halt with the Deploy action.

## §3 What changed this run
- Read PLAN.md in full (53 lines) to confirm step 5's exact scope before starting: parent dashboard (upcoming lessons, cancel action), tutor dashboard (today/upcoming), CP3 emails (confirmed/cancelled/skipped) through the settings sender, 24h/1h reminder scheduled job (`onOneServer` + `withoutOverlapping`, idempotency proven by running it twice), R54 deletion behaviour with audit rows, mail on `log` driver (assert queued, never delivered). PR #15; fresh review; halt: no.
- Committed the pending CYCLE-LOG DECISION entry (post-merge record for PR #14) as `75c0b02` — it had been written to the working tree in the prior step but not yet committed.
- This STATUS.md rewrite closes the 3d step boundary: §8 row updated to merged with the `ddb31ac` hash, §1/§2 updated to reflect `main`'s current position and the transition into step 5.
- Nothing else changed this write — no code touched yet for 3e.

## §4 Decisions and by whom
- Owner/planner rulings carried: R50, R54, R55, R57, R63, R75, R79 (unchanged).
- No new CC decisions this write beyond the step-boundary bookkeeping above.

## §5 Why stopping
**Not stopping — continuing in this same session.** This is a step-boundary STATUS.md write (rule 5) before starting step 5. Immediately next: push this commit, branch `cp/3e-dashboards-emails` from `main`, consult the advisor (design, before the first edit — PLAN.md line 4's mandatory trigger for a new sub-cycle), then begin implementation.

## §6 Mismatches
None new this write. Carried unchanged: the `## Carried to CP8 hardening checklist` items and the `## Deferred` gateway-refund-call item below.

## §7 Next step / Owner actions
No owner action open right now — proceeding autonomously per PLAN.md step 4's "halt: no" and R55. Step 5 (`cp/3e-dashboards-emails`, PR #15) begins immediately after this write is pushed.

Non-blocking, carried unchanged:
- Owner action C (learner-side overlap constraint) — **Recommended: leave as-is (Recommended)**.
- Owner action E (R53 live-as-you-type preview UX loss, revisit at CP8) — **Recommended: leave as Option A for v1 (Recommended)**.
- Owner action A (branch protection on `main`, R7); Owner action B (`.claude/settings.local.json`, ADR-002) — both optional, carried.

## §8 Programme board — CP3 (R50)
| Sub-cycle | State | Branch | PR | Review verdict | Merge |
|---|---|---|---|---|---|
| 3a Lows pass | **merged** | `cp/3a-lows` | [#11](https://github.com/rizwanoor80/eLearning-Platform/pull/11) | 0 Medium/High | `b75d6e9` (R50) |
| 3b state machine + ledger | **merged** | `cp/3b-state-machine` | [#12](https://github.com/rizwanoor80/eLearning-Platform/pull/12) | 14 findings, 1 FAIL(Low, scope) — resolved by R70 | `5471bcb` (R50, item 12 closed by owner ruling) |
| 3c booking | **merged** | `cp/3c-booking` | [#13](https://github.com/rizwanoor80/eLearning-Platform/pull/13) | round 1: 1 Medium + 5 Low FAIL, closed by fix-loop items 1–6; round 2: 0 Medium/High, 1 new Low (disposed as 3d's first commit) | `df0aa88` (R50, R55 GO by owner) |
| 3d cancellation | **merged** | `cp/3d-cancellation` | [#14](https://github.com/rizwanoor80/eLearning-Platform/pull/14) | round 1: 13 PASS, 2 PASS WITH NOTE, 1 FAIL(Medium), 1 FAIL(Low), fixed; re-review: 14 PASS, 5 PASS WITH NOTE, 0 FAIL | `ddb31ac` (R50/R55, self-merge) |
| 3e dashboards, emails, deletion | **starting now** | `cp/3e-dashboards-emails` | not yet opened | — | — |
| Programme end + rehearsal deploy | not started — halt | — | — | — | — |

Resume count: **0 of 8** (unchanged — no stop condition hit this cycle).

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
- Learner-side overlap constraint (Owner action C, R76 — not in v1).
- A reconciliation path for a `SuspendTutorForStrikes` failure after the triggering cancel/skip already committed — now built as swallow-and-log in both `CancelLesson`/`SkipLesson`; the reconciliation command itself is still not built, carried forward.
- PR #14 re-review's 5 PASS WITH NOTE items (stale docblock reference, minor code duplication between `CancelLesson`/`SkipLesson`'s guard pattern, an asymmetric test-coverage gap) — non-blocking, revisit at CP8.
