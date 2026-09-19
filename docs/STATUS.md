# STATUS — cycle 02 r5 — written 2026-09-19 18:35
Tests: 345 (merged main, up from 300) · Advisor: 2b consulted 2 times (design 16:05, pre-PR 17:20); 2c design consult next · Review: 2b merged after one fresh review — no Medium/High (3 Low)

## §1 Git state
`main` = `1c5ee05` (PR #6 merged with a merge commit) plus this post-merge record. Sub-cycles 1a, 1b, 1c, 2a, 2b merged (`c08bad8`, `10e6198`, `407a687`, `4bae31a`, `1c5ee05`). Branch protection on `main`: **not set** (owner action, carried, R23). `.claude/settings.local.json` (R14): not created — optional, carried.

## §2 Step map (cycle 02 r5)
0–5. Step 0 and sub-cycles 1a, 1b, 1c, 2a, 2b — [done]. CP1 complete except box 7's admin-payout clause (CP5, R25); CP2 boxes 1 and 2 closed, box 3's toggle half closed.
6. Sub-cycle 2c `cp/2c-match-pages-content` — [in progress] — design ADVISOR (R30 list) before the first edit; closes CP2 boxes 3 (match half), 4, 5, 6.
7. Programme end — [not started] — planned halt (24-hour owner review).

## §3 What changed this run
- PR #6 CI green (run https://github.com/rizwanoor80/eLearning-Platform/actions/runs/35409646480); fresh review: no Medium/High; merged under R21 as `1c5ee05`.
- Post-merge on `main`: `composer.bat test` 345 tests / 1565 assertions, Pint + PHPStan (0 errors) passed; `npm run build` ✓; curl `/` `/login` `/admin/login` `/register` `/tutors` → 200, `/tutors/1` → 404, `/dashboard` `/learners` `/tutor/onboarding` `/admin` → 302.

## §4 Decisions
- 2b merged under R21 (CYCLE-LOG DECISION 18:11); its three code Lows go to follow-up lists, no fix loop.
- R30 stays binding for the 2c design consult.

## §5 Why stopping
Not stopping — 2b is closed; the programme continues into 2c per R21. Continuations used: 7/8; 2c's CI wait will be the eighth and last automatic continuation.

## §6 Mismatches
- **Branch protection on `main` not set** (owner action, carried; R23).
- **CP1 box 7 admin-payout clause deferred to CP5** (R25) — "CP1 done" stays qualified.
- **PRD gaps from 2b (Owner actions 1–3 below):** first-name-only public display, free-text year group, slots offered beyond `permit_expires_at`; min-rating filter inert until CP7.
- **2b review Lows for the follow-up list:** L1 `GET /tutors/<overflowing id>` → 500 (`whereNumber` does not cap length; hint `->where('tutor', '[0-9]{1,18}')`); L2 first-name split `Str::before($name, ' ')` fragile with leading/non-breaking whitespace (hint `preg_split('/\s+/u', trim($name))[0]`); L3 `Onboarding.vue:248-257` trial-price preview rounds `100 − pct` and differs from `trialPrice()` at odd fils (CP3 must unify); `SlotCalculator::forTutors` `loadMissing` mutates caller models (CP3 callers must not rely on a partial `user`); unrendered GET validation errors on `/tutors`; `whereDate` in `bookable()` may bypass the new index; in-memory pagination.
- **Owed downstream:** CP3 freezes the trial price from `trialPrice()` and validates `bookable()` at booking; CP7 maintains `rating_avg`/`rating_count`; 2c reuses `feature:match_requests`; CP3/CP4 learner delete-guards.
- **Carried from earlier sub-cycles:** 2a Lows (`max_days` upper bound, admin-side rename sync, `Lesson::$fillable` status, user-delete cascade, overlapping-offer race, Pest helper name collisions); the CP1 follow-up list; programme-end review list from PLAN r5; finding #9 disputed.

## §7 Next step and owner actions
Owner action 1: Public tutor profile name — currently the first word of the account name only. Option 1 (Recommended): keep first name only — it protects tutors' privacy on a public page; option 2: first name plus last initial; option 3: full name. Reply `update` when decided (a PLAN ruling).
Owner action 2: Year groups — currently free text, so the year-group filter is low fidelity. Option 1 (Recommended): a controlled year-group list per curriculum in the next hand-run cycle — a schema change, so it needs a plan of its own; option 2: keep free text with the permissive filter. Reply `update` when decided.
Owner action 3: Slots beyond a permit's expiry — a tutor whose permit expires in five days shows slots up to day 14. Option 1 (Recommended): cap offered slots at `permit_expires_at`, decided in the CP3 plan alongside booking validation; option 2: leave as is and reject at booking. Reply `update` when decided.
None of these blocks the programme. Next: 2c design ADVISOR (R30 list with a test per dependency), then build on `cp/2c-match-pages-content`. Continuations used under R21's cap: **7/8**; the 2c CI wait is written as 8/8 before it is scheduled. Carried, optional: Owner action A (R7 branch protection, steps in cycle 01's CYCLE-LOG 18:35 BLOCKER); Owner action B (`.claude/settings.local.json`).

## §8 Programme board (R21)
| Sub-cycle | State | Branch | PR | Review verdict | Merge hash |
|---|---|---|---|---|---|
| 1a onboarding-core | **merged** | `cp/1a-onboarding-core` | [#2](https://github.com/rizwanoor80/eLearning-Platform/pull/2) | 9 PASS, 2 Low notes, no Medium/High | `c08bad8` |
| 1b onboarding-complete | **merged** | `cp/1b-onboarding-complete` | [#3](https://github.com/rizwanoor80/eLearning-Platform/pull/3) | 3 rounds; final 13 PASS, no Med/High | `10e6198` |
| 1c admin-approval | **merged** | `cp/1c-admin-approval` | [#4](https://github.com/rizwanoor80/eLearning-Platform/pull/4) | 3 rounds; final 10 PASS/PASS WITH NOTE + 3 Low, no Med/High | `407a687` |
| 2a learners-slots | **merged** | `cp/2a-learners-slots` | [#5](https://github.com/rizwanoor80/eLearning-Platform/pull/5) | 1 round; 7 verdicts PASS/PASS WITH NOTE, 3 Low, no Med/High | `4bae31a` |
| 2b search-profile | **merged** | `cp/2b-search-profile` | [#6](https://github.com/rizwanoor80/eLearning-Platform/pull/6) | 1 round; 9 verdicts PASS/PASS WITH NOTE, 3 Low, no Med/High | `1c5ee05` |
| 2c match-pages-content | in progress (design) | `cp/2c-match-pages-content` | — | — | — |

## Deferred (out of v1 scope — do not build)
- Lesson packs / subscriptions / credits
- Group classes
- Courses / self-paced content
- Learning plans, homework, assessments
- B2B organisations / tenancy
- Multi-currency
- WhatsApp notifications
- Meilisearch
