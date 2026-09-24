# STATUS — cycle 04 r8 (CP3) — written 2026-09-24 19:56 — Context: continued from prior session (summarized); size not measured this write via `get_usage`

Tests: full suite on `main` at `a369af5` (quoted result, post-merge R50 smoke run): **1147/1147 passed, 5175 assertions**. `ledger:verify` → "Ledger OK: every lesson sums to zero." Pint clean; PHPStan 0 errors; RTL grep clean. HTTP smoke (`curl`, local `project-elearning.test`): `/` → 200, `/login` → 200, `/admin/login` → 200. Advisor: 4 this cycle (unchanged from the last write — design `16:49`, rule-11 auth-trigger `16:49`, pre-commit remediation review `18:01`, post-lifecycle-email design `18:29`); all four logged with model and a quoted line. Review: PR #15's fresh-subagent adversarial review is done — **18 PASS, 2 PASS WITH NOTE, 0 FAIL**, no Medium/High finding, logged as a REVIEW entry at `19:47`. PR #15 squash-merged to `main` (`a369af5`), CI green (`pass 1m59s`).

## §1 Git state
`origin/main` and local `main` both at `a369af5` (fast-forwarded this run after PR #15's merge). `cp/3e-dashboards-emails` remains pushed and merged, not deleted (CLAUDE.md's branch-deletion rule only permits deleting a merged `hold/<step>`, so this feature branch is left in place). `cp/3d-cancellation` remains merged and present remotely, untouched.

## §2 Step map (cycle 04 r8 — CP3, an autonomous programme, R50)
1. `cp/3a-lows` — **merged** `b75d6e9` (PR #11).
2. `cp/3b-state-machine` — **merged** `5471bcb` (PR #12).
3. `cp/3c-booking` — **merged** `df0aa88` (PR #13).
4. `cp/3d-cancellation` — **merged** `ddb31ac` (PR #14).
5. `cp/3e-dashboards-emails` — **merged** `a369af5` (PR #15). All five CP3 sub-cycles are now closed.
6. Programme end and rehearsal deploy — **in progress, halted**. Per PLAN.md step 6: docs-only commit (this write), STATUS §8 complete — both done this run. The deploy step itself is Owner action 1 below; the post-deploy read-only verification and the CP4 carried-forward list are written after the owner presses Deploy and replies `update`. `halt: yes` per PLAN.md's own text for this step.

## §3 What changed this run
- Confirmed PR #15's merge (`a369af5`, CI green) and fast-forwarded local `main` to match.
- Ran R50's post-merge read-only smoke pass on `main`: full suite (1147/1147, 5175 assertions), `ledger:verify`, and `curl` on `/`, `/login`, `/admin/login` (all 200). Logged as a CYCLE-LOG NOTE at `19:56`.
- Confirmed `docs/CHECKPOINTS.md`'s CP3 section already carries all 6 Acceptance boxes `[x]` — no edit needed there.
- This STATUS.md rewrite: closes the step map at all five sub-cycles merged, completes §8's programme board, and raises Owner action 1 (press Deploy) as PLAN.md step 6 requires — the first CP3 halt in this cycle that is a genuine owner-only action, not a design question CC can default its way past. CC has no Forge/Hetzner/GitHub deploy credentials and does not perform deploys or remote migrations under any circumstance (CLAUDE.local.md, R61).

## §4 Decisions and by whom
- Owner/planner rulings carried: R50, R54, R55, R57, R61, R63, R75, R79 (unchanged).
- No new CC decision this run — this is a mechanical merge-confirmation and smoke-test write, not a design judgment call.

## §5 Why stopping
**Halting — genuine owner-only action required, not a clear.** Per HOW-WE-WORK rule 13, a halt for an owner GO is not a session clear; this session waits and resumes on the next `update`. PLAN.md step 6 names this exact halt: "§7 'Owner action 1: press Deploy in Forge on trustutor-rehearsal, then reply `update`'." CC cannot press Deploy itself — no deploy credentials, and CLAUDE.local.md's server allow-list and R61 both forbid it explicitly, matching CC's own standing rule against performing deploys or remote migrations without the person who holds that authority doing it themselves. Once the owner presses Deploy and replies `update`, the next step is read-only verification against `trustutor-rehearsal` (`migrate:status`, `horizon:status`, an HTTPS 200 check, `ledger:verify` clean — all on CLAUDE.local.md's read-only allow-list) followed by writing the CP4 carried-forward list, which closes PLAN.md step 6 and this cycle for real.

## §6 Mismatches
1. **Unchanged — Owner action F still open, now formally carried past merge.** R54's own text ends "Admin action, audited; not exposed to parents in CP3." `LearnerController::destroy()` remains a pre-existing self-service route reachable by any parent for their own learner, predating R54. The PR #15 review (finding 15, `19:47`) independently re-examined this and explicitly declined to file it as a FAIL, calling the current code "safe either way (guarded + audited)" and recommending it stay open pending the owner's ruling — so it did not block the merge, but the underlying product question is still unanswered and carries into CP4 planning if the owner doesn't rule on it here.
2. **New this write, disclosed rather than silently fixed — PR #15 review finding 14** (`AnonymizeUser`'s admin-only check lives inside the Action, not behind a route-level Policy/Gate). Not exploitable today (no HTTP caller exists for this Action in the merged code), but flagged for whoever wires up an admin UI/Filament resource around it later to add an outer authorization layer rather than relying on the Action's internal check alone. Carried to the CP8 hardening checklist below rather than treated as a CP3 blocker, since CP3's Filament admin surface for user management is out of this cycle's scope.
3. Carried, still disclosed — mid-cycle docs commits land on the feature branch, not `main`, contra HOW-WE-WORK rule 6's literal text (this cycle's established, previously-disclosed reading: rule 6's boundary is the *cycle's* halt/merge boundary, not every individual commit). This particular write is a genuine post-merge, on-`main` docs commit, so it does not itself carry that caveat.
4. Carried unchanged: the `18:29` ADVISOR entry's quoted line being drawn from working notes rather than a fresh literal re-read of the encrypted transcript payload; the model name (`claude-opus-5-5`) is a verified fact from transcript metadata, not affected by this caveat.
5. Carried unchanged: the `## Carried to CP8 hardening checklist` items and the `## Deferred` gateway-refund-call item below.

## §7 Next step / Owner actions
**Owner action 1 (PLAN.md step 6, Recommended: press it — Recommended): press Deploy in Forge on `trustutor-rehearsal`, then reply `update`.** This is the programme's designed stopping point — CP3 is fully merged to `main`, the rehearsal server is provisioned and previously verified reachable (CLAUDE.local.md, 2026-09-19), and zero-downtime deploy is on. Reason this is the recommended and only real option: PLAN.md's step 6 text names pressing Deploy as the entire remaining action; there is no CC-side alternative that keeps the invariant of "no remote migration or deploy by CC" intact. Once done, CC will read-only-verify (`migrate:status`, `horizon:status`, an HTTPS 200 on the rehearsal URL, `ledger:verify`) and write the CP4 carried-forward list, closing this cycle.

**Owner action F (unresolved, carried from 3e, does not block the deploy):** rule on whether R54's "not exposed to parents in CP3" governs the pre-existing `learners.destroy` self-service route.
- **Option 1 (Recommended): leave `LearnerController::destroy()` in place as-is — guarded and audited — until ruled on (Recommended).** Reason: the guarded, audited status quo already keeps every domain invariant intact; CLAUDE.md's default when unsure is the simplest option that keeps invariants intact until the owner answers.
- Option 2: close the route now (only an admin can delete a learner) to match R54's literal text, reopen later if ruled otherwise.
- Reply `update` when ruled (or leave silent to keep Option 1, per rule 4's default) — this does not need to be answered before Owner action 1.

Non-blocking, carried unchanged:
- Owner action C (learner-side overlap constraint) — **Recommended: leave as-is (Recommended)**.
- Owner action E (R53 live-as-you-type preview UX loss, revisit at CP8) — **Recommended: leave as Option A for v1 (Recommended)**.
- Owner action A (branch protection on `main`, R7); Owner action B (`.claude/settings.local.json`, ADR-002) — both optional, carried.

## §8 Programme board — CP3 (R50) — complete
| Sub-cycle | State | Branch | PR | Review verdict | Merge |
|---|---|---|---|---|---|
| 3a Lows pass | **merged** | `cp/3a-lows` | [#11](https://github.com/rizwanoor80/eLearning-Platform/pull/11) | 0 Medium/High | `b75d6e9` (R50) |
| 3b state machine + ledger | **merged** | `cp/3b-state-machine` | [#12](https://github.com/rizwanoor80/eLearning-Platform/pull/12) | 14 findings, 1 FAIL(Low, scope) — resolved by R70 | `5471bcb` (R50, item 12 closed by owner ruling) |
| 3c booking | **merged** | `cp/3c-booking` | [#13](https://github.com/rizwanoor80/eLearning-Platform/pull/13) | round 1: 1 Medium + 5 Low FAIL, closed by fix-loop items 1–6; round 2: 0 Medium/High, 1 new Low (disposed as 3d's first commit) | `df0aa88` (R50, R55 GO by owner) |
| 3d cancellation | **merged** | `cp/3d-cancellation` | [#14](https://github.com/rizwanoor80/eLearning-Platform/pull/14) | round 1: 13 PASS, 2 PASS WITH NOTE, 1 FAIL(Medium), 1 FAIL(Low), fixed; re-review: 14 PASS, 5 PASS WITH NOTE, 0 FAIL | `ddb31ac` (R50/R55, self-merge) |
| 3e dashboards, emails, deletion | **merged** | `cp/3e-dashboards-emails` | [#15](https://github.com/rizwanoor80/eLearning-Platform/pull/15) | 18 PASS, 2 PASS WITH NOTE, 0 FAIL | `a369af5` (R50/R55, self-merge) |
| Programme end + rehearsal deploy | **halted — awaiting Owner action 1** | — | — | — | — |

Resume count: **0 of 8** (unchanged — no stop condition hit this cycle; this halt is a designed owner-GO wait, not a resume-cap event).

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
- PR #15 review finding 14: `AnonymizeUser`'s admin-only check is Action-internal, not behind a route-level Policy/Gate — add an outer authorization layer when an admin UI/Filament resource is wired up around it.
