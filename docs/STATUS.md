# STATUS — cycle 03 r1 — written 2026-09-20 11:45
Tests: 428 (branch `cp/2.5a-rulings`, unmerged; main 390) · Advisor: cycle 03 step 1 consulted 2 times (design 09:30, pre-PR 11:30) · Review: step 1 pending (CI, then a fresh review)

## §1 Git state
`main` = `0a10c2c` (cycle 02's end) plus docs commits: PLAN cycle 03 r1 (`8bec280`) and the log. `cp/2.5a-rulings` carries three commits, suite 428 / 2106 assertions, `npm run build` ✓, `vue-tsc` exit 0; pushed and PR #8 opened after this STATUS write. Cycle 03 is a hand-run cycle: **Claude Code merges no PR** — each waits for the owner's GO, given as a ruling in the next PLAN revision. Branch protection on `main`: **not set** (owner action, carried, R23). `.claude/settings.local.json` (R14): not created — optional, carried.

## §2 Step map (cycle 03 r1 — "CP2.5")
1. `cp/2.5a-rulings` — [built, PR #8 opening, awaiting CI and fresh review; then halt for the owner's GO] — R32, R34, R35 and the eleven pure-fix Lows.
2. `cp/2.5b-year-groups` — [not started] (R33).
3. `cp/2.5c-tutor-lifecycle` — [not started] (R36 plus the named Lows).
4. Rehearsal server — [not started] (R38; owner actions first).
5. Close — [not started] (R37 docs; halt).

## §3 What changed this run
- Owner replied `update`; PLAN cycle 03 r1 committed (`8bec280`) before other work. The owner's rulings R32–R38 answer cycle 02's Owner actions 2–5 and acknowledge the 24-hour review.
- Step 1 built (three commits): **R32** `TutorProfile::displayName()` (first Unicode-whitespace-split word; empty → "Tutor"), used by the search card, the profile and the suggestions email; **R34** `SlotCalculator` offers nothing on or after the permit's expiry day (UTC midnight, exclusive), tested with a permit five days out against the 14-day window and as a change in search membership; **R35** the three budget-tier labels are content blocks (`match_budget_low|mid|high`) with in-code fallback; **Lows**: agreement-version error shown, profile id route constraint, `/tutors` validation errors redirect to `/tutors` and render, `booking_max_days` bounded at 90 (and clamped in the loader), `Lesson::$fillable` drops `status`, `forTutors` no longer mutates callers' models, `CloseMatchRequest` locks the row, deleted-request mail job dropped quietly, a `User` observer keeps the self-learner name in step, `Register.vue` uses the checkbox component with `value="1"`, global test-helper names prefixed.
- Tests: 38 new (428 / 2106 assertions). Details in docs/CYCLE-LOG.md (09:00–11:32).

## §4 Decisions
- Cycle-02 Owner actions 2–5 are answered by R32–R35 (owner rulings in the plan). Step-1 semantics in CYCLE-LOG DECISION 11:31 (name split, permit cutoff, 90-day horizon, label blocks, observer, fillable, forTutors, close lock).

## §5 Why stopping
Not stopping yet — building through CI and the fresh review for PR #8. After the review the run halts at the owner's GO gate (PLAN step 1: "halt: yes, owner GO to merge").

## §6 Mismatches
- **Branch protection on `main` not set** (owner action, carried; R23).
- **CP1 box 7 admin-payout clause deferred to CP5** (R25) — "CP1 done" stays qualified.
- **Step 1 disclosures:** the two `file_get_contents(...)->toContain(...)` assertions are R20 proxies for Vue; `deleteWhenMissingModels` is proven in three parts and the worker's delete-on-missing is the framework's; `TutorSearchRequest::getRedirectUrl()` overrides a framework hook and drops the other query parameters on a failed validation (a Low); navigation label "Homepage content" is now "Site content"; seeder/content-block counts 4→7 are growth; `UserResource` has no Edit page, so admin-side rename through Filament is not reachable today; 90 days is my number (PRD silent).
- **Diff-scope files outside the step's named areas** (all pre-declared at CYCLE-LOG 09:30): `SlotCalculator.php`, `TutorPresenter.php`, `TutorProfile.php`, `ContentBlockForm.php`, `EditContentBlock.php`, `ContentBlockResource.php`, `ContentBlockSeeder.php`, `MatchRequestController.php`, `MatchRequestInfolist.php`, `Onboarding.vue`, `routes/web.php`, `TutorSearchRequest.php`, `tutors/Index.vue`, `ManageSettings.php`, `Lesson.php`, `CloseMatchRequest.php`, `SendMatchSuggestionsMail.php`, `app/Observers/UserObserver.php`, `User.php`, `ProfileController.php`, `Register.vue`, `BudgetTierLabels.php`, the renamed-helper test files. (`BudgetTier.php` was pre-declared but untouched.)
- **Remaining follow-up list (steps 2–3 of this cycle):** year groups (R33, step 2); tutor lifecycle R36 items and the step-3 Lows (Fortify mail sender, "Submitted" sort column, transactional last-admin check, duplicate price-band, document-type `code`/`sort` validation, admin Disable confirmation). Not named in steps 1–3 and staying on the list for CP3's plan: `whereDate` in `bookable()` may bypass the new index; in-memory search pagination; the onboarding trial-price preview rounds `100 − pct` (CP3 must unify with `trialPrice()`); 2a Lows (user-delete cascade decision, overlapping-offer race, learner delete-guards); 2c Lows L2 (last-writer-wins page edits) and L4 (onboarding agreement text shows raw markdown); double queue hop; `footerPages` query per response; finding #9 disputed.
- **Owed downstream (CP3):** freeze the trial price from `trialPrice()`, validate `bookable()` and the permit at booking, decide user-delete behaviour; CP7 maintains `rating_avg`/`rating_count`.

## §7 Next step and owner actions
No owner action is open yet — the GO for PR #8 becomes Owner action 1 once the review lands. Next: CI on PR #8, a fresh-subagent adversarial review, then this run halts at the owner's GO gate. Carried, optional: Owner action A (R7 branch protection on `main`, exact GitHub UI steps in cycle 01's CYCLE-LOG 18:35 BLOCKER); Owner action B (`.claude/settings.local.json`, ADR-002). Step 4 will need the owner's Forge account, provider and server (R38) before Claude Code can start it.

## §8 Cycle 03 board
| Step | State | Branch | PR | Review verdict | Owner GO / merge |
|---|---|---|---|---|---|
| 1 rulings + Lows | built, awaiting CI + review | `cp/2.5a-rulings` | #8 (opening) | pending | — |
| 2 year groups (R33) | not started | `cp/2.5b-year-groups` | — | — | — |
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
