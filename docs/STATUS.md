# STATUS — cycle 04 r8 (CP3) — written 2026-09-24 16:15 — Context: 151.4k/200k (76%), measured via `get_usage`

Tests: full suite on `cp/3d-cancellation` at `71e0b86`: **1078/1078 passed, 4906 assertions** — up from the 15:12 baseline (1074/4891) by exactly the 4 new tests this write added, 15 new assertions. All seven gates green (Pint, PHPStan, RTL check, `ledger:verify`, `npm run build` — detail in CYCLE-LOG `16:14`). Advisor: 1 this cycle (design consult, CYCLE-LOG `14:35`) + 1 this write (fix-loop consult, CYCLE-LOG `16:14`) = 2. Review: 3a/3b/3c merged (see §8); PR #14 (3d) round 1 found 1 Medium + 1 Low — fixed this write, **re-review not yet run**.

## §1 Git state
`origin/main` at `9482375`, unchanged. Local `main` matches.
`cp/3d-cancellation` at `71e0b86`, **not yet pushed this write** — pushing immediately after this STATUS.md commit, per rule 7 (`git log origin/main..HEAD` to be quoted in CYCLE-LOG before the push). Three commits ahead of the merge base once pushed: `0920716` (Throwable-catch first commit), `1294dd0` (`CancelLesson`/`SkipLesson` implementation), `71e0b86` (this write's fix-loop round). PR #14 open: https://github.com/rizwanoor80/eLearning-Platform/pull/14.
Working tree: this STATUS.md commit pending, otherwise clean. trustutor-rehearsal unchanged, not touched this cycle.

## §2 Step map (cycle 04 r8 — CP3, an autonomous programme, R50)
1. `cp/3a-lows` — **merged** `b75d6e9` (PR #11).
2. `cp/3b-state-machine` — **merged** `5471bcb` (PR #12).
3. `cp/3c-booking` — **merged** `df0aa88` (PR #13).
4. `cp/3d-cancellation` — **fix-loop round 1 done this write, about to push and re-review.** Round 1 (cap 2) closed the 15:10 review's Medium + Low findings, plus a Medium+ money defect the review itself missed (see §4). All seven gates green. Next: push, dispatch the fresh-subagent re-review, merge under R50 (no additional owner GO needed per R55) if it comes back 0 Medium/High.
5. `cp/3e-dashboards-emails` — not started; still correctly blocked behind 3d closing.
6. Programme end and rehearsal deploy — not started — halt with the Deploy action.

## §3 What changed this run
- Read HOW-WE-WORK-mandated docs in order; confirmed no new PLAN.md revision to commit (still cycle 04 r8, `3e4026b`); confirmed `main`/`origin/main` already in sync (rule 1's fast-forward step was a no-op).
- Interpreted the bare `update` reply as accepting STATUS.md §7 Owner action 1, Option 1 (its own closing line: "Reply `update` to proceed under option 1, or state a different instruction") — logged as a DECISION, CYCLE-LOG `16:01`.
- Consulted the advisor before editing (rule 11, money-adjacent code) — CYCLE-LOG `16:14` ADVISOR. It found Option 1's literal premise false: `SkipLesson` has no real status guard to "mirror"; its only check (`sum($locked) !== 0`) can never fire against a `confirmed`, HOLD-ed lesson, since invariant #1 keeps every lesson's ledger at zero between operations. Read `LedgerService::sum()` (app/Services/Ledger/LedgerService.php:127-130) and `LessonStateMachine::edges()` (app/Services/Lessons/LessonStateMachine.php:52-55) directly to confirm: `confirmed -> cancelled_by_tutor|cancelled_by_parent` is a legal edge, so nothing stops `SkipLesson` being called on a paid lesson today. Net: calling `SkipLesson` on a `confirmed`/HOLD-ed lesson would silently cancel it while stranding its price in escrow forever — a Medium+ money defect the 15:10 review did not catch (it only checked `CancelLesson`).
- Wrote a regression test first (`SkipLessonTest.php`) and confirmed it failed against the unfixed code, proving the hole empirically before fixing it.
- Fixed, in both `CancelLesson.php` and `SkipLesson.php`: (a) an explicit status guard (`Confirmed` / `Reserved` respectively) evaluated on the row **inside** `LessonStateMachine::transition()`'s locked work closure — not a pre-lock check — closing a TOCTOU window against a concurrent `recurring:charge` promotion; (b) the `SuspendTutorForStrikes` call now goes through a new `suspendTutorForStrikesQuietly()` helper (`try`/`catch (Throwable)`/`report()`, no rethrow), closing the review's Medium finding.
- Added 4 tests (2 files): the `SkipLesson`-on-confirmed regression, a `CancelLesson`-on-reserved guard test, and one `SuspendTutorForStrikes`-throws test per file (mocked via constructor injection, matching the `tests/Feature/Lows/LowsPassTest.php:222-224` precedent).
- Ran all seven gates — full detail and exact counts in CYCLE-LOG `16:14` VERIFICATION. All green.
- Committed `71e0b86` (5 files: 2 app, 1 test x2, CYCLE-LOG.md — 158 insertions/2 deletions). **Not yet pushed** — pushing directly after this STATUS.md write, then dispatching the re-review.

## §4 Decisions and by whom
- Owner/planner rulings carried: R50, R55, R57, R77, R78, R79 (unchanged).
- **Owner decision, via bare `update`**: accept STATUS §7 Owner action 1, Option 1 — authorise fix-loop round 1 (cap 2) on PR #14.
- **CC decision this write, disclosed per rule 11's "stale plan text → disclose ... never silently redo finished work, never silently do something else"**: Option 1's literal text ("add a guard to `CancelLesson` mirroring `SkipLesson`'s pattern") presupposed `SkipLesson` already had a correct guard. It did not. Rather than literally copying a broken pattern, added the correct guard (real `status` check) to **both** files, since the advisor's investigation showed `SkipLesson` itself had the more serious version of the same class of defect. Judged this to stay within round 1's authorised scope — same PR, same two files already being touched, closing a more-precisely-diagnosed version of the same guard defect — rather than scope-widening requiring a fresh Owner action, per CLAUDE.md's "When unsure" rule and invariant #1's non-negotiable weight. Full reasoning and citations in CYCLE-LOG `16:14` ADVISOR/VERIFICATION.
- **CC decision this write**: still not starting `cp/3e-dashboards-emails` — 3d is even closer to done now (round 1 of the fix loop complete) but not yet merged.

## §5 Why stopping
**Not stopping — continuing in this same session.** This is a step-boundary STATUS.md write (rule 5) before push, immediately followed by: push `cp/3d-cancellation`, quote `git log origin/main..HEAD`, dispatch the fresh-subagent re-review of PR #14, and — if it comes back 0 Medium/High — self-merge under R50 (R55: "3a, 3b, 3d and 3e self-merge under R50", no additional owner GO needed) and continue straight into step 5 (`cp/3e-dashboards-emails`) per PLAN.md's "halt: no" for step 4. If the re-review still finds Medium/High, PR #14 stays open, the finding is logged, and STATUS.md is rewritten with a fresh Owner action (round 2 of the fix loop is the cap; a third round is not available).

## §6 Mismatches
- **New this write**: STATUS.md §7 Owner action 1's Option 1 text ("mirroring SkipLesson's pattern") described a pattern that does not actually guard what it needs to — see §4. Corrected here rather than followed literally; no other document changed as a result (PRD/DATA_MODEL/PLAN are silent on this implementation detail).
- Carried unchanged: the `## Carried to CP8 hardening checklist` items below and the `## Deferred` gateway-refund-call item.

## §7 Next step / Owner actions
No owner action open right now — proceeding autonomously per PLAN.md step 4's "halt: no" and R55's self-merge authorisation, contingent on the re-review coming back clean. If it does not, this section will carry a fresh Owner action on the next write.

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
| 3d cancellation | round 1 fix-loop done, pushing + re-review next | `cp/3d-cancellation` | [#14](https://github.com/rizwanoor80/eLearning-Platform/pull/14) | round 1: 13 PASS, 2 PASS WITH NOTE, 1 FAIL(Medium), 1 FAIL(Low); fixes applied, re-review pending | not yet |
| 3e dashboards, emails, deletion | not started | `cp/3e-dashboards-emails` | — | — | — |
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
- A reconciliation path for a `SuspendTutorForStrikes` failure after the triggering cancel/skip already committed (e.g. a scheduled command that re-derives and applies any pending 3-strike suspensions) — now built as swallow-and-log in both `CancelLesson`/`SkipLesson`; the reconciliation command itself is still not built, carried forward.
