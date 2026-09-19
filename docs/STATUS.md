# STATUS — cycle 02 r5 — written 2026-09-19 21:20
Tests: 390 (branch `cp/2c-match-pages-content`, unmerged; main 345) · Advisor: 2c consulted 2 times (design 19:00, pre-PR 21:10) · Review: 2c pending (not yet dispatched)

## §1 Git state
`main` = `1c5ee05` (PR #6, sub-cycle 2b) plus docs commits. `cp/2c-match-pages-content` carries four commits (`dde9a32`, `e83986f`, `ff91e23`, `384abd9`), suite 390 / 1976 assertions, `npm run build` ✓, `vue-tsc` exit 0; pushed and PR opened after this STATUS write. Branch protection on `main`: **not set** (owner action, carried, R23). `.claude/settings.local.json` (R14): not created — optional, carried.

## §2 Step map (cycle 02 r5)
0–5. Step 0 and sub-cycles 1a, 1b, 1c, 2a, 2b — [done]. CP1 complete except box 7's admin-payout clause (CP5, R25); CP2 boxes 1 and 2 closed.
6. Sub-cycle 2c `cp/2c-match-pages-content` — [built, PR opening, awaiting CI and fresh-subagent review] — closes CP2 box 3 (match half; the toggle half closed in 2b), 4, 5, 6.
7. Programme end — [not started] — planned halt (24-hour owner review).

## §3 What changed this run
- Sub-cycle 2b merged (`1c5ee05`) with post-merge smoke green.
- 2c built: match requests (parent create/list under `feature:match_requests`, admin Filament queue with Suggest tutors / Close, `bookable()` at the picker, the action and the send, suggestions email from the public tutor card); versioned public pages (Filament editor where saving is Publish, versions relation manager, six public routes, footer links, insert-missing `PageSeeder`); the agreement form now echoes the version it displayed and a stale or missing version is refused; content blocks (Filament editor, homepage rendered from four blocks, insert-missing seeder).
- Tests: 45 new (390 / 1976 assertions).

## §4 Decisions
- R30 list for 2c (CYCLE-LOG 19:00, 21 items, a test each); pre-push decisions (21:11).

## §5 Why stopping
Not stopping — 2c is built; continuing to PR, CI and the fresh-subagent review per R21. Continuations used: 8/8 (this CI wait is the eighth and the last automatic continuation); the programme end is a planned halt.

## §6 Mismatches
- **Branch protection on `main` not set** (owner action, carried; R23).
- **CP1 box 7 admin-payout clause deferred to CP5** (R25) — "CP1 done" stays qualified.
- **2c disclosures:** (a) `budget_tier` values (`low|mid|high`) are my choice — PRD §2.3 says only "budget band" (Owner action 5); (b) DATA_MODEL has no draft state for pages, so saving in the Filament editor IS publishing — the editor's Preview tab is the preview; (c) PRD §8 has no admin notification on a match-request submission (the queue is the mechanism) — none built; (d) the agreement acceptance now requires the echoed `version` — this edits merged 1b code (`TutorOnboardingController::storeAgreement`, `AgreementStepRequest`, `Onboarding.vue`) under R30, and every agreement POST in the onboarding tests now sends `version`; (e) `tests/Pest.php` now holds `agreementReadyTutor()` (moved from the onboarding test file and made re-callable within one test: it reuses an existing GCSE curriculum and price band); (f) R20 limits — the agreement form's Vue `watch`, and match entry-point absence, are proven by build/type-check, server re-render tests and the shared `features` prop, not a browser; the `lockForUpdate()` in `PublishPage` is proven by reading, with the unique `(page_id, version)` index as the tested backstop; (g) `SendMatchSuggestionsMail` (ShouldQueue) sends a ShouldQueue mailable — the same double hop as the tutor mails; (h) `footerPages` runs one `pages` query on every Inertia response (cache candidate); (i) `@tailwindcss/typography` is not installed, so `prose` classes on rendered markdown are inert (unstyled, not broken) — not added; (j) the Filament markdown editors have the attach-files button disabled; (k) `DatabaseSeederTest` counts grew (pages 1→6, versions 1→6, blocks 0→4); (l) the "suggestions dropped" audit row has `actor_user_id = null` (system).
- **Diff-scope files outside step 6's named areas** (beyond those pre-declared at 19:00): `resources/js/types/global.d.ts`, `resources/js/layouts/app/AppSidebarLayout.vue`, `resources/js/pages/tutors/Show.vue`, `tests/Pest.php`, `app/Http/Controllers/HomeController.php` (replaces `Route::inertia('/')`), `app/Support/{Markdown,PublicPages,HomepageContent}.php`.
- **2b review Lows for the follow-up list:** overflowing profile id → 500; fragile first-name split; onboarding trial-price preview rounds `100 − pct`; `loadMissing` mutating caller models; unrendered GET validation errors on `/tutors`; `whereDate` index bypass; in-memory pagination.
- **Carried from earlier:** 2a Lows; the CP1 follow-up list; programme-end review list from PLAN r5; finding #9 disputed; owed downstream — CP3 freezes the trial price from `trialPrice()` and validates `bookable()` at booking, CP7 maintains `rating_avg`/`rating_count`, CP3/CP4 learner delete-guards.

## §7 Next step and owner actions
Owner action 1: 24-hour review at the programme end (PLAN step 7, the §12 review point) — reply `update` to acknowledge, or `where are we?` to the planner. This is the halt; nothing further runs until then.
Owner action 2: Public tutor profile name — currently the first word of the account name only. Option 1 (Recommended): keep first name only — it protects tutors' privacy on a public page; option 2: first name plus last initial; option 3: full name. Reply `update` when decided (a PLAN ruling).
Owner action 3: Year groups — currently free text, so the year-group filter is low fidelity. Option 1 (Recommended): a controlled year-group list per curriculum in the next hand-run cycle — a schema change, so it needs a plan of its own; option 2: keep free text with the permissive filter. Reply `update` when decided.
Owner action 4: Slots beyond a permit's expiry — a tutor whose permit expires in five days shows slots up to day 14. Option 1 (Recommended): cap offered slots at `permit_expires_at`, decided in the CP3 plan alongside booking validation; option 2: leave as is and reject at booking. Reply `update` when decided.
Owner action 5: Match-request budget tiers — the form offers Budget-friendly / Mid-range / Premium (stored `low|mid|high`) because PRD §2.3 names no bands. Option 1 (Recommended): keep the three tiers and relabel later through a PLAN ruling; option 2: tie the tiers to price bands per curriculum (a schema and copy decision for a hand-run cycle). Reply `update` when decided.
Next automatically: CI on the 2c PR, a fresh-subagent adversarial review, and — if there is no Medium/High — the merge, the post-merge record and the cycle END. Continuations used under R21's cap: **8/8**, the last. Any Medium/High halts the programme ("awaiting owner"); no fix loop is authorised for 2c. Carried, optional: Owner action A (R7 branch protection, steps in cycle 01's CYCLE-LOG 18:35 BLOCKER); Owner action B (`.claude/settings.local.json`).

