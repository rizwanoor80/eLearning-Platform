# STATUS — cycle 02 r1 — written 2026-09-17 20:10
Tests: 110 (up from 89) · Advisor: consulted 2 times this run so far (1a design 19:35, 1a pre-PR 20:05) · Review: n/a this cycle so far (cycle 01's review: 10 PASS + 1 Low note, merged)

## §1 Git state
Cycle 01 remains merged (`646ce06`). `main` now also carries `72ca1ac` "CYCLE-LOG: sub-cycle 1a second ADVISOR consult, two decisions, verification" (docs-only, pushed directly per protocol). `cp/1a-onboarding-core` is code-complete, pushed, and open as [PR #2](https://github.com/rizwanoor80/eLearning-Platform/pull/2) (base `main`), CI running. `main`'s branch protection: **not set** (owner action, carried, R23). `.claude/settings.local.json` (R14) still not created — optional, carried.

## §2 Step map (cycle 02 r1)
0. Merge PR #1 and close cycle 01 — [done] `646ce06`.
1. Sub-cycle 1a `cp/1a-onboarding-core` — [in progress] — PR #2 open, awaiting CI then fresh-subagent review.
2. Sub-cycle 1b `cp/1b-onboarding-complete` — [not started]
3. Sub-cycle 1c `cp/1c-admin-approval` — [not started] (closes CP1)
4. Sub-cycle 2a `cp/2a-learners-slots` — [not started]
5. Sub-cycle 2b `cp/2b-search-profile` — [not started]
6. Sub-cycle 2c `cp/2c-match-pages-content` — [not started] (closes CP2)
7. Programme end — [not started] — this cycle's planned halt (24-hour owner review).

## §3 What changed this run
- **Sub-cycle 1a implemented** on `cp/1a-onboarding-core`: `document_types`/`tutor_profiles`/`tutor_documents` schema + models + seeder; ownership-checked signed document downloads (framework's `storage.local` disabled via `'serve' => false`, since it checks signature only, not ownership — CP1 box 3); onboarding wizard (personal → permit → one step per active document type, current step always derived server-side, resumable); R24's regression test for `scripts/rtl-check.sh` itself, closing cycle 01's Low review note.
- **Second mandatory ADVISOR (pre-PR, R21, 20:05)** caught that CP1 box 7 was claimed closed with no test proving it. Added `tests/Feature/Tutor/TutorProfileTest.php` (raw `bank_iban` column not plaintext, `bankIbanMasked()` shows last four; `bookable()`'s strictly-greater-than-today boundary). Suite grew 108/303 → 110/308, never shrank.
- **[PR #2](https://github.com/rizwanoor80/eLearning-Platform/pull/2) opened**: "CP1 sub-cycle 1a — onboarding core", checklist = CP1 boxes 3 and 7 (model half), design notes on the wizard-per-document-type choice and the merge-rule self-check. CI running.
- Full detail in CYCLE-LOG.md (19:35–20:09).

## §4 Decisions
- Wizard shows one step per active `document_types` row (not a single combined step) — required by CP1 box 6, a deviation from the first ADVISOR's suggested single step, confirmed correct by the second consult. CYCLE-LOG 20:06.
- CP1 box 7 split under R25: sub-cycle 1a closes the model/tutor half only; the admin payout view (full IBAN) does not exist yet and is CP5 scope. CYCLE-LOG 20:07.
- Merge-rule self-check disclosed: the diff touches `config/filesystems.php` and `scripts/rtl-check.sh`, outside the step's literally-named areas but treated as in-scope (the config change *is* the box-3 hardening; the script argument is required for R24's own test in this same step). CYCLE-LOG 20:09.

## §5 Why stopping
Not stopping — waiting on PR #2's CI, which is asynchronous. Will resume automatically when CI completes (via a scheduled check), then dispatch the fresh-subagent adversarial review, then continue per the no-stop rule. This is continuation-in-progress, not a halt.

## §6 Mismatches
- **`main` branch protection still not set** (owner action, carried from cycle 01). Per R23 this does not block the programme.
- **CP1 box 7 is only half-closeable pre-CP5**: the plan's sub-cycle 1a "done means" text says 1a "closes CHECKPOINTS CP1 boxes 3 and 7", but box 7's second clause ("admin payout view sees the full value") requires an admin payout view that does not exist in any cycle to date and is CP5/Ledger scope per CLAUDE.md. Disclosing per R25 rather than silently redefining "closes"; the model/tutor half is fully proven now, the admin half is deferred to CP5 by design, not by oversight.

## §7 Next step and owner actions
No owner action required to proceed — the programme is authorised and running. Automatic continuations used under R21's cap: **1/8** (this session will halt automatically after 8, or at any stop condition — smoke failure, a fix loop exceeding 2 rounds, a High security finding, an unreachable local service, or the owner typing `stop` — whichever comes first). Carried, optional: Owner action A (R7 branch protection, exact GitHub UI steps in cycle 01's CYCLE-LOG 18:35 BLOCKER); Owner action B (`.claude/settings.local.json`, ADR-002).

## §8 Programme board (R21)
| Sub-cycle | State | Branch | PR | Review verdict | Merge hash |
|---|---|---|---|---|---|
| 1a onboarding-core | PR open, CI running | `cp/1a-onboarding-core` | [#2](https://github.com/rizwanoor80/eLearning-Platform/pull/2) | — | — |
| 1b onboarding-complete | not started | `cp/1b-onboarding-complete` | — | — | — |
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
