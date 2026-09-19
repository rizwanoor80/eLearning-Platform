# STATUS — cycle 02 r5 — written 2026-09-19 15:30
Tests: 300 (merged main, up from 236) · Advisor: 2a consulted 2 times (design 13:30, pre-PR 14:40); 2b design consult next · Review: 2a merged after one fresh review — no Medium/High (3 Low + 2 notes)

## §1 Git state
`main` = `4bae31a` (PR #5 merged with a merge commit) plus this post-merge record. Sub-cycles 1a, 1b, 1c, 2a merged (`c08bad8`, `10e6198`, `407a687`, `4bae31a`). Branch protection on `main`: **not set** (owner action, carried, R23). `.claude/settings.local.json` (R14): not created — optional, carried.

## §2 Step map (cycle 02 r5)
0–3. Step 0 and sub-cycles 1a, 1b, 1c — [done]. CP1 complete except box 7's admin-payout clause (CP5, R25).
4. Sub-cycle 2a `cp/2a-learners-slots` — [done] `4bae31a`. CP2 box 2 closed.
5. Sub-cycle 2b `cp/2b-search-profile` — [in progress] — design ADVISOR (R30 list) before the first edit.
6. Sub-cycle 2c `cp/2c-match-pages-content` — [not started] (closes CP2)
7. Programme end — [not started] — planned halt (24-hour owner review).

## §3 What changed this run
- PR #5 CI green (run https://github.com/rizwanoor80/eLearning-Platform/actions/runs/35406831517); fresh review: no Medium/High; merged under R21 as `4bae31a`.
- Post-merge on `main`: `composer.bat test` 300 tests / 974 assertions, Pint + PHPStan (0 errors) passed; `npm run build` ✓; local `migrate` applied the three 2a migrations; curl `/` `/login` `/admin/login` `/register` → 200, `/dashboard` `/learners` `/tutor/onboarding` `/admin` → 302.

## §4 Decisions
- 2a merged under R21 (CYCLE-LOG DECISION 15:11); its five Lows go to follow-up lists, no fix loop.
- R30 stays binding for 2b/2c design consults.

## §5 Why stopping
Not stopping — 2a is closed; the programme continues into 2b per R21. Continuations used: 6/8.

## §6 Mismatches
- **Branch protection on `main` not set** (owner action, carried; R23 — does not block).
- **CP1 box 7 admin-payout clause deferred to CP5** (R25) — "CP1 done" stays qualified.
- **R25 deviation (merged in 2a):** `learners.curriculum_id`/`year_group` nullable for the adult student's self-learner; they cannot search or request a match until they edit the learner (2b/2c must handle a null curriculum).
- **2a review Lows / notes for later:** `max_days` setting has no upper bound (`ManageSettings.php:83` — `calculate()` took 4.4 s at 365 days with heavy rules, OOM at 3650); admin-side rename in Filament does not sync the self-learner name (R30 #9 gap); `Lesson::$fillable` includes `status` (CP3 to drop when it adds the state machine); hard-deleting a user cascades to learners including the self-learner (CP3 must decide once `lessons.learner_id` exists); overlapping availability offers overlapping candidate slots (CP3's transactional check owns the race); learner delete-guards for live lessons/weekly slots owed by CP3/CP4; global Pest helper names in the 2a test files will collide when reused; raw checkbox in `Register.vue`.
- **CP1 follow-up list (not built):** post-approval re-vetting; reinstate-from-suspended; `review_note` clearing; replaced-file disk cleanup; `onOneServer` on the permit job; document-type `code`/`sort` validation; admin Disable confirmation; approval-time permit/rate re-check; price bands editable in place; permit edit not resetting the accepted permit scan; optional-document review gap. Programme-end review list from PLAN r5 carried.
- **Finding #9** stays disputed (PRD §9 line 180; CHECKPOINTS).

## §7 Next step and owner actions
No owner action needed. Next: 2b design ADVISOR (R30 list with a test per dependency: search filters vs `bookable()`, null-curriculum learners, slot batching, feature gates), then build on `cp/2b-search-profile`. Continuations used under R21's cap: **6/8**; the next CI wait is written as 7/8 before it is scheduled. Carried, optional: Owner action A (R7 branch protection, steps in cycle 01's CYCLE-LOG 18:35 BLOCKER); Owner action B (`.claude/settings.local.json`).

## §8 Programme board (R21)
| Sub-cycle | State | Branch | PR | Review verdict | Merge hash |
|---|---|---|---|---|---|
| 1a onboarding-core | **merged** | `cp/1a-onboarding-core` | [#2](https://github.com/rizwanoor80/eLearning-Platform/pull/2) | 9 PASS, 2 Low notes, no Medium/High | `c08bad8` |
| 1b onboarding-complete | **merged** | `cp/1b-onboarding-complete` | [#3](https://github.com/rizwanoor80/eLearning-Platform/pull/3) | 3 rounds; final 13 PASS, no Med/High | `10e6198` |
| 1c admin-approval | **merged** | `cp/1c-admin-approval` | [#4](https://github.com/rizwanoor80/eLearning-Platform/pull/4) | 3 rounds; final 10 PASS/PASS WITH NOTE + 3 Low, no Med/High | `407a687` |
| 2a learners-slots | **merged** | `cp/2a-learners-slots` | [#5](https://github.com/rizwanoor80/eLearning-Platform/pull/5) | 1 round; 7 verdicts PASS/PASS WITH NOTE, 3 Low, no Med/High | `4bae31a` |
| 2b search-profile | in progress (design) | `cp/2b-search-profile` | — | — | — |
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
