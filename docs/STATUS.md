# STATUS — cycle 03 r1 — written 2026-09-20 12:15
Tests: 428 tests / 2105 assertions (branch `cp/2.5a-rulings`, unmerged; main 390) · Advisor: cycle 03 consulted 2 times so far (step 1 design 09:30, pre-PR 11:30) · Review: step 1 reviewed — no Medium/High (4 Low)

## §1 Git state
`main` = `0a10c2c` (cycle 02's end) plus docs commits (PLAN cycle 03 r1 `8bec280`, the log, this STATUS). [PR #8](https://github.com/rizwanoor80/eLearning-Platform/pull/8) (`cp/2.5a-rulings`, head `b48be37`, three commits) is open, CI green ([run 35430174880](https://github.com/rizwanoor80/eLearning-Platform/actions/runs/35430174880)), reviewed clean — **not merged: Claude Code merges no PR this cycle.** Branch protection on `main`: **not set** (owner action, carried, R23). `.claude/settings.local.json` (R14): not created — optional, carried.

## §2 Step map (cycle 03 r1 — "CP2.5")
1. `cp/2.5a-rulings` — [built, PR #8 open, CI green, review clean — halted for the owner's GO] — R32, R34, R35 and the eleven pure-fix Lows.
2. `cp/2.5b-year-groups` — [not started — starts after PR #8 is merged; branches are cut from `main`] (R33).
3. `cp/2.5c-tutor-lifecycle` — [not started] (R36 plus the named Lows).
4. Rehearsal server — [not started] (R38; owner actions first).
5. Close — [not started] (R37 docs; halt).

## §3 What changed this run
- Owner replied `update`; PLAN cycle 03 r1 committed (`8bec280`) before other work; rulings R32–R38 logged as ADVICE (owner).
- Step 1 built and reviewed: **R32** `TutorProfile::displayName()`; **R34** permit cap in `SlotCalculator`; **R35** budget labels as content blocks; the eleven pure-fix Lows (agreement-version error, profile id route constraint, `/tutors` validation errors, `booking_max_days` bound at 90, `Lesson::$fillable` without `status`, `forTutors` no-mutation, `CloseMatchRequest` lock, deleted-request mail guard, `User` observer for the self-learner name, `Register.vue` checkbox, prefixed test helpers).
- Suite 428 tests / 2105 assertions (up from 390 / 1976); Pint, PHPStan (0 errors), RTL, `npm run build`, `vue-tsc` green. (The earlier figure of 2106 assertions was stale by one after a no-op assertion was removed; the PR body is corrected.)

## §4 Decisions
- Cycle-02 Owner actions 2–5 are answered by R32–R35. Step-1 semantics: CYCLE-LOG DECISION 11:31. Review: REVIEW 12:10, NOTE 12:11.

## §5 Why stopping
**Stopping: step 1 halt (owner GO).** PLAN step 1 says "halt: yes, owner GO to merge"; Claude Code does not merge PRs this cycle. Nothing runs until the owner replies.

## §6 Mismatches
- **Branch protection on `main` not set** (owner action, carried; R23).
- **CP1 box 7 admin-payout clause deferred to CP5** (R25) — "CP1 done" stays qualified.
- **Step 1 review Lows (not fixed — no fix loop this cycle):** (L1) the assertion count was stated as 2106; corrected to 2105; (L2) `PermitCapTest` never exercises exactly 00:00 UTC, so changing `>=` to `>` in `SlotCalculator.php` would not fail a test — a one-line test for step 2 or 3 to add; (L3) `docs/DATA_MODEL.md` and the `ContentBlock` docblock still describe the block set as homepage-only — R37's DATA_MODEL v1.3 pass; (L4) a leading zero-width character (U+200B, U+FEFF, U+200F) in an account name gives an invisible one-character display name (tutor accounts are admin-created).
- **Step 1 disclosures (merged only on GO):** R20 proxies (`file_get_contents(...)->toContain(...)` for Vue); `deleteWhenMissingModels` proven in three parts and, per the review, also end to end under the sync queue; `getRedirectUrl()` drops the other query parameters on a failed `/tutors` validation; `UserResource` has no Edit page (admin rename through Filament unreachable today; the observer covers any model save; bulk `update()` bypasses events by design); 90 days is my number (PRD silent); nav label "Homepage content" is now "Site content"; content-block counts 4→7 are growth; the R34 cutoff follows the UTC reading (the same day `bookable()` stops), not the tutor's local expiry day.
- **Remaining follow-up list:** steps 2–3 of this cycle (R33 year groups; R36 lifecycle plus the Fortify mail sender, "Submitted" sort column, transactional last-admin check, duplicate price-band, document-type `code`/`sort` validation, admin Disable confirmation). Staying on the list for CP3's plan: `whereDate` in `bookable()` may bypass the new index; in-memory search pagination; the onboarding trial-price preview rounds `100 − pct` (CP3 must unify with `trialPrice()`); 2a Lows (user-delete cascade decision, overlapping-offer race, learner delete-guards); 2c Lows L2 (last-writer-wins page edits) and L4 (onboarding agreement text shows raw markdown); double queue hop; `footerPages` query per response; finding #9 disputed.
- **Owed downstream (CP3):** freeze the trial price from `trialPrice()`, validate `bookable()` and the permit at booking, decide user-delete behaviour; CP7 maintains `rating_avg`/`rating_count`.

## §7 Next step and owner actions
Owner action 1: GO to merge PR #8 (https://github.com/rizwanoor80/eLearning-Platform/pull/8) — Option 1 (Recommended): GO, no open Medium/High (CI green, fresh review clean, diff scoped, no protected or frozen file touched); option 2: hold and list changes you want first. Reply `update` with the GO as a ruling in the next PLAN revision. Claude Code will not merge it. After the merge, step 2 (`cp/2.5b-year-groups`) starts from the merged `main`.
Carried, optional: Owner action A (R7 branch protection on `main`, exact GitHub UI steps in cycle 01's CYCLE-LOG 18:35 BLOCKER); Owner action B (`.claude/settings.local.json`, ADR-002). Step 4 will need your Forge account, provider and server (R38) before Claude Code can start it — you may do that in parallel.

## §8 Cycle 03 board
| Step | State | Branch | PR | Review verdict | Owner GO / merge |
|---|---|---|---|---|---|
| 1 rulings + Lows | built, CI green, reviewed — **awaiting owner GO** | `cp/2.5a-rulings` | [#8](https://github.com/rizwanoor80/eLearning-Platform/pull/8) | 1 round; 11 verdicts PASS/PASS WITH NOTE, 4 Low, no Med/High | GO pending |
| 2 year groups (R33) | not started (after PR #8 merges) | `cp/2.5b-year-groups` | — | — | — |
| 3 tutor lifecycle (R36) | not started | `cp/2.5c-tutor-lifecycle` | — | — | — |
| 4 rehearsal server (R38) | not started (owner actions first) | — | — | — | — |
| 5 close (R37 docs) | not started | — | — | — | — |

Cycle 02 (programme CP1–CP2) is complete: sub-cycles 1a `c08bad8`, 1b `10e6198`, 1c `407a687`, 2a `4bae31a`, 2b `1c5ee05`, 2c `b0af825` (PRs #2–#7).

## Deferred (out of v1 scope — do not build)
- Lesson packs / subscriptions / credits
- Group classes
- Courses / self-paced content
- Learning plans, homework, assessments
- B2B organisations / tenancy
- Multi-currency
- WhatsApp notifications
- Meilisearch
