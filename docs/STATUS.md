# STATUS — cycle 02 r3 — written 2026-09-18 17:30
Tests: 205 (up from 133) · Advisor: consulted 2 times this sub-cycle (1c design 15:05, 1c pre-PR 17:24) · Review: sub-cycle 1a: 9 PASS, merged; sub-cycle 1b: three rounds, merged; sub-cycle 1c: PR opening now

## §1 Git state
Cycles 01, sub-cycles 1a and 1b merged (`646ce06`, `c08bad8`, `10e6198`). `cp/1c-admin-approval` is code-complete (13 commits, pushed) and is being opened as a PR now. `main`'s branch protection: **not set** (owner action, carried, R23). `.claude/settings.local.json` (R14) still not created — optional, carried.

## §2 Step map (cycle 02 r3)
0. Merge PR #1 and close cycle 01 — [done] `646ce06`.
1. Sub-cycle 1a `cp/1a-onboarding-core` — [done] `c08bad8`.
2. Sub-cycle 1b `cp/1b-onboarding-complete` — [done] `10e6198`.
3. Sub-cycle 1c `cp/1c-admin-approval` — [in progress] — code complete, PR opening, then CI and the fresh-subagent review (closes CP1 boxes 4, 6, 8, 9; CP1 is then complete except box 7's admin-payout clause, CP5).
4. Sub-cycle 2a `cp/2a-learners-slots` — [not started]
5. Sub-cycle 2b `cp/2b-search-profile` — [not started]
6. Sub-cycle 2c `cp/2c-match-pages-content` — [not started] (closes CP2)
7. Programme end — [not started] — this cycle's planned halt (24-hour owner review).

## §3 What changed this run
- **Sub-cycle 1c built** in 13 commits, each verified green before the next: `audit_logs` + `RecordAuditLog`; site/mail/features settings (layout title, mail sender read at `envelope()` time); admin document viewer (own signed route, policy admits owner or admin); six CP1 emails via events → queued listeners; `changes_requested` re-entry; Filament resources — DocumentType, TutorProfile approval queue (document viewer, per-document accept/reject, approve/request-changes/reject/suspend), admin users (create/disable, role forced server-side), PriceBands (non-blocking overlap warning), and a Settings page (4 tabs); permit-expiry job (30d/7d/expired, idempotent, scheduled daily).
- **Pre-PR ADVISOR (17:24)** caught: admin actions had no status preconditions (a draft could be approved and become bookable — now a transition table enforced in the Actions); the browser title came from a build-time `VITE_APP_NAME` so box 8's "without a deploy" was false for the title users see (now reads the shared `name` prop); delete removed from document types/price bands; audit added to both. Tests found two more real defects on the way: `TutorDocumentPolicy::viewAny` blocked Filament's relation manager after first render, and the Money cast handed Money objects to the price-band edit form.
- Suite 205 tests / 686 assertions (was 133/410); `npm run build` and `vue-tsc` clean. Detail in CYCLE-LOG.md (15:05–17:28).

## §4 Decisions
- `changes_requested` re-entry reuses the wizard (locked statuses = pending_review/approved/rejected/suspended). CYCLE-LOG 15:06.
- `users.status`/`suspended_reason` added in-step; `canAccessPanel()` requires an active account. CYCLE-LOG 15:07.
- Tutor-status transition table enforced in the Actions. CYCLE-LOG 17:20.
- `head_scripts` raw by design (DATA_MODEL line 169). CYCLE-LOG 17:21.
- Auto-hide is `bookable()`, not the job; the job is idempotent via `audit_logs`. CYCLE-LOG 17:22.
- Mail sender read at send time; branding uploads on the public disk; new admins email-verified. CYCLE-LOG 17:23.

## §5 Why stopping
Not stopping — opening the PR for `cp/1c-admin-approval` next, then waiting on CI (asynchronous). Resumes automatically per the no-stop rule (R21).

## §6 Mismatches
- **`main` branch protection still not set** (owner action, carried from cycle 01). Per R23 this does not block the programme.
- **CP1 box 7's admin-payout clause remains deferred to CP5** (R25, disclosed since 1a) — so "CP1 done" in §8 is qualified.
- **R25 differences from DATA_MODEL**: `audit_logs.actor_user_id` is nullable (null = system-originated; DATA_MODEL is silent) and its migration was edited on-branch before merge; `settings.value` made nullable via a new migration.
- **Own process slip, repeated**: pushed `cp/1c-admin-approval` before rewriting STATUS.md (HOW-WE-WORK §5) — the same slip as 1b's. Rewritten now, before the PR opens.
- **Diff-scope exceptions disclosed** (CYCLE-LOG 17:27): `config/settings.php`, `AppServiceProvider.php` (one gate), `HandleInertiaRequests.php`, `app/Support/Mail/`, `app/Exceptions/`.
- Non-blocking, left and listed in CYCLE-LOG 17:26: `review_note` not cleared on resubmit; double queue hop (listener + mailable); logo/favicon uploads untested and not yet rendered; N+1 on the queue's Docs column; admin password rule; duplicate-band 500.

## §7 Next step and owner actions
No owner action required to proceed — the programme is authorised and running. Automatic continuations used under R21's cap: **3/8** (this wakeup is the third; the session halts automatically after 8, or at any stop condition). Next: open the PR (checklist = CP1 boxes 4, 6, 8, 9), wait for CI, dispatch the fresh-subagent review; a Medium or High halts the programme (R21). Carried, optional: Owner action A (R7 branch protection, exact GitHub UI steps in cycle 01's CYCLE-LOG 18:35 BLOCKER); Owner action B (`.claude/settings.local.json`, ADR-002).

## §8 Programme board (R21)
| Sub-cycle | State | Branch | PR | Review verdict | Merge hash |
|---|---|---|---|---|---|
| 1a onboarding-core | **merged** | `cp/1a-onboarding-core` | [#2](https://github.com/rizwanoor80/eLearning-Platform/pull/2) | 9 PASS, 2 Low notes, no Medium/High | `c08bad8` |
| 1b onboarding-complete | **merged** | `cp/1b-onboarding-complete` | [#3](https://github.com/rizwanoor80/eLearning-Platform/pull/3) | 3 rounds; final 13 PASS, no Med/High | `10e6198` |
| 1c admin-approval | code complete, opening PR | `cp/1c-admin-approval` | — | — | — |
| 2a learners-slots | not started | `cp/2a-learners-slots` | — | — | — |
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
