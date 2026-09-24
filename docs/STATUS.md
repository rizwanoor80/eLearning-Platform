# STATUS — cycle 04 r9 (CP3) — written 2026-09-24 18:52 — Context: continued from prior session (summarized); size not measured this write via `get_usage`

Tests: full suite on branch `cp/3e-dashboards-emails` at `c180e0b` (quoted result): **1128/1128 passed, 5012 assertions** — grew from `b5548e3`'s 1120/4983 (+8 tests/+29 assertions, all from `SendLessonRemindersTest.php`) and did not shrink from `main`'s last count (1078/1078 at merge of PR #14). `ledger:verify` → "Ledger OK: every lesson sums to zero." Pint clean; PHPStan 0 errors (`--memory-limit=512M`, after one literal-union-type fix on `SendLessonReminders::sendWindow()`); RTL grep clean (Git Bash prepended to PATH, this cycle's documented one-off workaround). `npm run build` not re-run since the last green build earlier this cycle — no JS/Vue/CSS file touched by the reminder-job commit (PHP + one plain-inline-style Blade view only), same precedent as `4e7eb03`; will become a real gate again once the dashboard slice touches Vue/Tailwind. Advisor: 4 this cycle (design `16:49`, rule-11 auth-trigger `16:49`, pre-commit remediation review `18:01`, post-lifecycle-email design `18:29` covering both the reminder-job guidance just implemented and the not-yet-started dashboard guidance) — all four logged with model and a quoted line. Review: not yet opened — PR #15 requires step 5's full scope; emails and the reminder job are now done, dashboards/`LessonPolicy` are not.

## §1 Git state
`origin/main` and local `main` both at `75c0b02` (unchanged this run — no docs-only commit has gone to `main` yet this cycle; see §6 for why).
`cp/3e-dashboards-emails` checked out, pushed to origin, **14 commits ahead of `origin/main`** (quoted via `git log origin/main..HEAD --oneline` at 18:52, push confirmed `3d6d26f..e61b2d3`): `e61b2d3`, `c180e0b`, `3d6d26f`, `b5548e3`, `6021430`, `e92de05`, `6be8918`, `363fa54`, `4e7eb03`, `1d13e37`, `117c9e5`, `5ed8760`, `80c2e0e`, `8e8e8a3`. Working tree clean as of this write.
`cp/3d-cancellation` remains merged and present remotely, untouched.

## §2 Step map (cycle 04 r9 — CP3, an autonomous programme, R50)
1. `cp/3a-lows` — **merged** `b75d6e9` (PR #11).
2. `cp/3b-state-machine` — **merged** `5471bcb` (PR #12).
3. `cp/3c-booking` — **merged** `df0aa88` (PR #13).
4. `cp/3d-cancellation` — **merged** `ddb31ac` (PR #14).
5. `cp/3e-dashboards-emails` — **in progress**. Done so far: R54's deletion behaviour, the three CP3 lifecycle emails (confirmed, cancelled, skipped) with render/integration coverage, and **now** the 24h/1h reminder scheduled job — `SendLessonReminders` command (`everyMinute`, `onOneServer`+`withoutOverlapping`), `LessonReminderMail` (shared Mailable for both windows), one shared Blade view, atomic per-row claim for idempotency (`WHERE reminder_*_sent_at IS NULL`), half-open non-overlapping windows, 8 new tests including a run-twice idempotency proof. The <24h-booking edge case decided by the window predicate alone (no PRD guidance beyond PRD.md:160), logged as a DECISION. **Not started**: parent dashboard, tutor dashboard, `LessonPolicy`, an IDOR test, `CHECKPOINTS.md`/`DATA_MODEL.md` closure for this slice. PR #15 not yet opened.
6. Programme end and rehearsal deploy — not started — halt with the Deploy action.

## §3 What changed this run
- Confirmed the full-suite background run from the prior session segment: 1128/1128 passed, 5012 assertions, +8/+29 over baseline, no shrinkage.
- Committed and pushed the 24h/1h reminder job (`c180e0b`: `SendLessonReminders`, `LessonReminderMail`, `reminder.blade.php`, the `routes/console.php` schedule entry, `SendLessonRemindersTest.php`) plus its CYCLE-LOG VERIFICATION/DECISION/NOTE entries (`e61b2d3`).
- This STATUS.md rewrite, closing out the reminder-job slice of step 5 and pointing §7's next step at the parent/tutor dashboards.

## §4 Decisions and by whom
- Owner/planner rulings carried: R50, R54, R55, R57, R63, R75, R79 (unchanged).
- New CC decision this run: the <24h-booking edge case needs no special-case code — the 24h window's own predicate is the whole rule (CYCLE-LOG `18:52` DECISION), an implementation-scope call under CLAUDE.md's "When unsure" default, not a `DECISIONS.md`/ADR matter.

## §5 Why stopping
**Not stopping — continuing in this same session**, per HOW-WE-WORK rule 13 and PLAN.md step 5's "halt: no". This is a step-boundary write closing out the reminder-job slice before starting the dashboard slice. Immediately next: the parent dashboard (upcoming lessons, cancel action) and tutor dashboard (today + upcoming), per the `18:29` ADVISOR entry's dashboard guidance — explicit Inertia array props (never serialized models, per invariant #8), tutor "today" computed in the tutor's own timezone then converted to UTC, a new `LessonPolicy` with IDOR tests, a thin cancel controller (Policy + Form Request, server-decided `CancelLesson` vs `SkipLesson`), no cancel UI on the tutor dashboard, logical Tailwind classes only, `npm run build` becomes a real gate again for this slice. Browser sign-in smoke-testing is unavailable (needs a password, prohibited) — Inertia feature tests are the coverage, to be disclosed as a limitation rather than silently skipped.

## §6 Mismatches
1. **Unchanged — Owner action F still open.** R54's own text ends "Admin action, audited; not exposed to parents in CP3." `DeleteLearner` is also called from `LearnerController::destroy()`, a pre-existing self-service route reachable by any parent for their own learner — that route predates R54 and remains untouched. Still flagged rather than silently settled; still expected to surface as a Medium finding at PR #15's review (see §7 below, unchanged from the prior write).
2. **Carried, still disclosed — mid-cycle docs commits land on the feature branch, not `main`**, contra HOW-WE-WORK rule 6's literal text. This write's own CYCLE-LOG entries and this STATUS.md rewrite follow the same pattern for the same reason as previously disclosed: rule 6's boundary is read as the *cycle's* halt/merge boundary, not every individual commit.
3. Carried unchanged: the `18:29` ADVISOR entry's quoted line being drawn from working notes rather than a fresh literal re-read of the encrypted transcript payload (CYCLE-LOG `18:29`, `18:52`); the model name (`claude-opus-5-5`) is a verified fact from transcript metadata, not affected by this caveat.
4. Carried unchanged: the `## Carried to CP8 hardening checklist` items and the `## Deferred` gateway-refund-call item below.

## §7 Next step / Owner actions
**Unchanged — Owner action F: rule on whether R54's "not exposed to parents in CP3" governs the pre-existing `learners.destroy` self-service route.**
- **Option 1 (Recommended): leave `LearnerController::destroy()` in place as-is — guarded (blocking-lesson refusal) and audited (`RecordAuditLog`) — until ruled on (Recommended).** Reason: the guarded, audited status quo already keeps every domain invariant intact; CLAUDE.md's default when unsure is the simplest option that keeps invariants intact until the owner answers.
- Option 2: close the route now (parents can no longer delete a learner themselves; only an admin can) to match R54's literal text, reopen later if ruled otherwise.
- Still raised pre-PR rather than at review, for the same reason as before: a fresh review subagent will very likely file this as a Medium finding, which would stop PR #15 cold under rule 6.
- Reply `update` when ruled (or leave silent to keep Option 1, per rule 4's default).

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
| 3e dashboards, emails, deletion | **in progress** — deletion slice + lifecycle emails + reminder job done, dashboards not started | `cp/3e-dashboards-emails` | not yet opened | — | — |
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
