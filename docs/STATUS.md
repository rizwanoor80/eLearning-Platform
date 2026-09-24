# STATUS — cycle 04 r8 (CP3) — written 2026-09-24 10:33 — Context: not re-measured this write (last measured 164.4k/200k at 08:52; v1.2/R67 requires measurement not estimation — `get_usage` will be called before the next write or the cycle's HANDOFF, whichever comes first)
Tests: full `composer test` at the current `cp/3c-booking` HEAD (`14fcd4e`), confirmed twice independently this window (this session's own `09:10` re-run, and the round-2 reviewer's own separate checkout): **1053/1053 passed, 4835 assertions**, `ledger:verify` green — Pint, PHPStan (0 errors) and RTL grep all passed. CI: confirmed green on the exact current head — run `35960212898`, `headSha: 14fcd4e29198d75432e57f5366dcff7d38f3a203` (matches `git rev-parse HEAD` exactly), `conclusion: success`; `mcp__ccd_pr__get_status` independently confirms 1 passing/0 failing, `mergeStateStatus: CLEAN`. Advisor: 0 this write (round-2 review is a fresh-subagent adversarial review per rule 6, not an advisor consult; cycle 04 total remains 20). Review: 3a done (0 Medium/High); 3b done (14 findings, 1 FAIL(Low, scope), resolved by R70, merged); **3c: round-1's 1 Medium + 5 Low findings all closed by R77 items 1–6. Round-2 review (the last of cap 2) is now done: no open Medium/High — the R55 GO condition is met. One new Low (test-coverage gap, not doc-only) routes to the owner per rule 6, does not block merge. PR #13 is mergeable; the merge itself still needs the owner's explicit chat confirmation.**

