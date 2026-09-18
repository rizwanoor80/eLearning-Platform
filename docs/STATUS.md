# STATUS — cycle 02 r4 — written 2026-09-19 10:15
Tests: 219 (up from 205) · Advisor: consulted 3 times this sub-cycle (1c design 15:05, pre-PR 17:24, halt 20:41) · Review: 1a: 9 PASS, merged; 1b: three rounds, merged; 1c: 1st review 14 PASS/notes + 1 Medium + 1 Low (fixed via R28/R29), re-review pending — round 1 of 2

## §1 Git state
Cycles 01, sub-cycles 1a and 1b merged (`646ce06`, `c08bad8`, `10e6198`). [PR #4](https://github.com/rizwanoor80/eLearning-Platform/pull/4) (`cp/1c-admin-approval`) carries the R28/R29 fix (`b5bc351`), suite green (219/743), description corrected; CI on the new head and the re-review are next. `main`'s branch protection: **not set** (owner action, carried, R23). `.claude/settings.local.json` (R14) still not created — optional, carried.

## §2 Step map (cycle 02 r4)
0. Merge PR #1 and close cycle 01 — [done] `646ce06`.
1. Sub-cycle 1a `cp/1a-onboarding-core` — [done] `c08bad8`.
2. Sub-cycle 1b `cp/1b-onboarding-complete` — [done] `10e6198`.
3. Sub-cycle 1c `cp/1c-admin-approval` — [in progress] — R28/R29 fix pushed; waiting on CI for `b5bc351`, then the fresh-subagent re-review (fix loop round 1 of 2).
4. Sub-cycle 2a `cp/2a-learners-slots` — [not started] — blocked behind 1c.
5. Sub-cycle 2b `cp/2b-search-profile` — [not started]
6. Sub-cycle 2c `cp/2c-match-pages-content` — [not started] (closes CP2)
7. Programme end — [not started] — this cycle's planned halt (24-hour owner review).

## §3 What changed this run
- **Owner replied "update"**; PLAN.md cycle 02 r4 found on disk (R28 = the recommended shape, R29 seeder, R30 design rule), committed as `34e9269` before other work.
- **R28 implemented**: `currentStep()` counts only pending/accepted documents as uploaded, so a rejected document returns a `changes_requested` tutor to that type's step, where `storeDocument()`'s existing soft-delete-then-create transaction replaces it; completion and approval stay blocked until the replacement is accepted. `SerializesModels` on the six tutor events. `access-admin-area` and `TutorDocumentPolicy` now require an active account, so a disabled admin loses document access too.
- **R29 implemented**: `SettingsSeeder` inserts missing keys only and never overwrites an existing row.
- **Tests**: the full replace → resubmit → approve-blocked → accept → approve path, pending/accepted regression, completion-blocked, disabled-admin refusal, event serialization, seeder keeps admin edits. Suite 219 tests / 743 assertions (was 205/686), Pint/PHPStan/RTL green. PR #4's description corrected with a revision history. Detail in CYCLE-LOG.md (09:05–10:12).

## §4 Decisions
- Carried (1c): `changes_requested` re-entry reuses the wizard (15:06 — its document-step gap is now fixed by R28); `users.status` + panel access (15:07); transition table in the Actions (17:20); `head_scripts` raw by design (17:21); auto-hide is `bookable()`, job idempotent via `audit_logs` (17:22); mail sender read at send time, branding on the public disk, new admins email-verified (17:23).
- **R28/R29 applied** (CYCLE-LOG 10:10). **R30 in force** for 2a/2b/2c: each design ADVISOR entry must list what a revisitable step invalidates downstream, with a test per dependency (CYCLE-LOG 10:12).

## §5 Why stopping
Not stopping — the owner's authorisation (R28) has landed and the fix is complete and tested; continuing to CI and the fresh-subagent re-review in this same run per the no-stop rule (R21).

## §6 Mismatches
- **`main` branch protection still not set** (owner action, carried). Per R23 this does not block the programme.
- **CP1 box 7's admin-payout clause remains deferred to CP5** (R25, disclosed since 1a) — "CP1 done" stays qualified.
- **Own process slip, repeated a third time**: pushed `b5bc351` before rewriting STATUS.md (HOW-WE-WORK §5) — as in 1b and 1c's first push. Rewritten now, before the re-review is dispatched.
- Carried, resolved: the 1c design consult's and DECISION 15:06's claim that a `changes_requested` tutor can edit "any step exactly as during draft" was false for the document step; R28 fixes it and R30 makes the downstream-invalidation list mandatory going forward.
- Carried, not blocking (also recorded in PLAN r4's programme-end review list): Fortify verification/reset mails still use `config('mail.from')`; the queue's "Submitted" column sorts on profile creation; last-active-admin check not transactional; `review_note` not cleared on resubmit; double queue hop on tutor mails; logo/favicon uploads untested and not rendered; N+1 on the queue's Docs column; duplicate price-band row 500s. R25 differences carried from the PR: `audit_logs.actor_user_id` nullable, `settings.value` nullable via a new migration, `users.status`/`suspended_reason` added; diff-scope exceptions in CYCLE-LOG 17:27.

## §7 Next step and owner actions
No owner action required to proceed — R28 answered the blocking question. Automatic continuations used under R21's cap: **3/8** (implementing an owner-directed fix is not itself counted; the counter advances at the next CI wait). Next: wait for CI on `b5bc351`, dispatch the fresh-subagent re-review of PR #4 (fix loop round 1 of 2); if clean, merge under R21, post-merge record, smoke, then sub-cycle 2a — whose design consult must carry R30's downstream-invalidation list. **A further Medium or High on this re-review is round 2 (the last); a Medium after that halts the programme for the 24-hour review.** Carried, optional: Owner action A (R7 branch protection, exact GitHub UI steps in cycle 01's CYCLE-LOG 18:35 BLOCKER); Owner action B (`.claude/settings.local.json`, ADR-002).

## §8 Programme board (R21)
| Sub-cycle | State | Branch | PR | Review verdict | Merge hash |
|---|---|---|---|---|---|
| 1a onboarding-core | **merged** | `cp/1a-onboarding-core` | [#2](https://github.com/rizwanoor80/eLearning-Platform/pull/2) | 9 PASS, 2 Low notes, no Medium/High | `c08bad8` |
| 1b onboarding-complete | **merged** | `cp/1b-onboarding-complete` | [#3](https://github.com/rizwanoor80/eLearning-Platform/pull/3) | 3 rounds; final 13 PASS, no Med/High | `10e6198` |
| 1c admin-approval | R28/R29 fix pushed, awaiting CI + re-review (round 1 of 2) | `cp/1c-admin-approval` | [#4](https://github.com/rizwanoor80/eLearning-Platform/pull/4) | 1st: 14 PASS/notes, 1 Medium (fixed R28), 1 Low (fixed R28). 2nd: pending | — |
| 2a learners-slots | not started (blocked behind 1c) | `cp/2a-learners-slots` | — | — | — |
| 2b search-profile | not started | `cp/2b-search-profile` | — | — | — |
| 2c match-pages-content | not started | `cp/2c-match-pages-content` | — | — | — |

## Deferred (out of v1 scope — do not build)
- Lesson packs / subscriptions / credits
- Group classes
- Courses / self-paced content
- Learning plans, homework, assessments
- B2B organisations / tenancy
- Multi-currency
- WhatsApp notifications
- Meilisearch
