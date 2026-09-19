# STATUS — cycle 02 r5 — written 2026-09-19 17:30
Tests: 345 (branch `cp/2b-search-profile`, unmerged; main 300) · Advisor: 2b consulted 2 times (design 16:05, pre-PR 17:20) · Review: 2b pending (not yet dispatched)

## §1 Git state
`main` = `4bae31a` (PR #5, sub-cycle 2a) plus docs commits. `cp/2b-search-profile` carries two commits (`4c5466e`, `80b8279`), suite 345/1565, `npm run build` ✓, `vue-tsc` exit 0; pushed and PR opened after this STATUS write. Branch protection on `main`: **not set** (owner action, carried, R23). `.claude/settings.local.json` (R14): not created — optional, carried.

## §2 Step map (cycle 02 r5)
0–4. Step 0 and sub-cycles 1a, 1b, 1c, 2a — [done]. CP1 complete except box 7's admin-payout clause (CP5, R25); CP2 box 2 closed.
5. Sub-cycle 2b `cp/2b-search-profile` — [built, PR opening, awaiting CI and fresh-subagent review] — closes CP2 box 1 and the toggle half of box 3 (box 3's match-request half is 2c).
6. Sub-cycle 2c `cp/2c-match-pages-content` — [not started] (closes CP2)
7. Programme end — [not started] — planned halt (24-hour owner review).

## §3 What changed this run
- Sub-cycle 2a merged (`4bae31a`) with post-merge smoke green (see the previous record).
- 2b built: public `/tutors` search and `/tutors/{id}` profile; `TutorSearch` over `TutorProfile::bookable()` with curriculum / subject / year group / price / min-rating filters, day and time-of-day filters in the viewer's timezone, ≥1 slot in the next `min(booking_max_days, 14)` days, rating or price sort, in-memory pagination, `?learner=` prefill for the owning parent; `SlotCalculator::forTutors()` (one query per input table, per-tutor exception timezone); `TutorProfile::trialPrice()` (single source, rounding rule pinned); `TutorPresenter` allow-list; `feature:` middleware + shared `features` prop; a migration adding four search indexes.
- Tests: 45 new (345 / 1565 assertions).

## §4 Decisions
- R30 list for 2b (CYCLE-LOG 16:05, 17 items, a test each); semantics DECISION (16:06); trial-price rounding (17:21).

## §5 Why stopping
Not stopping — 2b is built; continuing to PR, CI and the fresh-subagent review per R21. Continuations used: 7/8 (this CI wait is the seventh; 2c's will be the eighth; the programme end is a planned halt).

## §6 Mismatches
- **Branch protection on `main` not set** (owner action, carried; R23).
- **CP1 box 7 admin-payout clause deferred to CP5** (R25) — "CP1 done" stays qualified.
- **R25 / PRD gaps disclosed in 2b:** (a) PRD §2.2 lists no name field for the public profile — the first word of the account name is shown, nothing else (Owner action 1); (b) year group is free text on both sides (`learners.year_group`, `tutor_subjects.level_min/max`) — the filter compares the first integer within the chosen curriculum and stays permissive when unparseable, low fidelity (Owner action 2); (c) the min-rating filter excludes unrated tutors and is inert until CP7 maintains `rating_avg`/`rating_count`; (d) **slots are offered beyond `permit_expires_at`**: `bookable()` is evaluated now while the window runs 14 days out, and CP3's `BookLesson` validates at booking time, not lesson time — a PRD gap, not a violation (Owner action 3).
- **Diff-scope files outside step 5's named areas:** `EnsureFeatureEnabled.php` (new), `bootstrap/app.php` (alias), `HandleInertiaRequests.php` (`features` share), `Welcome.vue` and `Dashboard.vue` (entry links), `resources/js/app.ts` (layout resolver: `tutors/*` pages render without the app shell), `resources/js/components/PublicHeader.vue` (new), a migration adding indexes, `TutorProfile.php` (`trialPrice()`), `SlotCalculator.php` (batch loader).
- **Lows:** GET validation failures on `/tutors` redirect back with session errors that `Index.vue` does not render; `bookable()`'s `whereDate(...)` casts the column and may bypass the new `(status, permit_expires_at)` index on Postgres (pre-existing); pagination is in memory over the filtered set (fine at v1 scale).
- **Owed downstream:** CP3 freezes the trial price from `trialPrice()` and validates `bookable()` at booking; CP7 maintains `rating_avg`/`rating_count`; 2c reuses `feature:match_requests`; CP3/CP4 learner delete-guards.
- **Carried from earlier sub-cycles:** 2a Lows (`max_days` upper bound, admin-side rename sync, `Lesson::$fillable` status, user-delete cascade, overlapping-offer race, Pest helper name collisions); the CP1 follow-up list; programme-end review list from PLAN r5; finding #9 disputed.

## §7 Next step and owner actions
Owner action 1: Public tutor profile name — currently the first word of the account name only. Option 1 (Recommended): keep first name only — it protects tutors' privacy on a public page; option 2: first name plus last initial; option 3: full name. Reply `update` when decided (a PLAN ruling).
Owner action 2: Year groups — currently free text, so the year-group filter is low fidelity. Option 1 (Recommended): a controlled year-group list per curriculum in the next hand-run cycle — a schema change, so it needs a plan of its own; option 2: keep free text with the permissive filter. Reply `update` when decided.
Owner action 3: Slots beyond a permit's expiry — a tutor whose permit expires in five days shows slots up to day 14. Option 1 (Recommended): cap offered slots at `permit_expires_at`, decided in the CP3 plan alongside booking validation; option 2: leave as is and reject at booking. Reply `update` when decided.
None of these blocks the programme. Next: CI on the 2b PR, then a fresh-subagent adversarial review; if no Medium/High, merge under R21, then sub-cycle 2c. Continuations used under R21's cap: **7/8**. Any Medium/High halts the programme ("awaiting owner"); no fix loop is authorised for 2b. Carried, optional: Owner action A (R7 branch protection, steps in cycle 01's CYCLE-LOG 18:35 BLOCKER); Owner action B (`.claude/settings.local.json`).

## §8 Programme board (R21)
| Sub-cycle | State | Branch | PR | Review verdict | Merge hash |
|---|---|---|---|---|---|
| 1a onboarding-core | **merged** | `cp/1a-onboarding-core` | [#2](https://github.com/rizwanoor80/eLearning-Platform/pull/2) | 9 PASS, 2 Low notes, no Medium/High | `c08bad8` |
| 1b onboarding-complete | **merged** | `cp/1b-onboarding-complete` | [#3](https://github.com/rizwanoor80/eLearning-Platform/pull/3) | 3 rounds; final 13 PASS, no Med/High | `10e6198` |
| 1c admin-approval | **merged** | `cp/1c-admin-approval` | [#4](https://github.com/rizwanoor80/eLearning-Platform/pull/4) | 3 rounds; final 10 PASS/PASS WITH NOTE + 3 Low, no Med/High | `407a687` |
| 2a learners-slots | **merged** | `cp/2a-learners-slots` | [#5](https://github.com/rizwanoor80/eLearning-Platform/pull/5) | 1 round; 7 verdicts PASS/PASS WITH NOTE, 3 Low, no Med/High | `4bae31a` |
| 2b search-profile | built, PR opening, awaiting CI + review | `cp/2b-search-profile` | opened next (see §1) | pending | — |
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
