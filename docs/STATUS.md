# STATUS — cycle 02 r5 — written 2026-09-19 12:35
Tests: 236 (up from 219) · Advisor: consulted 4 times this sub-cycle (design 15:05, pre-PR 17:24, halt 20:41, halt 11:06) · Review: 1a: 9 PASS, merged; 1b: three rounds, merged; 1c: 1st review 1 Medium + 1 Low (fixed R28/R29), 2nd review 2 Medium (fixed R31), 3rd review pending — round 2 of 2, the last

## §1 Git state
Cycles 01, sub-cycles 1a and 1b merged (`646ce06`, `c08bad8`, `10e6198`). [PR #4](https://github.com/rizwanoor80/eLearning-Platform/pull/4) (`cp/1c-admin-approval`) carries the R31 fix (`4b9cfbd`), suite 236/832 with `npm run build` + `vue-tsc` clean; it is pushed after this STATUS write, and CI plus the third review are next. `main`'s branch protection: **not set** (owner action, carried, R23). `.claude/settings.local.json` (R14) still not created — optional, carried.

## §2 Step map (cycle 02 r5)
0. Merge PR #1 and close cycle 01 — [done] `646ce06`.
1. Sub-cycle 1a `cp/1a-onboarding-core` — [done] `c08bad8`.
2. Sub-cycle 1b `cp/1b-onboarding-complete` — [done] `10e6198`.
3. Sub-cycle 1c `cp/1c-admin-approval` — [in progress] — R31 fix built; waiting on CI for `4b9cfbd`, then the fresh-subagent re-review (fix loop round 2 of 2, the last).
4. Sub-cycle 2a `cp/2a-learners-slots` — [not started] — blocked behind 1c.
5. Sub-cycle 2b `cp/2b-search-profile` — [not started]
6. Sub-cycle 2c `cp/2c-match-pages-content` — [not started] (closes CP2)
7. Programme end — [not started] — this cycle's planned halt (24-hour owner review).

## §3 What changed this run
- **Owner replied "update"**; PLAN.md cycle 02 r5 committed before other work (R31 = the recommended minimal shape; finding #9 stays disputed).
- **R31 implemented**: (a) document accept/reject is refused (and the buttons hidden) unless the profile is draft / pending_review / changes_requested, so document review closes with the approval decision; (b) `changes_requested` tutors get a step picker over the existing wizard forms (the picker only chooses which saved form to show — the derived step is never taken from the client; every submit re-derives and lands back on `complete`), plus distinct approved / rejected / suspended text and the admin note passed for suspended; (c) permit expiring/expired notices also go to `support_address` or, if unset, every active admin (PRD §8).
- **Tests** (17 new): guard on approved/rejected/suspended and still open for draft/pending/changes_requested; an approved tutor stays bookable when a rejection is attempted; relation-manager visibility; admin permit notices incl. disabled-admin exclusion; Inertia props per status; edit-an-earlier-step round trip. 236 tests / 832 assertions. Detail in CYCLE-LOG.md (09:10–12:32).

## §4 Decisions
- Carried (1c): transition table in the Actions (17:20); `head_scripts` raw by design (17:21); auto-hide is `bookable()`, job idempotent via `audit_logs` (17:22); mail sender read at send time, branding on the public disk, new admins email-verified (17:23); R28/R29 as implemented (10:10).
- **R31 applied** (CYCLE-LOG 12:30). **R30 remains in force** for 2a/2b/2c: the design ADVISOR entry must list what a revisitable step invalidates downstream, with a test per dependency.

## §5 Why stopping
Not stopping — the owner's authorisation (R31) has landed and the fix is complete and tested; continuing to CI and the fresh-subagent re-review in this same run per the no-stop rule (R21).

## §6 Mismatches
- **`main` branch protection still not set** (owner action, carried). Per R23 this does not block the programme.
- **CP1 box 7's admin-payout clause remains deferred to CP5** (R25, disclosed since 1a) — "CP1 done" stays qualified.
- **Not built, disclosed (R25) — CP1 follow-up list (PLAN r5):** post-approval re-vetting (a rejected document or newly-required type does not un-approve a tutor); reinstate-from-suspended; `review_note` clearing; replaced-file cleanup on disk; `onOneServer` on the permit job; document-type `code`/`sort` validation; admin Disable confirmation. The tutor's permit-expiring email still says "update your permit details" though no renewal path exists (PRD §2.1 "new permit uploaded and re-approved" is not built).
- **Finding #9 disputed**: agreement version is deliberately not re-derived (CHECKPOINTS line 76; PRD §9).
- **Process**: STATUS.md was rewritten BEFORE this branch push — the HOW-WE-WORK §5 slip from 1b and 1c is corrected this round.
- Carried, not blocking: Fortify verification/reset mails use `config('mail.from')`; queue "Submitted" sorts on `created_at`; last-active-admin check not transactional; double queue hop on tutor mails; logo/favicon uploads untested and not rendered; N+1 on the queue's Docs column; duplicate price-band row 500s. R25 differences carried from the PR: `audit_logs.actor_user_id` nullable, `settings.value` nullable via a new migration, `users.status`/`suspended_reason` added; diff-scope exceptions in CYCLE-LOG 17:27 (this round also adds `app/Support/Mail/AdminRecipients.php` and `app/Mail/Admin/`).

## §7 Next step and owner actions
No owner action required to proceed — R31 answered the blocking question. Automatic continuations used under R21's cap: **5/8** (this CI wait is the fifth). Next: wait for CI on `4b9cfbd`, dispatch the fresh-subagent re-review of PR #4 (round 2 of 2, the last); if clean, merge under R21, post-merge record, smoke, then sub-cycle 2a — whose design consult must carry R30's downstream-invalidation list. **A Medium or High on this re-review halts the whole programme for the 24-hour owner review — no round 3; the planner then cuts a hand-run cycle to redesign the tutor status lifecycle as one piece (R31).** Carried, optional: Owner action A (R7 branch protection, exact GitHub UI steps in cycle 01's CYCLE-LOG 18:35 BLOCKER); Owner action B (`.claude/settings.local.json`, ADR-002).

## §8 Programme board (R21)
| Sub-cycle | State | Branch | PR | Review verdict | Merge hash |
|---|---|---|---|---|---|
| 1a onboarding-core | **merged** | `cp/1a-onboarding-core` | [#2](https://github.com/rizwanoor80/eLearning-Platform/pull/2) | 9 PASS, 2 Low notes, no Medium/High | `c08bad8` |
| 1b onboarding-complete | **merged** | `cp/1b-onboarding-complete` | [#3](https://github.com/rizwanoor80/eLearning-Platform/pull/3) | 3 rounds; final 13 PASS, no Med/High | `10e6198` |
| 1c admin-approval | R31 fix pushed, awaiting CI + re-review (round 2 of 2, last) | `cp/1c-admin-approval` | [#4](https://github.com/rizwanoor80/eLearning-Platform/pull/4) | 1st: 1 Med + 1 Low (fixed R28). 2nd: 2 Med, 4 Low, 1 disputed (fixed R31). 3rd: pending | — |
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
