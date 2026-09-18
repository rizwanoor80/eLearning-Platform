# STATUS — cycle 02 r5 — written 2026-09-19 14:50
Tests: 300 (branch `cp/2a-learners-slots`, unmerged; main 236) · Advisor: 2a consulted 2 times (design 13:30, pre-PR 14:40) · Review: 2a pending (not yet dispatched)

## §1 Git state
`main` = `407a687` (PR #4, sub-cycle 1c) plus docs commits. `cp/2a-learners-slots` carries one commit (`5c7c718`), suite 300/974, `npm run build` ✓, `vue-tsc` clean; it is pushed and PR opened after this STATUS write. Branch protection on `main`: **not set** (owner action, carried, R23). `.claude/settings.local.json` (R14): not created — optional, carried.

## §2 Step map (cycle 02 r5)
0–3. Step 0 and sub-cycles 1a, 1b, 1c — [done] (`646ce06`, `c08bad8`, `10e6198`, `407a687`). CP1 complete except box 7's admin-payout clause (CP5, R25).
4. Sub-cycle 2a `cp/2a-learners-slots` — [built, PR opened, awaiting CI and fresh-subagent review] — closes CP2 box 2 only.
5. Sub-cycle 2b `cp/2b-search-profile` — [not started]
6. Sub-cycle 2c `cp/2c-match-pages-content` — [not started] (closes CP2)
7. Programme end — [not started] — planned halt (24-hour owner review).

## §3 What changed this run
- Sub-cycle 1c merged (`407a687`) — see the previous record; post-merge smoke green.
- 2a built: `learners` table + `Learner` model/factory/`LearnerPolicy`; `CreateLearner` (forces `is_minor=true`), `CreateSelfLearner`, `UpdateLearner`, `DeleteLearner` (self-learner undeletable), `SyncSelfLearnerName`; `/learners` CRUD pages under the parent gate; registration checkbox "I am an adult student" creating a self-learner; minimal `lessons` and `recurring_slots` stubs (+ `LessonStatus`, `RecurringSlotStatus` enums, partial unique indexes); `SlotCalculator` (pure core + loader) with the CHECKPOINTS CP2 box 2 matrix, DST, boundaries, weekly-slot blocking 10 weeks out.
- Tests: 64 new (unit matrix, loader/R30 dependencies, learner CRUD/registration/sync). 300 tests / 974 assertions.

## §4 Decisions
- R30 list for 2a (CYCLE-LOG 13:30, nine items, a test each); stub decision (13:31); `timestamp` not `timestampTz` for lessons (14:41, caught by a test).
- Exceptions read in the tutor's current user timezone (no timezone column on `availability_exceptions`).

## §5 Why stopping
Not stopping — sub-cycle 2a is built; continuing to PR, CI and the fresh-subagent review per R21. Continuations used: 6/8 (this CI wait is the sixth).

## §6 Mismatches
- **R25 deviation:** `learners.curriculum_id` and `year_group` are nullable (DATA_MODEL has them non-null): the adult student's self-learner exists from registration with nothing to put in them. Consequence: an adult student cannot search or request a match until they edit the learner (2b/2c must handle a null curriculum).
- **Files outside step 4's named areas:** `app/Actions/Fortify/CreateNewUser.php` and `resources/js/pages/auth/Register.vue` (auth boundary, in the design ADVISOR), `app/Http/Controllers/Settings/ProfileController.php` (R30 #9 rename sync), `app/Models/User.php` (relation), `resources/js/pages/Dashboard.vue` (entry link), `app/Exceptions/LearnerDeletionException.php`.
- **Invariant #2 in tests:** `SlotCalculatorLoaderTest` writes `lessons.status` directly in two tests as a stand-in for CP3's state machine. Test-only; no app code mutates it.
- **Stubs:** `lessons` and `recurring_slots` are minimal; CP3/CP4 extend by `Schema::table`; no `learner_id` on purpose; learner delete-guards for live lessons/weekly slots are owed by CP3/CP4.
- **Owed downstream:** learner curriculum/year-group edits must be snapshotted or re-validated by 2c match requests and CP3 booking; 2b/CP3 callers must apply `bookable()` (the calculator deliberately does not).
- **Lows:** global Pest helper names in the three new test files will collide when reused — prefix later; `Register.vue` uses a raw checkbox where the form otherwise uses `ui/` components.
- Carried: branch protection; box 7's payout clause (CP5); the CP1 follow-up list (post-approval re-vetting, reinstate-from-suspended, `review_note` clearing, replaced-file cleanup, `onOneServer`, document-type validation, Disable confirmation, approval-time permit/rate re-check, price bands editable in place, permit-edit not resetting the accepted permit scan, optional-document review gap); programme-end review list from PLAN r5.

## §7 Next step and owner actions
No owner action needed. Next: CI on the 2a PR, then a fresh-subagent adversarial review; if no Medium/High, merge under R21 and continue to 2b. Continuations used under R21's cap: **6/8**. Any Medium/High halts the programme ("awaiting owner"); no fix loop is authorised for 2a unless a PLAN ruling grants one. Carried, optional: Owner action A (R7 branch protection, steps in cycle 01's CYCLE-LOG 18:35 BLOCKER); Owner action B (`.claude/settings.local.json`).

## §8 Programme board (R21)
| Sub-cycle | State | Branch | PR | Review verdict | Merge hash |
|---|---|---|---|---|---|
| 1a onboarding-core | **merged** | `cp/1a-onboarding-core` | [#2](https://github.com/rizwanoor80/eLearning-Platform/pull/2) | 9 PASS, 2 Low notes, no Medium/High | `c08bad8` |
| 1b onboarding-complete | **merged** | `cp/1b-onboarding-complete` | [#3](https://github.com/rizwanoor80/eLearning-Platform/pull/3) | 3 rounds; final 13 PASS, no Med/High | `10e6198` |
| 1c admin-approval | **merged** | `cp/1c-admin-approval` | [#4](https://github.com/rizwanoor80/eLearning-Platform/pull/4) | 3 rounds; final 10 PASS/PASS WITH NOTE + 3 Low, no Med/High | `407a687` |
| 2a learners-slots | built, PR opened, awaiting CI + review | `cp/2a-learners-slots` | opened next (see §1) | pending | — |
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
