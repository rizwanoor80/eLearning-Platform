# STATUS — cycle 14 r2 — written 2026-10-12 18:12 (step boundary: 14b merged, starting 14c; resume cap 0 of 8)
Tests: **2259 passed / 11533 assertions** on the 14b head `dbbfe2d`, local (35 per-directory runs) and CI (run 38258688958, attempt 2, literal `Tests: 2259 passed (11533 assertions)`); squash-merged as `3aa33dc`, code tree identical to the verified head · `ledger:verify` OK · phpstan 0 · pint clean · vue-tsc exit 0 · `npm run build` exit 0 · RTL check green · smoke on `main`: `/`, `/login`, `/admin/login`, `/tutors` all 200 · Advisor: 9 consultations this cycle (14a ×2, 14b ×7), none since the removal began; consultation 8 is quotable (model `claude-fable-5-1`), 9 is recorded with one recovered line and is not counted as quotable · Review: PR #47 round 4 (the one R192 allows) FAIL, highest High, every High in the display name → R192 removal branch executed, then merged; the removal commit was not re-reviewed (§6) · Context: not measured by the tool this write; 1 compaction since the last `/clear`.

## §1 Git state
`main` and `origin/main` at `3aa33dc` (squash of PR #47) plus this docs commit. Branch `cp/14b-onboarding-clarity` merged, not deleted. No open PR. Rehearsal is still at `2f50206`. An untracked `docs/cloud/` is not mine and is left alone.

## §2 Step map
1. Commit PLAN r1 and close R184 — done.
2. `cp/14a-approval-rule` (R185) — done, merged `3aaac2c`.
3. `cp/14b-onboarding-clarity` (R186, R187, R188) — **done**: PR #47 merged `3aa33dc` under R190/R147 and R192's fallback, CI green on the head (R177 wait), post-merge smoke green.
4. `cp/14c-lesson-polish` (R189) — **next**, starting now.
5. Rehearsal deploy (R111), R91/R169 checks, END — not started.

## §3 What changed
- **14b (R186–R188), merged:** tutor checklist in three groups with Required/Optional tags, condensed on the dashboard, the same groups on the admin review page, a parent "Get started" page and banner, rate step priced against the highest level; an app-raised 409 on a form submit becomes a toast on the same page; the Subjects row wraps; "Today" keeps lessons already taught; lesson-mail brand test.
- **R188(a), the free-text display name, is NOT in 14b (R192).** Built, then removed: no `display_name` column, rule, field or admin line. Parents keep seeing the first word of the account name (`TutorProfile::displayName()`). `TutorNameForParentsTest` (4 tests) pins that on the personal step, the onboarding props, the public profile and the lesson-room join name. **R188(a) is carried to the next cycle as "admin-approved display names"** (see §7 and `## Deferred`).
- One line differs from the old `main`: `IssueJoinToken` now passes the tutor's first word to the video provider instead of the full account name (`docs/reports/14b.md` §0).
- Docs on `main`: DATA_MODEL stays v1.11 (a note says the column was built and removed; there is no v1.12); ADR-024 row, CHECKPOINTS CP1 note and PRD §2.1 say the same; `docs/reports/14b.md` and `docs/copy/onboarding.md` now match the code.

## §4 Decisions and by whom
- Owner (via PLAN r2 R192): one more fix loop, one review, and the removal branch if that review found a High. It did, so the removal ran.
- CC, logged as DECISION: the removal (CYCLE-LOG 18:00 on the 12th, the merge entry), reading R190's "A High halts under R109" as not applying because R192 pre-rules this High; the merge itself, with R190 and R192 quoted verbatim; keeping the first-word room name; rerunning CI once for the flaky test with no code change.
- Advisor (`claude-fable-5-1`): remove by targeted revert, check factories and seeders before dropping the column, keep the masker findings visible.

## §5 Why stopping
Not stopping. The plan runs on to 14c without an owner gate.

## §6 Mismatches
- **Removal commit not re-reviewed.** R192 allows exactly one review and says to merge the rest after removal. `dbbfe2d` is deletions plus 4 tests, verified by the suite, types, build, RTL check and CI. Disclosed, not hidden.
- **Suite count dropped inside the branch** from 2401 (the figure the review ran against) to 2259, because the display-name tests left with the feature (net of the 4 added). It did not shrink against `main` (2205 measured at the 14a head). My own miss on the way: `BrandedMailTest` still built a tutor profile with the removed column; the first per-directory pass printed no result line for `tests/Feature/Mail`, I reran it, fixed the test (it now asserts the mail shows "Amira" and not "Haddad"), and it passed 12/55.
- **Flaky test, not mine:** `tests/Feature/YearGroups/YearGroupMigrationTest.php:27` compares an ordered array to a `groupBy` over a query with no `ORDER BY`, so it depends on Postgres row order. It failed once on the branch's CI (attempt 1: 2258 passed, 1 failed) and passed on the rerun with no code change. Not fixed here; it is under `## Deferred`.
- **Masker findings the reviews turned up (in `MessageMasker`, so they touch messaging and invariant 8; none is in scope now, all are under `## Deferred`):** (a) U+A8E0–A8E9 are combining digits classed as marks, so the masker does not see a digit; (b) the masker's ending tokens (`com|net|…`, two ASCII letters) are ASCII, so look-alike Cyrillic/Greek endings are not read as a domain; (c) a hyphen or apostrophe followed by exactly two ASCII letters is read as a country code, so ordinary names such as "Sa'id", "Mary-Jo", "Noor-ul-Ain", "Zia-ul-Haq", "Abd-al-Aziz" lose characters, and a title or initial plus a domain-ending word ("Dr. Dev Patel", "A. Page") is read as a domain; (d) a short word then ". " and a two-letter tail ("Ali. co") is not read as a domain (arguably a deliberate limit).
- **My mistakes this run:** an edit script wiped `app/Models/TutorProfile.php` through a null `preg_replace` (restored from `HEAD`, suite re-run); a 16-minute full-suite command was killed as a stall (CYCLE-LOG BLOCKER) and replaced with per-directory runs; the `BrandedMailTest` miss above.
- **Local dev database (checked):** no `display_name` column and no orphan migration row. It does have five older migrations pending (the 2026-10-07/08 ones, all from before this cycle); the test database migrates itself. I have not run them on the dev database.
- **Review Low notes on PR #47 carried from earlier rounds** (not fixed): LinkedIn tag wording when an admin marks the CV type required; the "contact" tick also needs the account name (unreachable); `bookable()` does not check rate or documents, and the availability button does nothing for an approved tutor; the "profile is with the review team" lock message also shows for approved, rejected and suspended tutors; a draft or changes-requested tutor no longer sees the onboarding banner or the requested-changes nudge on the dashboard (the sections are still highlighted on the onboarding page); the unused prop `missingForSubmission`; the admin checklist memo is per record object; the 409 handler is global; tutor bio and headline are not masked (pre-existing).
- **Sender name:** rehearsal sends as "TrusTutor (Support)" (`mail.from.name`); the source is the `from_name` setting, which CC may not write.
- **Subjects row:** checked by reading the layout only; no browser tool.

## §7 Next step / Owner actions
No owner action is needed yet. Next, in order: 14c (R189: pre-join screen, lesson titles, hide the Join button once the room is open) on `cp/14c-lesson-polish` with two advisor consultations, review, merge under R190/R147; rehearsal deploy (R111) and the R91/R169 checks; END. The owner actions that will be written at END: the Daily retry test (R181), the sender-name setting ("trusTutor Team"), the SendPulse unsubscribe link, the UI-developer review of the onboarding copy and checklist, the Subjects form at 1280px and 375px, carried items 8 and 9, and the planner decision on "admin-approved display names".

## §8 Programme board
Programme "clarity" (cycle 14 r2).
| # | Item | State |
|---|------|-------|
| 1 | PLAN r1 commit, R184 | done |
| 2 | 14a R185 | merged `3aaac2c` (PR #46) |
| 3 | 14b R186-R188 | merged `3aa33dc` (PR #47); R188(a) carried as "admin-approved display names" |
| 4 | 14c R189 | next |
| 5 | Rehearsal deploy, R91, R169, END | not started |
Resume cap 0 of 8.

## Deferred
- **Admin-approved display names (R188(a))** — carried to the next cycle by R192; needs a PLAN revision for the status field and the admin action.
- **`MessageMasker` gaps (§6 a–d)** — combining digits, non-ASCII domain endings, the country-code heuristic's false positives on common names, short-word ". co" tails. Touches messaging and invariant 8; planner to schedule.
- **`YearGroupMigrationTest` line 27 is order-dependent** — add an `ORDER BY` or compare with `toEqual`; a test-only fix, found flaky on this cycle's CI.