## §8 Programme board (R21)
| Sub-cycle | State | Branch | PR | Review verdict | Merge hash |
|---|---|---|---|---|---|
| 1a onboarding-core | **merged** | `cp/1a-onboarding-core` | [#2](https://github.com/rizwanoor80/eLearning-Platform/pull/2) | 9 PASS, 2 Low notes, no Medium/High | `c08bad8` |
| 1b onboarding-complete | **merged** | `cp/1b-onboarding-complete` | [#3](https://github.com/rizwanoor80/eLearning-Platform/pull/3) | 3 rounds; final 13 PASS, no Med/High | `10e6198` |
| 1c admin-approval | **merged** | `cp/1c-admin-approval` | [#4](https://github.com/rizwanoor80/eLearning-Platform/pull/4) | 3 rounds; final 10 PASS/PASS WITH NOTE + 3 Low, no Med/High | `407a687` |
| 2a learners-slots | **merged** | `cp/2a-learners-slots` | [#5](https://github.com/rizwanoor80/eLearning-Platform/pull/5) | 1 round; 7 verdicts PASS/PASS WITH NOTE, 3 Low, no Med/High | `4bae31a` |
| 2b search-profile | **merged** | `cp/2b-search-profile` | [#6](https://github.com/rizwanoor80/eLearning-Platform/pull/6) | 1 round; 9 verdicts PASS/PASS WITH NOTE, 3 Low, no Med/High | `1c5ee05` |
| 2c match-pages-content | built, PR opening, awaiting CI + review | `cp/2c-match-pages-content` | opened next (see §1) | pending | — |

## Deferred (out of v1 scope — do not build)
- Lesson packs / subscriptions / credits
- Group classes
- Courses / self-paced content
- Learning plans, homework, assessments
- B2B organisations / tenancy
- Multi-currency
- WhatsApp notifications
- Meilisearch
