# STATUS — cycle 03 r2 — written 2026-09-20 13:40
Tests: 428 tests / 2105 assertions (merged main, `a6281eb`) · Advisor: cycle 03 consulted 3 times so far (step 1 design 09:30, pre-PR 11:30; step 2 design 13:30) · Review: step 1 merged after one fresh review — no Medium/High (4 Low)

## §1 Git state
`main` = `a6281eb` (PR #8 merged with a merge commit under R39's GO) plus this record. Step 1 is closed. Branch `cp/2.5b-year-groups` is being cut for step 2. Branch protection on `main`: **not set** (owner action, carried, R23). `.claude/settings.local.json` (R14): not created — optional, carried.

## §2 Step map (cycle 03 r2 — "CP2.5")
1. `cp/2.5a-rulings` — [done] PR #8 merged `a6281eb` (R32, R34, R35 and the eleven Lows).
2. `cp/2.5b-year-groups` — [in progress] — design ADVISOR logged (R30 list, 18 items); build next. Also fixes step-1 review Lows L2 (00:00 UTC boundary test) and L4 (leading zero-width characters) per R39.
3. `cp/2.5c-tutor-lifecycle` — [not started]. PR #10 has the owner's advance GO under R39's terms.
4. Rehearsal server — [not started] (R38; owner actions first — halt).
5. Close — [not started] (R37 docs, including L3; halt).

## §3 What changed this run
- Owner replied `UPDATE`; PLAN cycle 03 r2 committed (`PLAN.md cycle 03 r2`) before other work; R39 quoted in CYCLE-LOG ADVICE 13:01.
- PR #8 merged under R39 (`a6281eb`); post-merge on `main`: `composer.bat test` 428 tests / 2105 assertions, Pint + PHPStan (0 errors) + RTL passed, `npm run build` ✓, curl `/` `/terms` `/tutors` `/login` `/register` `/admin/login` → 200, `/tutors?min_price=abc` `/dashboard` `/match-requests` `/admin` → 302.
- Step 2 design consulted (CYCLE-LOG 13:30, 13:31).

## §4 Decisions
- R39: PR #8 merged now; PRs #9 and #10 merge without a further GO only if: fresh review with no open Medium/High, CI green on the PR head, diff limited to the step's named areas plus tests/migrations/seeders/factories/routes/resources, and none of CLAUDE.md, HOW-WE-WORK.md, PRD.md, `ci.yml` changed. Any Medium+ → PR stays open and the run halts.
- Step 2 semantics: CYCLE-LOG DECISION 13:31.

## §5 Why stopping
Not stopping — R39 gives advance GOs for PRs #9 and #10 under stated terms, so the run continues through steps 2 and 3. The next halt is step 4's owner-action gate (Forge), or earlier on a Medium+ finding.

## §6 Mismatches
- **Branch protection on `main` not set** (owner action, carried; R23).
- **CP1 box 7 admin-payout clause deferred to CP5** (R25) — "CP1 done" stays qualified.
- **Step 2 planned deviations (disclosed in advance):** `*_legacy` text columns kept for unmapped rows (R33 says the columns "become foreign keys"; DATA_MODEL has no legacy columns — justified: the report and any correction need the original text; for the v1.3 note); the legacy mapping is trimmed and case-insensitive exact-label (R33: "exact label"); CBSE labels "Grade 6"–"Grade 12" are my default; "left null for admin correction" has no admin path — the row's owner corrects it (parent via the learner form, draft/changes_requested tutor via onboarding) and approved tutors with unmapped rows stay permissive in search until R36(b) (step 3); an admin tier/sort edit can push an approved tutor's rate out of band and nothing re-checks until R36(f) (step 3) and CP3 at booking.
- **Step 1 review Lows:** L2 and L4 are fixed in step 2's branch (R39); L3 (DATA_MODEL/`ContentBlock` docblock still say homepage-only) is for step 5's docs pass; L1 (assertion count) was corrected in the PR body.
- **Remaining follow-up list:** step 3 (R36 lifecycle plus the Fortify mail sender, "Submitted" sort column, transactional last-admin check, duplicate price-band, document-type `code`/`sort` validation, admin Disable confirmation). Staying on the list for CP3's plan: `whereDate` in `bookable()` may bypass the new index; in-memory search pagination; the onboarding trial-price preview rounds `100 − pct` (CP3 must unify with `trialPrice()`); 2a Lows (user-delete cascade decision, overlapping-offer race, learner delete-guards); 2c Lows L2 (last-writer-wins page edits) and L4 (onboarding agreement text shows raw markdown); double queue hop; `footerPages` query per response; finding #9 disputed.
- **Owed downstream (CP3):** freeze the trial price from `trialPrice()`, validate `bookable()` and the permit at booking, decide user-delete behaviour; CP7 maintains `rating_avg`/`rating_count`.

## §7 Next step and owner actions
No owner action is open. The run continues with step 2's build. **Advance notice for step 4:** Claude Code cannot start the rehearsal server until you have created the Forge account, provider link and server per R38, pasted the `.env` in Forge, and filled `CLAUDE.local.md` `## Servers` — you can do this in parallel now. Carried, optional: Owner action A (R7 branch protection on `main`, exact GitHub UI steps in cycle 01's CYCLE-LOG 18:35 BLOCKER); Owner action B (`.claude/settings.local.json`, ADR-002).

## §8 Cycle 03 board
| Step | State | Branch | PR | Review verdict | Owner GO / merge |
|---|---|---|---|---|---|
| 1 rulings + Lows | **merged** | `cp/2.5a-rulings` | [#8](https://github.com/rizwanoor80/eLearning-Platform/pull/8) | 1 round; 11 verdicts PASS/PASS WITH NOTE, 4 Low, no Med/High | GO (R39); merged `a6281eb` |
| 2 year groups (R33) | in progress (design done) | `cp/2.5b-year-groups` | — | — | advance GO under R39 terms |
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
