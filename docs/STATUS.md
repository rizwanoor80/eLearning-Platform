# STATUS — cycle 04 r8 (CP3) — written 2026-09-24 08:52 — Context: 164.4k/200k (82%, auto-compacts at 84%; measured via `get_usage` — v1.2/R67)
Tests: full `composer test`, latest completed run (item 6, this write's own work): **1053/1053 passed, 4835 assertions**, `ledger:verify` green — Pint, PHPStan (0 errors) and RTL grep all passed. Up from 1040/4739 before item 6 (items 1–5 already landed in earlier writes this cycle). CI: not yet re-checked this write — nothing has been pushed since item 6 landed; see §5, this is a push-confirmation halt, not a review-finding halt. Advisor: 2 this write (pre-implementation design consult for item 6 — verbatim unrecoverable from the session transcript, disclosed rather than fabricated, see CYCLE-LOG `08:52` ADVISOR entry; post-implementation verification consult, quoted) — cycle 04 total now 20. Review: 3a done (0 Medium/High); 3b done (14 findings, 1 FAIL(Low, scope), resolved by R70, merged); **3c: round-1 review's 1 Medium + 5 Low findings are now all closed by PLAN r8's R77 fix-loop round (items 1–6, all six implemented and green on `cp/3c-booking`) — round 2 (the last of cap 2) has not run yet; it runs after this write's push is confirmed (R77 item 7).**

## §1 Git state
`origin/main` at `3e4026b` (PLAN r8, planner-pushed). Local `main` is 4 commits ahead, all docs-only `[skip ci]`, none yet pushed: `5c97c1e`/`cabad25`/`71ee95c`/`b3ce3e6` (CYCLE-LOG entries for R77 items 1–2, 3, 4, 5). Verified no divergence: `git merge-base main origin/main` == `origin/main`'s HEAD exactly (`3e4026b`) — local `main` is a clean fast-forward ahead, not diverged.
`cp/3c-booking` (current branch) is at `f8d2d57` (R77 item 6 code+tests), built on `34d255b` ("Merge branch 'main' into cp/3c-booking", which already carries `3e4026b`). Not yet pushed to `origin/cp/3c-booking`: nine commits (`4984760`…`f8d2d57`, i.e. all of R77 items 1–6 plus their three merge-`main`-in commits and CYCLE-LOG entries) — see the full list in CYCLE-LOG.
Working tree, at the start of this write: `docs/CYCLE-LOG.md` and `docs/STATUS.md` both modified-uncommitted on `cp/3c-booking` (the item-6 CYCLE-LOG entry, and this rewrite). Per R79 these must land on `main`, not the feature branch — moving them via the stash technique immediately after this write, before requesting push confirmation (see §5).
PR #13 open against `main`, still describing the pre-R77 (round-1-only) state; its body will be updated once round 2 runs. trustutor-rehearsal unchanged, behind `main` by design (R41), not touched this cycle.

## §2 Step map (cycle 04 r8 — CP3, an autonomous programme, R50)
1. `cp/3a-lows` — **merged** `b75d6e9` (PR #11).
2. `cp/3b-state-machine` — **merged** `5471bcb` (PR #12).
3. `cp/3c-booking` — **R77 fix-loop round in progress, item 7 of 7 remaining.** PLAN r8 authorised Option 1 (fix-loop round 1 of cap 2) for round-1's 1 Medium + 5 Low findings; items 1–6 are all implemented and green (suite 1053/1053, 4835 assertions). Item 7 (this and the next write): full re-verify at final head → push (needs owner chat confirmation, standing rule) → confirm CI green → round-2 fresh-subagent adversarial review (`opus`) briefed with round-1's findings → if clean, R55's GO already stands (conditional, met) → merge PR #13 (needs owner chat confirmation) → post-merge record → continue straight into step 4.
4. `cp/3d-cancellation` — not started, blocked behind 3c's merge.
5. `cp/3e-dashboards-emails` — not started.
6. Programme end and rehearsal deploy — not started — halt with the Deploy action.

## §3 What changed this run
- R77 item 6 implemented and verified on `cp/3c-booking`: `tests/Unit/MoneyTest.php:40`'s inverted comment fixed; `tests/Feature/Tutor/TutorOnboardingBankSubjectsRateTest.php`'s onboarding-preview band-table dataset widened from the synthetic-plus-one-seeded-band case to all three seeded `LevelTier::band()` cases (3→9 rows, CBSE/Exam1/Exam2-style tiers, using a new optional `CurriculumCode` parameter on `subjectsReadyTutor()`); `tests/Feature/Lessons/BookLessonTest.php`'s booked-price band-table dataset widened the same way (3→9 rows) via a new `bookableTutorSetupForTier()` helper (named to avoid the existing `bookableTutorSetup()` under Pest's global-function scope rule); one new test added comparing the Inertia onboarding-preview prop directly against a booked lesson's frozen price for the same tutor, closing the "agreement only via transitivity" gap (round-1 finding #5).
- Committed as `f8d2d57`. Full suite re-run at that head: **1053/1053 passed, 4835 assertions** (+13 over the 1040/4739 baseline: +6/+6/+1, matching item 6's three additions exactly), `ledger:verify` green, Pint/PHPStan(0 errors)/RTL all green. Standalone-file PHPStan runs on the three touched files produced ~64 apparent errors, all triaged: 63 were the known isolated-file false-positive pattern (Pest macros/global test functions unresolved outside a full-project run) and 1 was real — `TutorProfile.php`'s `$approved_at` docblock typed `Carbon` while `AppServiceProvider`'s `Date::use(CarbonImmutable::class)` makes `now()` return `CarbonImmutable`; fixed by using `forceFill()` (matching the existing `ApproveTutor` action's pattern) instead of a plain `->update()`, which also happened to be required anyway since `status`/`approved_at` are not in `TutorProfile::$fillable`.
- Two ADVISOR consults this write (both logged in CYCLE-LOG `08:52`): pre-implementation (confirmed CBSE spans all three `LevelTier` cases, flagged the Pest scope trap, required the no-transitivity test to compare the prop directly against the booked price rather than via `trialPrice()` twice — genuinely unrecoverable verbatim from the session transcript, `advisor_redacted_result` is encrypted with no paired plaintext `thinking`, confirmed by direct inspection; disclosed as paraphrase, not presented as a quote per R75); post-implementation (quoted: *"The tier datasets went from 3 to 9 cases in both files, plus one new test, which is +13 and agrees with 1040 → 1053."* — plus an instruction to disclose that the band-table tests build `PriceBand` rows directly from `$tier->band()`, the same source `PriceBandSeeder`/`PriceBandFactory` read, rather than by running the seeder itself; disclosed here and in CYCLE-LOG so round 2 does not flag it as a gap).
- This write: STATUS.md rewritten in full (this content); `docs/CYCLE-LOG.md`'s item-6 entry was already written in the prior write and sits alongside this rewrite as the two files to move to `main` next via the stash technique.

## §4 Decisions and by whom
- Owner/planner rulings carried: R70, R71, R72, R73 (pre-R77).
- **Planner ruling this cycle (PLAN r8, R77)**: authorised Option 1 from the prior write's Owner action 1 — a fix-loop round (round 1 of cap 2) covering round-1 review's Medium + 5 Low findings, itemised as R77 items 1–6, plus a 7th item (re-verify/push/round-2-review/merge sequence). This supersedes the prior write's open "Owner action 1" — it is now answered, not outstanding.
- **R78 (planner, this cycle)**: defers the `payments.lesson_id` UNIQUE-vs-recurring-retry conflict (§6, prior write) to the CP5 plan — no CP3 migration needed; makes the `btree_gist` privilege confirmation on trustutor-rehearsal the planner's job, not a merge condition for this PR.
- **R79 (planner, this cycle)**: restates and closes four process defects — STATUS.md/CYCLE-LOG.md are written on `main` and merged into the feature branch, never the reverse; check `git branch --show-current` before every docs commit.
- CC decisions carried from the design phase (unchanged): gateway resolution unbound/test-only (invariant 16, CP5-deferred), commission-split formula, tutor overlap via additive `EXCLUDE USING gist`, `tsrange` not `tstzrange`, `payments` row committed before `LessonStateMachine::transition()` runs, no learner-side overlap constraint in v1, a declined capture writes its own `failed` `payments` row before the lesson expires.
- Carried, R53 Option A over Option B (live-as-you-type onboarding preview removed) — unchanged, still a §6/§7 item for the owner to weigh at CP8.
- CC decision this write: item 6's helper naming (`bookableTutorSetupForTier()`) and full test self-containment, specifically to avoid the Pest global-function redeclaration trap the advisor flagged pre-implementation.

## §5 Why stopping
**This is a push-confirmation halt under this session's own standing rule, not a PLAN/GO gate and not the cycle's END** (rule 13: no §5 context handoff beyond this paragraph, no HANDOFF entry, no `/clear` Owner action yet). PLAN r8's R77 already authorised the fix-loop round and states R55's GO stands, conditional on round-2 review being clean and CI green — that condition is not yet tested, because nothing has been pushed since item 6 landed. Per this session's standing instruction (confirmed earlier in this conversation, independent of and in addition to CLAUDE.md's own rules): local work — commits, merges, edits, test runs — proceeds without asking, but any `git push` to `origin` or any PR merge requires an explicit "yes" from the owner in chat first. That confirmation has not yet been requested (see §7, Owner action 1, raised for the first time in this write). Context is 82% at this write (v1.2/R67: a context percentage is never itself a stop reason; auto-compaction at 84% is expected and not a halt) — the halt is driven solely by the pending push confirmation.

## §6 Mismatches
- **Resolved this cycle (was Owner action 1, prior write)**: the Medium finding (`BookLesson.php` stranding a captured payment against a lesson stuck at `pending_payment`) is closed by R77 items 1–3 (cap `trial_discount_pct` at 99, pre-capture zero/negative-price guard, post-capture `Throwable` handling + `strandedPayments()` detection). Round-2 review will confirm.
- **Resolved this cycle**: round-1 Low findings #2/#3 (`ExpireUnpaidLessons` post-lock re-check, `lazyById()` pagination) closed by item 4; #4 (float/round() grep coverage) closed by item 5; #5 (band-table agreement only via transitivity) and the `MoneyTest.php:40` comment closed by item 6 (this write).
- **Deferred to CP5 by the planner (R78, this cycle)** — no longer an open mismatch for this PR: `payments.lesson_id` UNIQUE vs. the PRD's CP4/CP5 "retry a declined weekly charge up to 3 times on the same reserved lesson" design. Noted on the CP5 carry-forward list.
- **Reassigned to the planner by R78, this cycle** — no longer a merge condition for this PR: confirming the `trustutor-rehearsal` Postgres user can run `CREATE EXTENSION IF NOT EXISTS btree_gist` before the R61 deploy step. Still true that CI's superuser run doesn't prove this; the planner now owns getting it confirmed before R61.
- Carried, unchanged: `composer test`'s `"test"` script lacks `disableProcessTimeout`, worked around with `COMPOSER_PROCESS_TIMEOUT=1200` plus backgrounding from the start.
- Carried, unchanged: rehearsal behind `main` by design (R41); branch protection on `main` not set (R23, Owner action A, non-blocking).
- Carried, unchanged: Owner action C (learner-side overlap constraint) and Owner action E (R53 live-preview UX loss) — see §7.

## §7 Next step / Owner actions
**Owner action 1 (blocking this cycle's continuation — session standing rule, not a PLAN gate).** R77 items 1–6 are complete and green (`cp/3c-booking` @ `f8d2d57`, 1053/1053 tests, 4835 assertions). Before item 7 can proceed (push → CI check → round-2 review → merge), this session's own confirmed working rule requires an explicit "yes" in chat before any `git push` to `origin`. Two pushes are needed:
  1. `origin/main` ← local `main`'s 4 unpushed docs-only `[skip ci]` commits (`5c97c1e`, `cabad25`, `71ee95c`, `b3ce3e6`), plus one more about to be added (this write's STATUS.md + the item-6 CYCLE-LOG entry, moved from `cp/3c-booking` via the stash technique).
  2. `origin/cp/3c-booking` ← the branch's commits since its last push (all of R77 items 1–6, `4984760`…`f8d2d57`, nine commits including three "merge main in" commits), which will pick up one more merge-`main`-in commit once step 1 lands and is merged back.

  **Recommended: approve both pushes together (Recommended)** — nothing in either push is a merge to `main`'s protected content beyond docs, and the code push is to the still-open, still-unmerged PR #13 branch; approving lets item 7 proceed to the CI check and round-2 review in this same session. Reply `update` (or explicit approval in chat) naming both pushes to proceed; the round-2 review and the eventual merge each raise their own confirmation request when reached.

Non-blocking, does not gate Owner action 1:
- Carried, unchanged: Owner action C (learner-side overlap constraint) — **Recommended: leave as-is (Recommended)**.
- Carried, unchanged: Owner action E (R53 live-as-you-type preview UX loss, revisit at CP8) — **Recommended: leave as Option A for v1 (Recommended)**.
- Carried, optional: Owner action A (branch protection on `main`, R7); Owner action B (`.claude/settings.local.json`, ADR-002).

The standing clear action is **not** raised here — this is a push-confirmation halt mid-cycle, not the cycle's END (rule 13); v1.2/R67 clears only at cycle END.

## §8 Programme board — CP3 (R50)
| Sub-cycle | State | Branch | PR | Review verdict | Merge |
|---|---|---|---|---|---|
| 3a Lows pass | **merged** | `cp/3a-lows` | [#11](https://github.com/rizwanoor80/eLearning-Platform/pull/11) | 0 Medium/High | `b75d6e9` (R50) |
| 3b state machine + ledger | **merged** | `cp/3b-state-machine` | [#12](https://github.com/rizwanoor80/eLearning-Platform/pull/12) | 14 findings, 1 FAIL(Low, scope) — resolved by R70 | `5471bcb` (R50, item 12 closed by owner ruling) |
| 3c booking | **R77 fix-loop round in progress** — items 1–6 done and green; item 7 (push/CI/round-2-review/merge) awaiting Owner action 1 | `cp/3c-booking` | [#13](https://github.com/rizwanoor80/eLearning-Platform/pull/13) | round 1: 1 Medium + 5 Low FAIL, all now closed by fix-loop items 1–6; round 2 not yet run | — |
| 3d cancellation | not started | `cp/3d-cancellation` | — | — | — |
| 3e dashboards, emails, deletion | not started | `cp/3e-dashboards-emails` | — | — | — |
| Programme end + rehearsal deploy | not started — halt | — | — | — | — |

Resume count: **0 of 8** (unchanged — this halt is a push-confirmation gate, not a stop condition/resume per R69).

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
- Review finding #2/#3 (sweep post-lock re-check, `lazyById()` pagination) — closed this cycle by R77 item 4; no longer carried.
