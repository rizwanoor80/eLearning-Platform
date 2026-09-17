# STATUS — cycle 02 r1 — written 2026-09-17 22:00
Tests: 126 (unchanged, unmerged) · Advisor: consulted 3 times this run (1b design 20:45, 1b pre-PR 20:55, halt money-judgment 21:59) · Review: sub-cycle 1a's review: 9 PASS (2 Low notes), no Medium/High, merged; **sub-cycle 1b's review: 10 PASS (2 informational notes), 1 FAIL Medium — halted, not merged**

## §1 Git state
Cycles 01 and sub-cycle 1a remain merged (`646ce06`, `c08bad8`). [PR #3](https://github.com/rizwanoor80/eLearning-Platform/pull/3) (`cp/1b-onboarding-complete`) is **open and unmerged** — CI green, but the fresh-subagent review found a Medium finding, so R21's merge rule is not met. `main`'s branch protection: **not set** (owner action, carried, R23). `.claude/settings.local.json` (R14) still not created — optional, carried.

## §2 Step map (cycle 02 r1)
0. Merge PR #1 and close cycle 01 — [done] `646ce06`.
1. Sub-cycle 1a `cp/1a-onboarding-core` — [done] merged `c08bad8`.
2. Sub-cycle 1b `cp/1b-onboarding-complete` — **[halted — awaiting owner]** PR #3 open, CI green, review found 1 Medium finding. See §7 Owner action 1.
3. Sub-cycle 1c `cp/1c-admin-approval` — [not started] (closes CP1) — blocked behind 1b (sub-cycles are dependent, R21).
4. Sub-cycle 2a `cp/2a-learners-slots` — [not started]
5. Sub-cycle 2b `cp/2b-search-profile` — [not started]
6. Sub-cycle 2c `cp/2c-match-pages-content` — [not started] (closes CP2)
7. Programme end — [not started] — this cycle's planned halt (24-hour owner review).

## §3 What changed this run
- **Sub-cycle 1b implemented, tested (126 tests, 378 assertions), PR #3 opened, CI green.** Schema/models/factories for `tutor_subjects`/`availability_rules`/`availability_exceptions`/`pages`/`page_versions`; wizard extended with bank, subjects, rate (band-validated), profile, availability, agreement steps and a completion action. Full build detail in CYCLE-LOG.md (20:45–21:45).
- **Fresh-subagent adversarial review of PR #3** (21:58): 10 PASS (2 informational notes — a display-only integer-fils money preview in Vue, and that only 2 of 9 step handlers have a dedicated post-submission-guard test even though all share the same guard function), **1 FAIL Medium**: the rate step's band check (`rateBandFor()`) unions the min/max across every curriculum a tutor teaches at their highest tier, rather than validating per curriculum. Since `price_bands` is keyed per `(curriculum_id, level_tier, effective_from)`, an admin can legitimately configure different bands per curriculum at the same tier — a tutor teaching two such curricula could be offered a wider union band and set a rate outside one curriculum's real band, undermining CP1 box 1's literal guarantee. Today's seed data happens to make every curriculum's band identical at a given tier, which is why no earlier test caught this.
- **Halt-decision ADVISOR (21:59)**: confirmed the finding is real, confirmed R21's "Medium+ halts, no fix loop" governs (consistent with, not overridden by, HOW-WE-WORK §6's generic fix-loop text, which is for Low doc-only findings). Also corrected the design consult's own earlier band-aggregation suggestion: the obvious fix (intersecting each curriculum's band) can produce an *empty* range, so this is a genuine product decision, not a mechanical bug fix — see Owner action 1 below.
- Per R21, this halts the programme: sub-cycles are dependent, so 1c cannot start until 1b is resolved and merged. No fix was attempted on `cp/1b-onboarding-complete` — full detail in CYCLE-LOG.md (21:58–22:00).

