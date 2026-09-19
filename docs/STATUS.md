# STATUS — cycle 03 r2 — written 2026-09-20 17:20
Tests: 462 tests / 2383 assertions (branch `cp/2.5b-year-groups`, unmerged; main 428) · Advisor: cycle 03 consulted 4 times so far (step 1 design 09:30, pre-PR 11:30; step 2 design 13:30, pre-PR 17:10) · Review: step 2 pending (CI, then a fresh review)

## §1 Git state
`main` = `a6281eb` (PR #8, step 1) plus docs commits. `cp/2.5b-year-groups` carries three commits (`9cb1258`, `c97eaf0`, `a4b5b0d`), suite 462 / 2383 assertions under plain `composer.bat test`, `npm run build` ✓, `vue-tsc` exit 0; pushed and PR #9 opened after this STATUS write. Merge terms for PRs #9 and #10 are R39's advance GO (see §4). Branch protection on `main`: **not set** (owner action, carried, R23). `.claude/settings.local.json` (R14): not created — optional, carried.

## §2 Step map (cycle 03 r2 — "CP2.5")
1. `cp/2.5a-rulings` — [done] PR #8 merged `a6281eb`.
2. `cp/2.5b-year-groups` — [built, PR #9 opening, awaiting CI and fresh review; merges under R39 if clean] — R33 in full plus step-1 review Lows L2 and L4.
3. `cp/2.5c-tutor-lifecycle` — [not started]. PR #10 has the owner's advance GO under R39's terms.
4. Rehearsal server — [not started] (R38; owner actions first — halt).
5. Close — [not started] (R37 docs, including step-1 Low L3; halt).

## §3 What changed this run
- Step 1 closed (merged `a6281eb`). Step 2 built in three commits: **table, mapper, seeder, report** (`year_groups` with 21 seeded rows from one source; `LegacyYearGroupMapper`; `php artisan year-groups:report [--remap]`, names-free; `docs/reports/year-groups-migration.md`); **learners, onboarding, search, match requests** on the list (year-group ids validated against the curriculum; onboarding takes min/max year-group ids and derives the tier, a client-sent tier is dropped; unmapped rows send a draft/changes_requested tutor back to the subjects step; search filters by `year_group_id` in SQL; match requests store the chosen label as a snapshot); **Filament CRUD** (admin-only, audited, curriculum fixed after creation, tier limited to the curriculum's tiers, delete hidden while referenced, tier/sort edits re-derive stored tiers with one audit row) plus L2 (00:00 UTC boundary test) and L4 (leading zero-width characters stripped from `displayName()`).
- Tests: 34 net new (462 / 2383 assertions), including a rollback → legacy rows → migrate round trip.

## §4 Decisions
- R39: PR #8 merged now; PRs #9 and #10 merge without a further GO only if: fresh review with no open Medium/High, CI green on the PR head, diff limited to the step's named areas plus tests/migrations/seeders/factories/routes/resources, and none of CLAUDE.md, HOW-WE-WORK.md, PRD.md, `ci.yml` changed. Any Medium+ → PR stays open and the run halts.
- Step 2 semantics: CYCLE-LOG DECISION 13:31 and 17:11.

## §5 Why stopping
Not stopping — R39 gives an advance GO for PR #9 (and #10) under stated terms; the run continues through the review, the merge (if clean) and into step 3. The next halt is step 4's owner-action gate (Forge), or earlier on a Medium+ finding.

## §6 Mismatches
- **Branch protection on `main` not set** (owner action, carried; R23).
- **CP1 box 7 admin-payout clause deferred to CP5** (R25) — "CP1 done" stays qualified.
- **Step 2 deviations (disclosed):** `*_legacy` text columns are kept for unmapped rows (R33 says the columns "become foreign keys"; DATA_MODEL has no legacy columns — for the v1.3 note in step 5); the legacy mapping is trimmed, whitespace-collapsed, case-insensitive exact-label (R33: "exact label"); CBSE labels "Grade 6"–"Grade 12" are my default; `match_requests.year_group` stays a label snapshot; "left null for admin correction" has no admin path — the row's owner corrects it (parent via the learner form, draft/changes_requested tutor via onboarding) and an approved tutor's unmapped row is permissive in search until step 3's re-vetting (R36b); an admin's tier/sort edit can push an approved tutor's rate out of band and nothing re-checks until R36(f) (step 3) and CP3 at booking; `LegacyYearGroupMapper::norm()` is Postgres-specific SQL; `->after('is_minor')` is a no-op on Postgres; no unique on `(curriculum_id, sort)` (a range with two equal sorts is ambiguous; the CRUD does not prevent it — a Low); `YearGroupOptions::all()` ships all 21 rows to four pages; the Filament "delete refused if called anyway" path is unreachable in a test (a hidden action cannot be called) — the visibility guard and the foreign key are tested; `year-groups:report --remap` is state-changing and needs a per-command "yes" on the rehearsal server.
- **Test integrity (step 2):** the `TutorSearchTest` free-text parsing test is removed because R33 deletes that behaviour, and replaced by id-based tests; `TutorOnboardingBankSubjectsRateTest`'s "level tier that does not exist for the chosen curriculum" test is reshaped into "year groups that belong to another curriculum" (the client can no longer send a tier); onboarding subjects POSTs go through `ygRow()`, learner setup through `lnChild()`; `MatchRequestTest`'s snapshot test is stronger; `DatabaseSeederTest` +21.
- **Diff-scope files not pre-declared by name (all inside the step's areas):** `YearGroupTiers.php`, `YearGroupOptions.php`, `RederiveSubjectTiers.php`, `UpdateLearner.php`, `TutorProfileController.php`, `SendMatchSuggestionsMail.php`, `CreateLearner.php`, `TutorProfile.php` (L4), three test files; pre-declared but untouched: `MatchRequestFactory.php`, `HandleInertiaRequests.php`, `tutors/Show.vue`.
- **Step 1 review Lows:** L2 and L4 fixed in this branch; L3 (DATA_MODEL/`ContentBlock` docblock still say homepage-only) is for step 5's docs pass.
- **Remaining follow-up list:** step 3 (R36 lifecycle plus the Fortify mail sender, "Submitted" sort column, transactional last-admin check, duplicate price-band, document-type `code`/`sort` validation, admin Disable confirmation). Staying on the list for CP3's plan: `whereDate` in `bookable()` may bypass the new index; in-memory search pagination; the onboarding trial-price preview rounds `100 − pct` (CP3 must unify with `trialPrice()`); 2a Lows (user-delete cascade decision, overlapping-offer race, learner delete-guards); 2c Lows L2 (last-writer-wins page edits) and L4 (onboarding agreement text shows raw markdown); double queue hop; `footerPages` query per response; finding #9 disputed.
- **Owed downstream (CP3):** freeze the trial price from `trialPrice()`, validate `bookable()` and the permit at booking, decide user-delete behaviour; CP7 maintains `rating_avg`/`rating_count`.

## §7 Next step and owner actions
No owner action is open. The run continues: CI on PR #9, a fresh-subagent review, the merge under R39 if it is clean, then step 3. **Advance notice for step 4:** Claude Code cannot start the rehearsal server until you have created the Forge account, provider link and server per R38, pasted the `.env` in Forge, and filled `CLAUDE.local.md` `## Servers` (include `php artisan year-groups:report` in the read-only list and `year-groups:report --remap` in the state-changing list) — you can do this in parallel now. Carried, optional: Owner action A (R7 branch protection on `main`, exact GitHub UI steps in cycle 01's CYCLE-LOG 18:35 BLOCKER); Owner action B (`.claude/settings.local.json`, ADR-002).

## §8 Cycle 03 board
| Step | State | Branch | PR | Review verdict | Owner GO / merge |
|---|---|---|---|---|---|
| 1 rulings + Lows | **merged** | `cp/2.5a-rulings` | [#8](https://github.com/rizwanoor80/eLearning-Platform/pull/8) | 1 round; 11 verdicts PASS/PASS WITH NOTE, 4 Low, no Med/High | GO (R39); merged `a6281eb` |
| 2 year groups (R33) | built, CI + review pending | `cp/2.5b-year-groups` | #9 (opening) | pending | advance GO under R39 terms |
| 3 tutor lifecycle (R36) | not started | `cp/2.5c-tutor-lifecycle` | — | — | advance GO under R39 terms |
| 4 rehearsal server (R38) | not started (owner actions first) | — | — | — | owner gate |
| 5 close (R37 docs) | not started | — | — | — | docs-only to `main` |

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
