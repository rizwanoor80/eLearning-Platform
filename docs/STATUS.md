# STATUS — cycle 04 r8 (CP3) — written 2026-09-24 22:39 — Context: continued from a summarized prior segment; window at 136,973/200,000 tokens (68%) as of 22:37 (informational only — this is not a HANDOFF write, so no `/clear` is raised)

Tests: full suite on `main` at `e5d31f1` (unchanged since the last local run — this write adds no code): **1147/1147 passed, 5175 assertions**. `ledger:verify` on local `main` → "Ledger OK: every lesson sums to zero" (last run `19:56`). Pint clean; PHPStan 0 errors; RTL grep clean. On `trustutor-rehearsal` this run: `migrate:status` → all 37 migrations `Ran` (batch [2] = the 7 CP3 migrations, matching the owner's report exactly); `horizon:status` → "Horizon is running"; HTTPS smoke on the public rehearsal domain → `/`, `/login`, `/admin/login` all 200. `ledger:verify` on rehearsal is the one PLAN.md step 6 item **not yet run** — see §5/§6/§7. Advisor: 5 this cycle (4 carried + 1 new `22:37` consultation on the plan/allow-list conflict — logged as a **tool failure**, not a counted consultation, because the answering model could not be verified from inspectable metadata; the advice itself was substantive and is being followed). Review: PR #15's review (18 PASS, 2 PASS WITH NOTE, 0 FAIL) stands unchanged; merged `a369af5`.

## §1 Git state
`origin/main` and local `main` both at `e5d31f1` (the step-6 halt-record commit from the prior write, confirmed unchanged by `git fetch` across three quiet check-ins before this `update`). This write adds a further docs-only commit on top, to be pushed to `main` with `[skip ci]` per R57/rule 6.

## §2 Step map (cycle 04 r8 — CP3, an autonomous programme, R50)
1.–5. All five CP3 sub-cycles **merged** (3a `b75d6e9`, 3b `5471bcb`, 3c `df0aa88`, 3d `ddb31ac`, 3e `a369af5`) — unchanged from the last write.
6. Programme end and rehearsal deploy — **in progress, halted again, one gate short of done.** Owner action 1 (press Deploy) is satisfied: the owner deployed `e5d31f1` to `trustutor-rehearsal`, 7 migrations ran. Of PLAN.md step 6's named read-only verification (`migrate:status`, `horizon:status`, HTTPS 200, `ledger:verify` clean), three of four are done and green this run; `ledger:verify` on rehearsal was withheld because it is not on CLAUDE.local.md's binding allow-list for that server (see §6). The CP4 carried list (also required by step 6) is written below regardless, on the advisor's explicit instruction that it doesn't depend on the missing check. `halt: yes` stands — but this is still an owner-GO halt, not the cycle's true END, because step 6's own text requires "`ledger:verify` clean" before the step closes.

## §3 What changed this run
- Logged the owner's `22:35` ADVICE in-chat message (Deploy done, `e5d31f1`/7 migrations, plus the `composer.lock` drift warning from the Forge deploy log) as a CYCLE-LOG ADVICE entry before acting on any of it, per rule 8.
- Ran the three CLAUDE.local.md-allow-listed read-only checks against `trustutor-rehearsal`: `migrate:status` (37/37 Ran, batch [2] = the expected 7), `horizon:status` (running), HTTPS smoke on `/`, `/login`, `/admin/login` (all 200). Logged as a CYCLE-LOG VERIFICATION entry, `22:37`.
- Identified a **plan/security-boundary conflict**: PLAN.md step 6 names `ledger:verify` as part of this verification, but CLAUDE.local.md's explicit "What CC may do on trustutor-rehearsal" list does not include it, and states "Nothing else is read-only by assumption: if a command is not on this list, ask." Per rule 11 (a plan–repo conflict is an advisor trigger), consulted the advisor before deciding. Advice: hold the line — CLAUDE.local.md is binding over PLAN.md text (rule 9); this is not the cycle's END since step 6's own gate is unmet; write the CP4 list now anyway; a migration-count error in the draft VERIFICATION entry (36 vs. the actual 37) was caught and corrected before logging. Logged as a CYCLE-LOG ADVISOR entry, `22:37` — as a **tool failure** (model name unverifiable this call), so it does not count toward this cycle's consultation minimum, though its advice is being followed in full.
- Corrected `docs/CHECKPOINTS.md`'s CP3 heading from "in progress" to "merged, deployed to trustutor-rehearsal" (Low, doc-only, fixed in-cycle per rule 6); read back via `git diff` before committing.
- Wrote the CP4 carried list (below) per PLAN.md step 6's requirement, independent of the outstanding `ledger:verify` gate.
- This STATUS.md rewrite; a further Owner action (2) raised in §7.

## §4 Decisions and by whom
- Owner/planner rulings carried: R50, R54, R55, R57, R61, R63, R75, R79 (unchanged).
- CC decision this run: withhold `ledger:verify` on rehearsal rather than run it on the assumption it's harmless, because CLAUDE.local.md explicitly requires asking when a command isn't on the allow-list — confirmed correct by the advisor, not a unilateral call.
- CC decision this run: fix the CP3 CHECKPOINTS.md heading in-cycle (Low, doc-only, rule 6) rather than only disclosing it.
- CC decision this run: do not run `composer update`/regenerate `composer.lock` on the strength of the owner's ADVICE alone — the owner explicitly asked for it to be logged and carried to CP4, not fixed now, and CC does not touch the shared lockfile without the plan naming it as a task.

## §5 Why stopping
**Halting again — a second, narrower owner-only gate, not a clear.** PLAN.md step 6 requires `ledger:verify` clean on `trustutor-rehearsal` before this step (and the cycle) closes. CC cannot run it: it is absent from CLAUDE.local.md's binding, explicit allow-list for this server, and that file's own text says to ask rather than assume. This is not the cycle's true END per HOW-WE-WORK rule 13 — step 6's stated gate is unmet, so writing a HANDOFF entry, an END entry, or raising a `/clear` request now would self-certify a gate the plan states, which rule 8 forbids. The session waits and resumes on the next `update`, exactly as after Owner action 1.

## §6 Mismatches
1. **New this write — plan/allow-list conflict, resolved conservatively.** PLAN.md step 6 names `ledger:verify` as part of the rehearsal read-only verification; CLAUDE.local.md's "What CC may do on trustutor-rehearsal" list does not include it and states "Nothing else is read-only by assumption: if a command is not on this list, ask." Advisor-confirmed: the security-boundary file governs. Not run. Owner action 2 (§7) asks the owner to either widen the allow-list or run/paste it themselves.
2. Carried — Owner action F (`LearnerController::destroy()` vs. R54's "not exposed to parents" text) — still open, still non-blocking, unchanged since 3e.
3. Carried — PR #15 review finding 14 (`AnonymizeUser`'s admin-only check is Action-internal, not Policy/Gate-guarded) — non-blocking, carried to the CP4/CP8 lists below.
4. Carried — mid-cycle docs commits land on the feature branch, not `main` (established reading: rule 6's boundary is the cycle's halt/merge boundary, not every commit); this write, like the prior one, is a genuine on-`main` docs commit and doesn't carry that caveat itself.
5. Carried unchanged — the `18:29` ADVISOR entry's quoted line was drawn from working notes, not a fresh literal re-read; the `22:37` ADVISOR entry this run could not name its model at all and is logged as a tool failure accordingly (see §3).

## §7 Next step / Owner actions
**Owner action 1 — done.** Deploy pressed in Forge on `trustutor-rehearsal`; `e5d31f1` live, 7 migrations ran, confirmed by this run's `migrate:status`.

**Owner action 2 (new, blocks closing PLAN.md step 6 and this cycle): resolve the `ledger:verify`-on-rehearsal gate, then reply `update`.**
- **Option 1 (Recommended): add `php8.4 artisan ledger:verify` to CLAUDE.local.md's rehearsal read-only allow-list (Recommended).** Reason: it's a pure read/sum-check with no side effects, PLAN.md step 6 already expects it to run here, and this keeps future post-deploy verifications self-serve for CC without another round-trip.
- Option 2: the owner runs `ssh -i "$HOME/.ssh/trustutor_cc" forge@167.233.122.19 "cd /home/forge/rehearsal.trustutor.com/current && php8.4 artisan ledger:verify"` themselves and pastes the output into chat; CC logs it as-is and closes the step without an allow-list change.
- Reply `update` when either is done.

**Owner action F (unresolved, non-blocking, carried from 3e):** rule on `LearnerController::destroy()` vs. R54. Recommended: leave as-is (guarded + audited) until ruled on — unchanged from the last write.

Non-blocking, carried unchanged: Owner action C (learner-side overlap constraint), Owner action E (R53 preview UX, revisit CP8), Owner action A (branch protection), Owner action B (`.claude/settings.local.json`).

## §8 Programme board — CP3 (R50) — complete except the final verification gate
| Sub-cycle | State | Branch | PR | Review verdict | Merge |
|---|---|---|---|---|---|
| 3a Lows pass | **merged** | `cp/3a-lows` | [#11](https://github.com/rizwanoor80/eLearning-Platform/pull/11) | 0 Medium/High | `b75d6e9` |
| 3b state machine + ledger | **merged** | `cp/3b-state-machine` | [#12](https://github.com/rizwanoor80/eLearning-Platform/pull/12) | 14 findings, 1 FAIL(Low, scope) — resolved by R70 | `5471bcb` |
| 3c booking | **merged** | `cp/3c-booking` | [#13](https://github.com/rizwanoor80/eLearning-Platform/pull/13) | round 2: 0 Medium/High | `df0aa88` (R55 GO) |
| 3d cancellation | **merged** | `cp/3d-cancellation` | [#14](https://github.com/rizwanoor80/eLearning-Platform/pull/14) | re-review: 14 PASS, 5 NOTE, 0 FAIL | `ddb31ac` |
| 3e dashboards, emails, deletion | **merged** | `cp/3e-dashboards-emails` | [#15](https://github.com/rizwanoor80/eLearning-Platform/pull/15) | 18 PASS, 2 NOTE, 0 FAIL | `a369af5` |
| Programme end + rehearsal deploy | **deployed; halted on the `ledger:verify` gate (Owner action 2)** | — | — | — | `e5d31f1` deployed |

Resume count: **0 of 8** (unchanged — both halts this cycle are designed owner-GO waits, not resume-cap events).

## CP4 carried list (PLAN.md step 6 deliverable — for the planner's "where are we?")
1. **`composer.lock` drift** — the Forge deploy log on `e5d31f1` warned `composer.lock` is not up to date with `composer.json` (owner's `22:35` ADVICE). Not diagnosed or fixed this cycle. First CP4 item: identify which dependency changed, run `composer update <package>` (not a blanket `composer update`) or regenerate the lock deliberately, `composer test` green, commit the lock file alone.
2. **Owner action F** — `LearnerController::destroy()` vs. R54's "admin action, not exposed to parents in CP3" text — still unruled. Does not block CP4 unless CP4 work touches learner deletion.
3. **PR #15 review finding 14** — `AnonymizeUser`'s admin-only check is Action-internal, not behind a route-level Policy/Gate. Add an outer authorization layer whenever an admin UI/Filament resource is wired up around it (not a CP4 task by default; flag if CP4 touches admin tooling).
4. **`ledger:verify` missing from the `trustutor-rehearsal` allow-list** (this run's Owner action 2) — once resolved, consider whether the allow-list should also gain it for `trustutor-production` when that server exists, so this doesn't recur.
5. Everything already in the `## Carried to CP8 hardening checklist` section below (search pagination, expired-lesson refund needing a real gateway, R53 preview UX, the three round-2 PASS-WITH-NOTE observations, the multi-process booking race test, the learner-side overlap constraint, the `SuspendTutorForStrikes` reconciliation command, PR #14's 5 NOTE items) — unchanged, still CP8-horizon, listed here only for the planner's visibility while triaging CP4 scope.
6. Owner action A (branch protection on `main`) and Owner action B (`.claude/settings.local.json`) — both optional, still open.

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
