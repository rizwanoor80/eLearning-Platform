# STATUS — cycle 02 r2 — written 2026-09-18 00:47
Tests: 129 (unchanged, unmerged) · Advisor: consulted 4 times this resumed run (carried: 1b design 20:45, 1b pre-PR 20:55, halt money-judgment 21:59; this run: fix-loop-round money-judgment 00:46) · Review: sub-cycle 1a's review: 9 PASS (2 Low notes), no Medium/High, merged; sub-cycle 1b's first review: 10 PASS (2 notes), 1 FAIL Medium (fixed via R26); **sub-cycle 1b's second review: 10 PASS, 1 FAIL Medium, 1 Low — halted again, not merged**

## §1 Git state
Cycles 01 and sub-cycle 1a remain merged (`646ce06`, `c08bad8`). [PR #3](https://github.com/rizwanoor80/eLearning-Platform/pull/3) (`cp/1b-onboarding-complete`) carries the R26 fix (`8e0afcf`), CI green, but a second fresh-subagent review found a further Medium finding — R21's merge rule is still not met. `main`'s branch protection: **not set** (owner action, carried, R23). `.claude/settings.local.json` (R14) still not created — optional, carried.

## §2 Step map (cycle 02 r2)
0. Merge PR #1 and close cycle 01 — [done] `646ce06`.
1. Sub-cycle 1a `cp/1a-onboarding-core` — [done] merged `c08bad8`.
2. Sub-cycle 1b `cp/1b-onboarding-complete` — **[halted — awaiting owner, round 2 of 2]** PR #3 open, R26 fix in, second review found 1 Medium + 1 Low. See §7 Owner action 1.
3. Sub-cycle 1c `cp/1c-admin-approval` — [not started] (closes CP1) — blocked behind 1b.
4. Sub-cycle 2a `cp/2a-learners-slots` — [not started]
5. Sub-cycle 2b `cp/2b-search-profile` — [not started]
6. Sub-cycle 2c `cp/2c-match-pages-content` — [not started] (closes CP2)
7. Programme end — [not started] — this cycle's planned halt (24-hour owner review).

## §3 What changed this run
- **R26 implemented and verified**: rate-band validation is now the intersection across curricula, not the union (details in the prior write of this file / CYCLE-LOG 00:20–00:21). Suite 129/393, pushed `8e0afcf`.
- **Second fresh-subagent review of PR #3** (00:45): 10 PASS, **1 FAIL Medium**, 1 PLAUSIBLE Low. Medium finding: a tutor's `hourly_rate`, once saved, is never re-validated or cleared when they later go back and change their `subjects` (the wizard's own design allows editing earlier steps). `storeSubjects()` replaces the subject list but doesn't touch `hourly_rate`; `currentStep()` only checks `hourly_rate !== null`, not band membership, so the wizard skips straight past `rate` even when the stored value no longer fits the new subjects' band. `complete()` doesn't re-check either. Confirmed empirically by the reviewer with a scratch test: wide-band subjects → rate 900 saved → subjects swapped to a narrow band [100,200] → profile keeps `hourly_rate=900`, wizard shows step `profile`, not `rate`. Low finding (#11): a curriculum with no current `price_bands` row at the tutor's tier is silently dropped from the band calculation instead of being flagged as a conflict — same family of gap.
- **Halt-decision ADVISOR (00:46)**: confirmed R26's "counts as the fix loop's first round" is bookkeeping, not blanket authorisation for a second round — round 2 needs the owner's word, same as round 1 did. Recommended fix shape and bundling #2+#11 into one round if authorised. Also flagged that the original design consult's step-guard advice (19:35/20:45) never addressed what happens to *downstream* derived state when an earlier step is edited afterward — the same class of miss as the original union bug, disclosed here rather than presented as purely the reviewer's catch.
- Per R21, this halts the programme again: sub-cycles are dependent, 1c cannot start. No fix attempted on `cp/1b-onboarding-complete` this round. Full detail in CYCLE-LOG.md (00:45–00:47).

## §4 Decisions
- Carried (unchanged): `level_tier` explicit field (20:46); `pages`/`page_versions` built in full (20:47); `TutorProfile.status` out of `$fillable` (20:57); post-submission steps refused outright (20:58); weekday `0 = Sunday` (20:59); `LevelTier::rank()` in-step (21:00); single-file `Onboarding.vue` (20:49); R26's intersection rule (00:20).
- **Not yet decided — see Owner action 1**: whether to authorise fix-loop round 2 for the stale-rate-after-subjects-edit gap (bundled with the missing-band Low), and in what shape.

## §5 Why stopping
**Stopping: R21 merge rule not met, second time.** The second fresh-subagent review of PR #3 returned one Medium finding (stale out-of-band rate surviving a later subjects edit) plus a related Low. Per R21/HOW-WE-WORK §6, Medium+ in code halts and returns to the owner. This would be fix-loop round 2 of 2 if authorised — spending it without the owner's explicit go-ahead was judged (00:46 ADVISOR) not this session's call to make alone, especially since R21's own stop condition ("a regression the fix loop cannot clear in two rounds") ends the *whole programme* early if round 2 also finds a Medium.

## §6 Mismatches
- **`main` branch protection still not set** (owner action, carried from cycle 01). Per R23 this does not block the programme.
- **CP1 box 7's admin half remains deferred to CP5** (carried, unchanged).
- **The original design consult's step-guard advice had a second gap, now found.** Its 19:35/20:45 guidance ("requests may target any step at or before the current position") let a tutor freely re-edit earlier steps but never addressed invalidating state derived from them — the rate/band relationship is exactly such derived state. This is the same class of miss as the original union-vs-intersection bug: a design consult that answered "can this be done" without fully tracing "what does this action invalidate downstream." Disclosed plainly rather than presented as purely the second review's own catch.
- **PR #3's description still states "126/126, 378 assertions"** — stale since the R26 fix (`8e0afcf`) landed at 129/393. Left as-is while halted; will be corrected in the same push as any round-2 fix, not edited mid-halt.

## §7 Next step and owner actions
**Owner action 1 (blocking — the programme cannot resume without this): authorise fix-loop round 2 for sub-cycle 1b, and pick a shape (or a different one).**
- **(Recommended)** In `storeSubjects()`, after the replace-all: if `hourly_rate` is already set, recompute `rateBandFor()` against the new subject list; if the rate no longer fits (or the new band is `conflicting` or doesn't exist), clear `hourly_rate` to `null` so `currentStep()` returns the tutor to `'rate'` with the correct new band shown — nothing stale survives, no separate error state to design. Additionally, `complete()` re-validates the stored rate against the current band and 409s if it's somehow still out of range (defence in depth). Bundle the Low finding in the same round: a curriculum at the highest tier with no current `price_bands` row is treated as a conflict (named in the rejection message), not silently dropped, consistent with R26's "nothing is silently picked." Tests: a subjects swap to a narrower band clears the rate and returns step `rate`; a swap that still fits keeps the rate; completion with a since-invalidated rate 409s; a curriculum with no current band is named as a conflict.
- Forbid editing `subjects` once `hourly_rate` is set (make the wizard forward-only from the rate step onward). Simpler to implement, worse UX, and narrows the "edit earlier steps" design DECISION 20:58 established without an explicit ruling to that effect.
- Leave it unfixed this cycle, accept the gap, and rely on sub-cycle 1c's admin approval step to catch an out-of-band rate by eye before approving. Not recommended — CP1 box 1's literal wording is "rejected on save," not "caught at approval."

Reply `update` (optionally naming the option) when decided. **This is explicitly round 2 of 2** under R21's fix-loop cap: if round 2's re-review still returns a Medium, the whole programme halts early for the 24-hour owner review (R21's own stop condition), not just this sub-cycle.

Automatic continuations used under R21's cap: **2/8** — unchanged (an owner-directed fix-and-reverify round is not itself a counted continuation; the counter advances at the next CI/async wait). Carried, optional: Owner action A (R7 branch protection); Owner action B (`.claude/settings.local.json`, ADR-002).

## §8 Programme board (R21)
| Sub-cycle | State | Branch | PR | Review verdict | Merge hash |
|---|---|---|---|---|---|
| 1a onboarding-core | **merged** | `cp/1a-onboarding-core` | [#2](https://github.com/rizwanoor80/eLearning-Platform/pull/2) | 9 PASS, 2 Low notes, no Medium/High | `c08bad8` |
| 1b onboarding-complete | **awaiting owner (round 2 of 2)** | `cp/1b-onboarding-complete` | [#3](https://github.com/rizwanoor80/eLearning-Platform/pull/3) | 1st: 10 PASS, 2 notes, 1 FAIL Medium (fixed via R26). 2nd: 10 PASS, 1 FAIL Medium, 1 Low | — |
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
