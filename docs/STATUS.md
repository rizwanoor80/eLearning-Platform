# STATUS — cycle 02 r5 — written 2026-09-19 22:35
Tests: 390 (merged main, `b0af825`) · Advisor: 16 consultations across cycle 02 (2c: design 19:00, pre-PR 21:10); cycle 01 had 5 · Review: 2c merged after one fresh review — no Medium/High (5 Low)

## §1 Git state
`main` = `b0af825` (PR #7 merged with a merge commit) plus this post-merge record. Sub-cycles 1a, 1b, 1c, 2a, 2b, 2c merged (`c08bad8`, `10e6198`, `407a687`, `4bae31a`, `1c5ee05`, `b0af825`). No feature branch is open. Branch protection on `main`: **not set** (owner action, carried, R23). `.claude/settings.local.json` (R14): not created — optional, carried.

## §2 Step map (cycle 02 r5)
0. Merge PR #1 — [done] `646ce06`.
1–3. Sub-cycles 1a, 1b, 1c — [done] (`c08bad8`, `10e6198`, `407a687`).
4–6. Sub-cycles 2a, 2b, 2c — [done] (`4bae31a`, `1c5ee05`, `b0af825`).
7. Programme end — [reached] — **programme CP1–CP2 complete**; halted for the 24-hour owner review. CP1 is complete except box 7's admin-payout clause (CP5, R25); every CP2 box is closed.

## §3 What changed this run
- R31 pushed; final 1c re-review clean; PR #4 merged (`407a687`).
- 2a merged (`4bae31a`): learners CRUD, adult-student self-learner, `lessons`/`recurring_slots` stubs, `SlotCalculator`.
- 2b merged (`1c5ee05`): public tutor search and profile, batched slot loader, `TutorProfile::trialPrice()`, `feature:` middleware.
- 2c merged (`b0af825`): match requests (parent flow, admin queue, suggestions email), versioned public pages (Filament publish editor, six public routes, footer links, agreement version echo), content blocks and a real homepage.
- Post-merge on `main`: `composer.bat test` 390 tests / 1976 assertions, Pint + PHPStan (0 errors) + RTL passed; `npm run build` ✓; curl `/` and the six pages, `/tutors`, `/login`, `/register`, `/admin/login` → 200; `/dashboard`, `/match-requests`, `/learners`, `/admin` → 302.

## §4 Decisions
- Every merge followed a fresh-subagent review with no open Medium/High, CI green and a scoped diff (R21); Lows went to follow-up lists. Full record in docs/CYCLE-LOG.md (2c: DECISION 22:06, END 22:32).

## §5 Why stopping
**Stopping: programme end.** PLAN step 7 makes this the planned halt for the 24-hour owner review; the resume cap (8/8 automatic continuations) is also used up. Nothing further runs until the owner replies.

## §6 Mismatches
- **Branch protection on `main` not set** (owner action, carried; R23).
- **CP1 box 7 admin-payout clause deferred to CP5** (R25) — "CP1 done" is always qualified.
- **2c disclosures (merged):** `budget_tier` `low|mid|high` is my choice (Owner action 5); saving a page in the editor IS publishing (no draft state in DATA_MODEL); no admin email on a match-request submission (PRD §8); the agreement acceptance now requires the echoed `version` — this edited merged 1b code under R30, and every agreement POST in the onboarding tests now sends it; `tests/Pest.php` holds the shared `agreementReadyTutor()`; R20 limits (Vue `watch`, entry-point absence, `lockForUpdate` proven by reading); double queue hop on the suggestions mail; `footerPages` queries once per Inertia response; `prose` classes inert (no typography plugin); markdown editors have attach-files disabled; seeder counts grew (pages 1→6, versions 1→6, blocks +4); null-actor audit row for dropped suggestions.
- **2c review Lows (follow-up list):** L1 `Onboarding.vue:602` renders only `errors.accepted`, so the stale-agreement-version message is never shown (hint `<InputError :message="agreementForm.errors.version" />`); L2 two admins editing a page: last writer wins; L3 `CloseMatchRequest` takes no lock; L4 the onboarding agreement text shows raw markdown while the public page renders HTML; L5 a `MatchRequest` deleted between queueing and sending fails at deserialisation.
- **2b review Lows:** overflowing profile id → 500 (`->where('tutor', '[0-9]{1,18}')`); fragile first-name split (`preg_split('/\s+/u', trim($name))[0]`); onboarding trial-price preview rounds `100 − pct` (CP3 must unify with `trialPrice()`); `loadMissing` mutating caller models; unrendered GET validation errors on `/tutors`; `whereDate` in `bookable()` may bypass the new index; in-memory pagination.
- **2a review Lows:** `max_days` setting has no upper bound; admin-side rename in Filament does not sync the self-learner; `Lesson::$fillable` includes `status` (CP3 to drop); user-delete cascade decision for CP3; overlapping-offer race (CP3's transactional check); learner delete-guards owed by CP3/CP4; global Pest helper names may collide; raw checkbox in `Register.vue`.
- **CP1 follow-up list (not built):** post-approval re-vetting; reinstate-from-suspended; `review_note` clearing; replaced-file disk cleanup; `onOneServer` on the permit job; document-type `code`/`sort` validation; admin Disable confirmation; approval-time permit/rate re-check; price bands editable in place; a permit edit not resetting the accepted permit scan; optional-document review gap. Programme-end review list from PLAN r5 (Fortify mail sender, queue "Submitted" sort, non-transactional last-admin check, double queue hop, logo/favicon untested, N+1 Docs column, duplicate price-band 500).
- **Owed downstream:** CP3 freezes the trial price from `trialPrice()`, validates `bookable()` at booking and decides the user-delete behaviour; CP7 maintains `rating_avg`/`rating_count`; CP3/CP4 add the learner delete-guards.
- **Finding #9** stays disputed (PRD §9 line 180; CHECKPOINTS).

## §7 Next step and owner actions
Owner action 1: 24-hour review at the programme end (PLAN step 7, the §12 review point) — reply `update` to acknowledge, or `where are we?` to the planner. This is the halt; nothing further runs until then.
Owner action 2: Public tutor profile name — currently the first word of the account name only. Option 1 (Recommended): keep first name only — it protects tutors' privacy on a public page; option 2: first name plus last initial; option 3: full name. Reply `update` when decided (a PLAN ruling).
Owner action 3: Year groups — currently free text, so the year-group filter is low fidelity. Option 1 (Recommended): a controlled year-group list per curriculum in the next hand-run cycle — a schema change, so it needs a plan of its own; option 2: keep free text with the permissive filter. Reply `update` when decided.
Owner action 4: Slots beyond a permit's expiry — a tutor whose permit expires in five days shows slots up to day 14. Option 1 (Recommended): cap offered slots at `permit_expires_at`, decided in the CP3 plan alongside booking validation; option 2: leave as is and reject at booking. Reply `update` when decided.
Owner action 5: Match-request budget tiers — the form offers Budget-friendly / Mid-range / Premium (stored `low|mid|high`) because PRD §2.3 names no bands. Option 1 (Recommended): keep the three tiers and relabel later through a PLAN ruling; option 2: tie the tiers to price bands per curriculum (a schema and copy decision for a hand-run cycle). Reply `update` when decided.
Carried, optional: Owner action A (R7 branch protection on `main`, exact GitHub UI steps in cycle 01's CYCLE-LOG 18:35 BLOCKER); Owner action B (`.claude/settings.local.json`, ADR-002). After the review the planner cuts the next plan (CP3 and the CP1 follow-up list); Forge rehearsal server remains its own hand-run cycle (PLAN "Carried forward").

## §8 Programme board (R21)
| Sub-cycle | State | Branch | PR | Review verdict | Merge hash |
|---|---|---|---|---|---|
| 1a onboarding-core | **merged** | `cp/1a-onboarding-core` | [#2](https://github.com/rizwanoor80/eLearning-Platform/pull/2) | 9 PASS, 2 Low notes, no Medium/High | `c08bad8` |
| 1b onboarding-complete | **merged** | `cp/1b-onboarding-complete` | [#3](https://github.com/rizwanoor80/eLearning-Platform/pull/3) | 3 rounds; final 13 PASS, no Med/High | `10e6198` |
| 1c admin-approval | **merged** | `cp/1c-admin-approval` | [#4](https://github.com/rizwanoor80/eLearning-Platform/pull/4) | 3 rounds; final 10 PASS/PASS WITH NOTE + 3 Low, no Med/High | `407a687` |
| 2a learners-slots | **merged** | `cp/2a-learners-slots` | [#5](https://github.com/rizwanoor80/eLearning-Platform/pull/5) | 1 round; 7 verdicts PASS/PASS WITH NOTE, 3 Low, no Med/High | `4bae31a` |
| 2b search-profile | **merged** | `cp/2b-search-profile` | [#6](https://github.com/rizwanoor80/eLearning-Platform/pull/6) | 1 round; 9 verdicts PASS/PASS WITH NOTE, 3 Low, no Med/High | `1c5ee05` |
| 2c match-pages-content | **merged** | `cp/2c-match-pages-content` | [#7](https://github.com/rizwanoor80/eLearning-Platform/pull/7) | 1 round; 11 verdicts PASS/PASS WITH NOTE, 5 Low, no Med/High | `b0af825` |

**Programme CP1–CP2: complete** (CP1 qualified by box 7's admin-payout clause, deferred to CP5).

## Deferred (out of v1 scope — do not build)
- Lesson packs / subscriptions / credits
- Group classes
- Courses / self-paced content
- Learning plans, homework, assessments
- B2B organisations / tenancy
- Multi-currency
- WhatsApp notifications
- Meilisearch
