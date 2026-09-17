# STATUS — cycle 02 r1 — written 2026-09-17 21:00
Tests: 126 (up from 110) · Advisor: consulted 2 times this run so far (1b design 20:45, 1b pre-PR 20:55) · Review: sub-cycle 1a's review: 9 PASS (2 Low notes), no Medium/High, merged; sub-cycle 1b's review: pending

## §1 Git state
Cycles 01 and sub-cycle 1a remain merged (`646ce06`, `c08bad8`). `cp/1b-onboarding-complete` is code-complete, pushed (5 commits), not yet opened as a PR. `main`'s branch protection: **not set** (owner action, carried, R23). `.claude/settings.local.json` (R14) still not created — optional, carried.

## §2 Step map (cycle 02 r1)
0. Merge PR #1 and close cycle 01 — [done] `646ce06`.
1. Sub-cycle 1a `cp/1a-onboarding-core` — [done] merged `c08bad8`.
2. Sub-cycle 1b `cp/1b-onboarding-complete` — [in progress] — code complete, opening PR next.
3. Sub-cycle 1c `cp/1c-admin-approval` — [not started] (closes CP1)
4. Sub-cycle 2a `cp/2a-learners-slots` — [not started]
5. Sub-cycle 2b `cp/2b-search-profile` — [not started]
6. Sub-cycle 2c `cp/2c-match-pages-content` — [not started] (closes CP2)
7. Programme end — [not started] — this cycle's planned halt (24-hour owner review).

## §3 What changed this run
- **Sub-cycle 1b implemented** on `cp/1b-onboarding-complete`: `tutor_subjects`/`availability_rules`/`availability_exceptions`/`pages`/`page_versions` schema + models + factories; `PageSeeder` seeds a `tutor_agreement` placeholder at version 1 (built directly against DATA_MODEL.md's full schema, not a throwaway table — migrations are forward-only); wizard extended with bank, subjects (curriculum × subject × explicit level tier), rate (band-validated against the current `price_bands` row for the highest tier taught, band shown in the rejection message and as a prop), bio/headline/video, availability (weekly rules + exceptions, overlap-checked, replace-all), agreement (records the page's current version server-side) and a `CompleteTutorOnboarding` action that sets `pending_review`.
- **Design ADVISOR (20:45)**: confirmed building `pages`/`page_versions` in full now rather than a placeholder; confirmed no PRD label→tier mapping exists so `level_tier` is an explicit tutor-selected field; gave the rate-band and step-order-guard mechanics.
- **Two real bugs found and fixed while testing**: `TutorProfile::$fillable` missing `status` (silently dropped by `firstOrCreate()`); `UserFactory` missing a default `timezone` (in-memory null despite a DB column default, breaking `storeAvailability()`). Both disclosed in CYCLE-LOG 20:48.
- **Pre-PR ADVISOR (20:55)** then caught that the `status`-fillable fix itself was a Medium-risk regression (1a's review praised excluding privileged columns like `role` from mass assignment) — reverted to `firstOrNew()`+`forceFill()`; also caught that a submitted profile could still be edited via earlier steps, and that the Vue rate step did float arithmetic on money (invariant #3). All three fixed and re-tested. Suite: 126 tests, 378 assertions, Pint/PHPStan/RTL all green.
- Full detail in CYCLE-LOG.md (20:45–21:00).

## §4 Decisions
- `level_tier` is an explicit tutor-selected field constrained to `CurriculumCode::tiers()`, never parsed from the free-text level labels — no such mapping exists in the PRD (illustrative examples only) and DATA_MODEL stores it as its own column. CYCLE-LOG 20:46.
- `pages`/`page_versions` built in full in 1b (not a placeholder); 2c's "migrate the 1b placeholder" clause becomes a no-op. CYCLE-LOG 20:47.
- `TutorProfile.status` stays out of `$fillable` (matching the `User::role` convention); every write uses `forceFill()`. CYCLE-LOG 20:57 (reverting an intermediate mistake logged and disclosed at 20:48/20:56).
- Once a profile leaves `draft`, every onboarding step handler refuses outright; `changes_requested` re-entry is 1c's scope. CYCLE-LOG 20:58.
- Availability weekdays use Carbon's `0 = Sunday`..`6 = Saturday`; `recurring_slots` (CP2) must match. CYCLE-LOG 20:59.
- `LevelTier::rank()` added to the CP0 enum in-step (disclosed, same pattern as 1a's `filesystems.php` exception). CYCLE-LOG 21:00.
- Kept `Onboarding.vue` as one file rather than splitting per-step, since R20 means no browser rendering is exercised this programme. CYCLE-LOG 20:49 (a deviation from the design ADVISOR's suggestion).

## §5 Why stopping
Not stopping — opening the PR for `cp/1b-onboarding-complete` next, then waiting on CI (asynchronous). Will resume automatically per the no-stop rule (R21).

## §6 Mismatches
- **`main` branch protection still not set** (owner action, carried from cycle 01). Per R23 this does not block the programme.
- **CP1 box 7's admin half remains deferred to CP5** (carried from 1a, unchanged this sub-cycle).
- **Own process slip, disclosed**: pushed `cp/1b-onboarding-complete`'s first four commits (~20:44) without rewriting STATUS.md first, as HOW-WE-WORK §5 requires at every push. Caught in the 20:55 ADVISOR consult; STATUS.md is being rewritten now, before the PR opens, and the push carrying the pre-PR fixes follows this write.

## §7 Next step and owner actions
No owner action required to proceed — the programme is authorised and running. Automatic continuations used under R21's cap: **2/8** (1a's CI wait, 1b's CI wait to follow — this session will halt automatically after 8, or at any stop condition, whichever comes first). Next: open the PR for `cp/1b-onboarding-complete` (checklist = CP1 boxes 1, 2, 5), wait for CI, dispatch the fresh-subagent review. Carried, optional: Owner action A (R7 branch protection, exact GitHub UI steps in cycle 01's CYCLE-LOG 18:35 BLOCKER); Owner action B (`.claude/settings.local.json`, ADR-002).

## §8 Programme board (R21)
| Sub-cycle | State | Branch | PR | Review verdict | Merge hash |
|---|---|---|---|---|---|
| 1a onboarding-core | **merged** | `cp/1a-onboarding-core` | [#2](https://github.com/rizwanoor80/eLearning-Platform/pull/2) | 9 PASS, 2 Low notes, no Medium/High | `c08bad8` |
| 1b onboarding-complete | code complete, opening PR | `cp/1b-onboarding-complete` | — | — | — |
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