## §4 Decisions
- `level_tier` is an explicit tutor-selected field constrained to `CurriculumCode::tiers()`, never parsed from the free-text level labels. CYCLE-LOG 20:46.
- `pages`/`page_versions` built in full in 1b (not a placeholder); 2c's "migrate the 1b placeholder" clause becomes a no-op. CYCLE-LOG 20:47.
- `TutorProfile.status` stays out of `$fillable` (matching the `User::role` convention); every write uses `forceFill()`. CYCLE-LOG 20:57 (reverting an intermediate mistake logged and disclosed at 20:48/20:56).
- Once a profile leaves `draft`, every onboarding step handler refuses outright; `changes_requested` re-entry is 1c's scope. CYCLE-LOG 20:58.
- Availability weekdays use Carbon's `0 = Sunday`..`6 = Saturday`; `recurring_slots` (CP2) must match. CYCLE-LOG 20:59.
- `LevelTier::rank()` added to the CP0 enum in-step (disclosed). CYCLE-LOG 21:00.
- Kept `Onboarding.vue` as one file rather than splitting per-step (R20: no browser rendering exercised this programme). CYCLE-LOG 20:49.
- **Not yet decided — see Owner action 1**: how the rate step should validate a tutor who teaches multiple curricula at the same highest tier with different admin-configured bands.

## §5 Why stopping
**Stopping: R21 merge rule not met.** The fresh-subagent review of PR #3 returned one Medium finding (rate-band union across curricula, §3 above). Per R21, "any Medium+ finding or rule miss → PR stays open, STATUS §7 names it 'awaiting owner', programme halts (sub-cycles are dependent, so there is no next independent item)". This is not the programme's planned end-of-programme halt (step 7) — it is an early stop inside sub-cycle 1b, resuming only once the owner has picked an option below.

## §6 Mismatches
- **`main` branch protection still not set** (owner action, carried from cycle 01). Per R23 this does not block the programme.
- **CP1 box 7's admin half remains deferred to CP5** (carried from 1a, unchanged this sub-cycle).
- **The design ADVISOR's own band-aggregation suggestion was wrong.** Its 20:45 guidance to build the band as "min/max across the matched rows" did not anticipate curricula having genuinely different bands at the same tier; the fresh-subagent review caught the resulting bug, not this session unprompted. Disclosed here plainly rather than presented as self-caught.

## §7 Next step and owner actions
**Owner action 1 (blocking — the programme cannot resume without this): pick how the rate step should validate a tutor teaching multiple curricula at the same highest tier when their admin-configured price bands differ.**
- **(Recommended)** Validate per curriculum: the rate must fall inside *every* matched curriculum's band (the intersection). If the intersection is empty, show a clear message naming which curricula's bands don't overlap, rather than silently picking one. This is the only option that keeps CP1 box 1's literal wording ("rejected... showing the band") true without a schema change.
- Give each `tutor_subjects` row its own rate instead of one `hourly_rate` on `tutor_profiles`. This is a schema change beyond DATA_MODEL v1.2 (which places `hourly_rate` on `tutor_profiles`) and beyond what R25 authorises this session to decide alone.
- Keep the current union behaviour as an accepted simplification for v1, documented as such (a tutor teaching multiple curricula at one tier gets the widest band across them).

Reply `update` (optionally naming the option, e.g. "update, option 1") when decided — this session will then fix `cp/1b-onboarding-complete` accordingly, re-run the suite, push, and re-request the fresh-subagent review before merging.

Automatic continuations used under R21's cap: **2/8** — stopped here deliberately (a Medium finding is a stop condition, not something to continue past); the counter does not advance further while halted. Carried, optional: Owner action A (R7 branch protection, exact GitHub UI steps in cycle 01's CYCLE-LOG 18:35 BLOCKER); Owner action B (`.claude/settings.local.json`, ADR-002).

## §8 Programme board (R21)
| Sub-cycle | State | Branch | PR | Review verdict | Merge hash |
|---|---|---|---|---|---|
| 1a onboarding-core | **merged** | `cp/1a-onboarding-core` | [#2](https://github.com/rizwanoor80/eLearning-Platform/pull/2) | 9 PASS, 2 Low notes, no Medium/High | `c08bad8` |
| 1b onboarding-complete | **awaiting owner** | `cp/1b-onboarding-complete` | [#3](https://github.com/rizwanoor80/eLearning-Platform/pull/3) | 10 PASS (2 notes), 1 FAIL Medium | — |
| 1c admin-approval | not started (blocked behind 1b) | `cp/1c-admin-approval` | — | — | — |
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