## §1 Git state
`origin/main` at `cd16d63` (6 docs-only `[skip ci]` commits pushed this window, approved by the owner — see §4). Local `main` and `origin/main` match exactly after this write's docs-only commit is pushed (no confirmation needed this cycle per the owner's relaxed rule — see §4).
`cp/3c-booking` (current branch) is at `14fcd4e` ("Merge branch 'main' into cp/3c-booking", carrying forward the round-2-review-dispatch NOTE). `origin/cp/3c-booking` was last pushed to `67b7243`; this write adds the round-2 REVIEW/VERIFICATION/DECISION CYCLE-LOG entries and this STATUS.md rewrite, landing on `main` first via the stash technique then merged forward — one more commit beyond `14fcd4e` once that merge lands, to be pushed alongside this write (no confirmation needed, docs-only + `cp/` push, per the owner's relaxed rule).
Working tree, at the start of this write: clean (`git status --short` empty) — the round-2 CYCLE-LOG entries were already committed to `main` as part of this write's own sequence before this file was written; see below for the exact commit-and-push sequence still to run after this write.
PR #13 open against `main`. Mergeable: `mcp__ccd_pr__get_status` reports `mergeable: MERGEABLE`, `mergeStateStatus: CLEAN`, 1 CI check passing, 0 failing. trustutor-rehearsal unchanged, behind `main` by design (R41), not touched this cycle.

## §2 Step map (cycle 04 r8 — CP3, an autonomous programme, R50)
1. `cp/3a-lows` — **merged** `b75d6e9` (PR #11).
2. `cp/3b-state-machine` — **merged** `5471bcb` (PR #12).
3. `cp/3c-booking` — **R77 item 7 of 7: round-2 review done, no open Medium/High, R55 GO condition met. Awaiting the owner's explicit merge confirmation (still required this cycle) before merging PR #13.** All of items 1–6 (code fixes) and item 7's re-verify/push/CI-confirm/round-2-review steps are complete. Remaining: owner "yes" to merge → merge PR #13 under R50 → post-merge record → continue straight into step 4, same session.
4. `cp/3d-cancellation` — not started, blocked behind 3c's merge.
5. `cp/3e-dashboards-emails` — not started.
6. Programme end and rehearsal deploy — not started — halt with the Deploy action.

## §3 What changed this run
- Round-2 fresh-subagent adversarial review of PR #13 completed (model `opus`, isolated worktree, no prior session context — dispatched last write, result received this write). Verdict, quoted: *"there is no open Medium or High on PR #13. The six fixes do close the round-1 findings, and the suite and ledger:verify are green. It is mergeable under the project rule. One new Low remains open. It concerns a test, so under the protocol it goes to the owner."* Full verbatim result logged in `docs/CYCLE-LOG.md` at `2026-09-24 10:31` (REVIEW entry).
- Every round-1 finding the round-2 review re-checked came back closed: #1 (Medium, stranded payment) PASS WITH NOTE — the reviewer independently proved closure with its own probe (forced `hold()` to throw post-charge; result was a clean `BookingException`/`expired`/no-ledger-rows outcome, with `ledger:verify` correctly flagging it after the grace window); #2/#3 (sweep post-lock re-check, `lazyById()` pagination) PASS WITH NOTE / PASS, confirmed via a mutation test (disabling the re-check broke the item-4 test); #4 (float/round grep) PASS WITH NOTE; #5 (band-table agreement) PASS; #7 (MoneyTest comment) PASS.
- One NEW finding: FAIL(Low, `tests/Feature/Lessons/BookLessonTest.php:509-553`) — the broadened `catch (Throwable $e)` in `BookLesson.php:163` (added by item 3, to catch stranding failures beyond `LessonTransitionException`) has no committed test exercising a non-`LessonTransitionException` throwable; reverting the catch to its narrower pre-item-3 type left all 421 committed tests in `tests/Feature/Lessons` and `tests/Feature/Ledger` green, proving the gap. Per rule 6 ("Low doc-only findings fixed in-cycle; Medium+ or anything in code/config/routes/migrations/tests stops and returns to the owner"), this is in a test file — not doc-only — so it is not fixed in-cycle here (the fix-loop's cap of 2 rounds is also now fully spent). Recorded as Owner action 2 below and on the CP8 hardening carry-forward list (§ below).
- Two further NEW PASS-WITH-NOTE observations (non-gating, latent — nothing in the codebase calls these paths yet): an after-commit callback failure once a lesson is confirmed-and-paid would be misreported as "could no longer be confirmed"; `VerifyLedger` would crash (not fail cleanly) if run against a database missing the `payments` table (a deploy-ordering edge, not a current defect); a gateway error other than `PaymentCaptureException` leaves a payment `pending` with no reconciliation path beyond manual fix. All three noted for future hardening, none block this merge.
- CI re-confirmed independently by this session on the actual current head (`14fcd4e`, not a stale earlier commit) immediately before logging the merge decision — see header.
- DECISION logged in CYCLE-LOG: R55's GO (PLAN r8, conditional on round-2 clean + CI green) stands — condition met. PR #13 is mergeable under R50, pending the owner's own required merge confirmation.

## §4 Decisions and by whom
- Owner/planner rulings carried: R70, R71, R72, R73, R77, R78, R79 (all pre-this-write).
- **Owner ADVICE, in chat, logged before acting (CYCLE-LOG `09:18`)**: *"yes, push both. For the rest of cycle 04, pushes to cp/ branches and docs-only pushes to main need no confirmation; the PR merge still does."* This is the standing rule for the remainder of cycle 04: `git push` to `origin/main` (docs-only) and to any `cp/` branch needs no further chat confirmation; **PR merges still require an explicit owner "yes" each time** — this is the one gate left open for step 3c.
- **CC decision this write**: the new Low finding (test-coverage gap) does not block the R55 GO condition, because R77 item 7's literal text gates merge on "no open Medium/High", not on zero findings of any severity, and rule 6 explicitly routes non-doc-only Low findings to the owner rather than an in-cycle fix. Both are quoted verbatim in the CYCLE-LOG DECISION entry (`10:33`).
- Carried, unchanged from prior writes: R78 (payments.lesson_id UNIQUE deferred to CP5), R79 (docs land on `main` first, always), CC design decisions from earlier in 3c (gateway resolution unbound, commission-split formula, tutor overlap via `EXCLUDE USING gist`, `tsrange`, payments-row-before-transition ordering, R53 Option A).

## §5 Why stopping
**This is a merge-confirmation halt — the one gate the owner's own relaxed rule left standing this cycle, not a review-finding halt and not the cycle's END** (rule 13: no §5 context handoff beyond this paragraph, no HANDOFF entry, no `/clear` Owner action yet). Round-2 review found no open Medium/High; CI is green on the exact current head; R55's conditional GO (PLAN r8) is satisfied. Everything left in R77 item 7 — push, CI confirmation, round-2 review — is done. The owner's own words this window ("the PR merge still does" [require confirmation]) are the only remaining condition before PR #13 can be merged, per rule 8 ("merge to main" is a named-authorisation action, never self-authorised). That confirmation is requested in §7, Owner action 1, for the first time this write.

## §6 Mismatches
- **Resolved this cycle**: round-1's 1 Medium + 5 Low findings all closed by R77 items 1–6, confirmed independently by the round-2 fresh-subagent review (see §3).
- **New, non-blocking**: round-2 review's one new Low (test-coverage gap for the broadened `Throwable` catch in `BookLesson.php:163`) — not fixed in-cycle per rule 6 (test file, not doc-only; fix-loop cap of 2 rounds is spent). Carried to Owner action 2 (§7) and the CP8 hardening list.
- **New, non-blocking, disclosed for visibility**: three latent PASS-WITH-NOTE observations from round 2 (after-commit-callback misreporting on a confirmed-and-paid lesson; `VerifyLedger` would crash rather than fail cleanly against a database missing `payments`; non-`PaymentCaptureException` gateway errors leave a payment `pending` with no reconciliation path) — none currently reachable, noted on the CP8 hardening carry-forward list, not raised as Owner actions.
- Deferred to CP5 by the planner (R78, carried): `payments.lesson_id` UNIQUE vs. weekly-charge-retry design.
- Reassigned to the planner by R78, carried: confirming `btree_gist` privilege on trustutor-rehearsal ahead of R61.
- Carried, unchanged: `composer test`'s `"test"` script lacks `disableProcessTimeout` (worked around each run); rehearsal behind `main` by design (R41); branch protection on `main` not set (R23, Owner action A, non-blocking); Owner action C (learner-side overlap constraint) and Owner action E (R53 live-preview UX loss) — see §7.

## §7 Next step / Owner actions
**Owner action 1 (blocking — the merge gate the owner's own relaxed rule left standing this cycle).** PR #13 is mergeable: round-2 review found no open Medium/High (the fix-loop's cap-2 rounds both ran, both are closed out), CI is green on the exact current head (`14fcd4e`, run `35960212898`), and R55's conditional GO (PLAN r8) is satisfied. **Recommended: approve the merge now (Recommended)** — merging under R50, writing the post-merge record, and continuing straight into `cp/3d-cancellation` in this same session, per PLAN r8's instruction. Reply `update` (or an explicit "yes" in chat) to authorise the merge; nothing else in this step needs further confirmation this cycle.

**Owner action 2 (non-blocking, does not gate the merge — for visibility and future scheduling).** Round-2 review's one new Low finding: `tests/Feature/Lessons/BookLessonTest.php` has no committed test for the broadened `catch (Throwable $e)` at `BookLesson.php:163` catching something other than `LessonTransitionException` — the reviewer proved the gap with a disposable probe (a stand-in `LedgerService` whose `hold()` throws), then reverted it. Per rule 6 this is a test-file finding, not doc-only, so it wasn't fixed in-cycle, and the fix-loop's cap of 2 rounds is now spent for this PR. **Recommended: pick it up as the first item of `cp/3d-cancellation`'s cycle, or a dedicated micro-cycle, rather than reopening 3c's fix loop (Recommended)** — it's a test-coverage gap, not a live defect (all 421 committed tests in the affected suites pass either way). Also carried to the CP8 hardening list below.

Non-blocking, does not gate Owner action 1:
- Carried, unchanged: Owner action C (learner-side overlap constraint) — **Recommended: leave as-is (Recommended)**.
- Carried, unchanged: Owner action E (R53 live-as-you-type preview UX loss, revisit at CP8) — **Recommended: leave as Option A for v1 (Recommended)**.
- Carried, optional: Owner action A (branch protection on `main`, R7); Owner action B (`.claude/settings.local.json`, ADR-002).

The standing clear action is **not** raised here — this is a merge-confirmation halt mid-cycle, not the cycle's END (rule 13); v1.2/R67 clears only at cycle END.

## §8 Programme board — CP3 (R50)
| Sub-cycle | State | Branch | PR | Review verdict | Merge |
|---|---|---|---|---|---|
| 3a Lows pass | **merged** | `cp/3a-lows` | [#11](https://github.com/rizwanoor80/eLearning-Platform/pull/11) | 0 Medium/High | `b75d6e9` (R50) |
| 3b state machine + ledger | **merged** | `cp/3b-state-machine` | [#12](https://github.com/rizwanoor80/eLearning-Platform/pull/12) | 14 findings, 1 FAIL(Low, scope) — resolved by R70 | `5471bcb` (R50, item 12 closed by owner ruling) |
| 3c booking | **mergeable — awaiting Owner action 1** | `cp/3c-booking` | [#13](https://github.com/rizwanoor80/eLearning-Platform/pull/13) | round 1: 1 Medium + 5 Low FAIL, closed by fix-loop items 1–6; round 2: 0 Medium/High, 1 new Low (routed to owner, non-blocking) | — |
| 3d cancellation | not started | `cp/3d-cancellation` | — | — | — |
| 3e dashboards, emails, deletion | not started | `cp/3e-dashboards-emails` | — | — | — |
| Programme end + rehearsal deploy | not started — halt | — | — | — | — |

Resume count: **0 of 8** (unchanged — this halt is a merge-confirmation gate, not a stop condition/resume per R69).

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
- The R53 onboarding live-as-you-type trial-price preview (Owner action E) — revisit if the UX loss is judged to matter.
- **New this cycle**: round-2 review's Low finding — no committed test for `BookLesson.php:163`'s broadened `Throwable` catch handling a non-`LessonTransitionException` failure (Owner action 2 above).
- **New this cycle**: three latent round-2 PASS-WITH-NOTE observations — after-commit-callback misreporting on a confirmed-and-paid lesson; `VerifyLedger` crashing (not failing cleanly) against a database missing `payments`; non-`PaymentCaptureException` gateway errors leaving a payment `pending` with no reconciliation path.
