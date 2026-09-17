# STATUS — cycle 02 r1 — written 2026-09-17 20:38
Tests: 110 (up from 89) · Advisor: consulted 2 times this run so far (1a design 19:35, 1a pre-PR 20:05) · Review: sub-cycle 1a's review: 9 PASS (2 with Low, non-blocking notes), no Medium/High, merged

## §1 Git state
Cycle 01 remains merged (`646ce06`). Sub-cycle 1a merged: `main` at `c08bad8` "Merge pull request #2 from rizwanoor80/cp/1a-onboarding-core" (a real merge commit, not squashed), fast-forwarded locally and pushed. `cp/1a-onboarding-core` left in place (not deleted). `main`'s branch protection: **not set** (owner action, carried, R23). `.claude/settings.local.json` (R14) still not created — optional, carried.

## §2 Step map (cycle 02 r1)
0. Merge PR #1 and close cycle 01 — [done] `646ce06`.
1. Sub-cycle 1a `cp/1a-onboarding-core` — [done] merged `c08bad8`.
2. Sub-cycle 1b `cp/1b-onboarding-complete` — [in progress] — next.
3. Sub-cycle 1c `cp/1c-admin-approval` — [not started] (closes CP1)
4. Sub-cycle 2a `cp/2a-learners-slots` — [not started]
5. Sub-cycle 2b `cp/2b-search-profile` — [not started]
6. Sub-cycle 2c `cp/2c-match-pages-content` — [not started] (closes CP2)
7. Programme end — [not started] — this cycle's planned halt (24-hour owner review).

## §3 What changed this run
- **Sub-cycle 1a implemented and merged**: `document_types`/`tutor_profiles`/`tutor_documents` schema + models + seeder; ownership-checked signed document downloads (framework's `storage.local` disabled via `'serve' => false` — CP1 box 3, closed); onboarding wizard (personal → permit → one step per active document type, server-derived, resumable); `bank_iban` encryption + masking (CP1 box 7 model half, closed; admin half correctly deferred to CP5 under R25); R24's rtl-check regression test, closing cycle 01's Low review note.
- **Second mandatory ADVISOR (pre-PR, R21, 20:05)** caught CP1 box 7 was claimed closed with no proving test — fixed before the PR opened. Suite grew 108/303 → 110/308.
- **[PR #2](https://github.com/rizwanoor80/eLearning-Platform/pull/2)** opened, CI green (`gh pr checks 2` → pass, 1m11s), fresh-subagent adversarial review: 9 PASS (2 Low notes — step-order leniency on personal/permit steps, UTC handling not independently re-verified — both non-blocking), no Medium/High. Merged under R21's self-applied merge rule, no owner GO needed (R22).
- **Post-merge smoke**: `composer.bat test` on `main` → 110/110 tests, 308 assertions, Pint/PHPStan/RTL all green. `curl` (R20, no browser): `/` → 200, `/login` → 200, `/admin/login` → 200, `/dashboard` (unauth) → 302, `/tutor/onboarding` (unauth) → 302 — all as expected.
- Full detail in CYCLE-LOG.md (19:35–20:38).

## §4 Decisions
- Wizard shows one step per active `document_types` row (not a single combined step) — required by CP1 box 6, a deviation from the first ADVISOR's suggested single step, confirmed correct by the second consult. CYCLE-LOG 20:06.
- CP1 box 7 split under R25: sub-cycle 1a closes the model/tutor half only; the admin payout view (full IBAN) does not exist yet and is CP5 scope. CYCLE-LOG 20:07.
- Merge-rule self-check disclosed: the diff touched `config/filesystems.php` and `scripts/rtl-check.sh`, outside the step's literally-named areas but treated as in-scope (the config change *is* the box-3 hardening; the script argument is required for R24's own test in this same step) — confirmed clean by the fresh-subagent review's own diff-scope check (finding 7). CYCLE-LOG 20:09.

## §5 Why stopping
Not stopping — sub-cycle 1a is fully merged and verified; continuing into sub-cycle 1b (`cp/1b-onboarding-complete`) in this same run per the no-stop rule (R21).

## §6 Mismatches
- **`main` branch protection still not set** (owner action, carried from cycle 01). Per R23 this does not block the programme.
- **CP1 box 7 is only half-closeable pre-CP5**: the plan's sub-cycle 1a "done means" text says 1a "closes CHECKPOINTS CP1 boxes 3 and 7", but box 7's second clause ("admin payout view sees the full value") requires an admin payout view that does not exist in any cycle to date and is CP5/Ledger scope per CLAUDE.md. Disclosed per R25 rather than silently redefining "closes"; the model/tutor half is fully proven now, the admin half is deferred to CP5 by design, not by oversight.

## §7 Next step and owner actions
No owner action required to proceed — the programme is authorised and running. Automatic continuations used under R21's cap: **1/8** (this session will halt automatically after 8, or at any stop condition — smoke failure, a fix loop exceeding 2 rounds, a High security finding, an unreachable local service, or the owner typing `stop` — whichever comes first). Next: sub-cycle 1b design ADVISOR consult, then branch `cp/1b-onboarding-complete`. Carried, optional: Owner action A (R7 branch protection, exact GitHub UI steps in cycle 01's CYCLE-LOG 18:35 BLOCKER); Owner action B (`.claude/settings.local.json`, ADR-002).

## §8 Programme board (R21)
| Sub-cycle | State | Branch | PR | Review verdict | Merge hash |
|---|---|---|---|---|---|
| 1a onboarding-core | **merged** | `cp/1a-onboarding-core` | [#2](https://github.com/rizwanoor80/eLearning-Platform/pull/2) | 9 PASS, 2 Low notes, no Medium/High | `c08bad8` |
| 1b onboarding-complete | in progress | `cp/1b-onboarding-complete` | — | — | — |
| 1c admin-approval | not started | `cp/1c-admin-approval` | — | — | — |
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
