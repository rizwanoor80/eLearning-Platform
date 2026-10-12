# STATUS — cycle 14 r2 — written 2026-10-12 18:10 (step boundary: review round 4 logged, display-name removal about to start; resume cap 0 of 8)
Tests: 2401 passed / 12217 assertions on the 14b head `9a99db5` (per-directory runs, see CYCLE-LOG BLOCKER) · `ledger:verify` OK · phpstan 0 · pint clean · `npm run build` exit 0 · CI on `9a99db5` success (run 38147960823) — all measured **before** the removal; the removal commit is not yet written · Advisor: 9 consultations this cycle (14a ×2, 14b ×7); consultation 8 is quotable (live quote, model `claude-fable-5-1`), 9 is recorded with one recovered line and is not counted as quotable · Review: PR #47 round 4 (the one R192 allows) **FAIL, highest High** → R192 removal branch in force · Context: not measured by the tool this write; 1 compaction since the last `/clear`.

## §1 Git state
`main` at `e2b24ca` (PLAN cycle 14 r2) plus the docs commit that carries this file, CYCLE-LOG and the corrected R191 docs. Branch `cp/14b-onboarding-clarity` at `9a99db5`, pushed, PR #47 open; the removal commit is not yet made. Rehearsal is still at `2f50206`. An untracked `docs/cloud/` is not mine and is left alone.

## §2 Step map
1. Commit PLAN r1 and close R184 — done.
2. `cp/14a-approval-rule` (R185) — done, merged `3aaac2c`.
3. `cp/14b-onboarding-clarity` (R186, R187, R188) — R192 loop done and reviewed; **removing the free-text display name now**, then merge under R190/R147 once CI is green on the head (R177 wait).
4. `cp/14c-lesson-polish` (R189) — not started (waits for the 14b merge and its post-merge record).
5. Rehearsal deploy (R111), R91/R169 checks, END — not started.

## §3 What changed
- R192 loop (on the branch, `9a99db5`): a rule-based `DisplayNameRule` (the masker's own invisible-character constant, named scripts only, marks and joiners counted, masker run on the result) with 99 rule tests and the display-name feature tests. It is being **removed** — see §5.
- Everything else in 14b is unchanged from the last STATUS: R186 checklist, tags, parent Get started page; R187 409 toast, Subjects wrap, "Today" keeps taught lessons.
- On `main` (docs only): CYCLE-LOG entries for this run; the four R191 docs corrected so they no longer describe a `display_name` column (DATA_MODEL stays v1.11 with a note that the column was built and removed — there is no v1.12; ADR-024 row, CHECKPOINTS CP1 note and PRD §2.1 say the same).

## §4 Decisions and by whom
- Owner (via PLAN r2 R192): one more fix loop, one review, and the removal branch if that review finds a High.
- CC, logged as DECISION: rule design (CYCLE-LOG 17:42); the removal (CYCLE-LOG 18:00). The removal is R192 applied, not my choice.
- Advisor (`claude-fable-5-1`): extra probes before the commit; on the High branch, remove by targeted revert, check factories/seeders before dropping the column, keep the masker findings visible.

## §5 Why stopping
Not stopped; this is a step-boundary write. Why the field goes: the fresh review of `9a99db5` found three High that I reproduced myself (a zero-width joiner splits a long word past the "full stop after a long word" rule; combining Devanagari digits U+A8E0–A8E9 get in as marks; Cyrillic or Greek look-alikes spell a domain ending such as "com" that the masker's ASCII-only endings cannot read) and two Medium. R192 says another High means "do not patch — remove the free-text field".

## §6 Mismatches
- **Masker findings the reviews turned up (these are in `MessageMasker`, not only in the validator, so they also touch messaging and invariant 8; none is in scope now, all are under `## Deferred`):** (a) U+A8E0–A8E9 are combining digits classed as marks, so the masker does not see a digit; (b) the masker's ending tokens (`com|net|…`, two ASCII letters) are ASCII, so look-alike Cyrillic/Greek endings are not read as a domain; (c) a hyphen or apostrophe followed by exactly two ASCII letters is read as a country code, so ordinary names such as "Sa'id", "Mary-Jo", "Noor-ul-Ain", "Zia-ul-Haq", "Abd-al-Aziz" lose characters, and a title or initial plus a domain-ending word ("Dr. Dev Patel", "A. Page") is read as a domain; (d) a short word then ". " and a two-letter tail ("Ali. co") is not read as a domain (arguably a deliberate limit).
- **Suite count:** removing the display-name rule and its two test files drops this branch's count from 2401 (the number the review ran against) to a lower figure, recorded after the removal. "Suite never shrinks" is relative to `main` (2203 was measured on the 14a branch before its fix loop; `main` is at least that); the cause of the within-branch drop is R192.
- **My mistakes this run:** an edit script wiped `app/Models/TutorProfile.php` through a null `preg_replace`; I restored it from `HEAD` and re-ran the suite. A 16-minute full-suite command was killed as a stall (CYCLE-LOG BLOCKER) and replaced with per-directory runs.
- **Possible local orphan:** the local dev database may still hold the unmerged `add_display_name_to_tutor_profiles` migration row and column from earlier runs. It is local only; I will not run `migrate:fresh` for it unless the owner asks.
- **Review Low notes on PR #47 carried from earlier rounds** (not fixed): LinkedIn tag wording when an admin marks the CV type required; the "contact" tick also needs the account name (unreachable); `bookable()` does not check rate or documents, and the availability button does nothing for an approved tutor; the "profile is with the review team" lock message also shows for approved, rejected and suspended tutors; a draft or changes-requested tutor no longer sees the onboarding banner or the requested-changes nudge on the dashboard (the sections are still highlighted on the onboarding page); the unused prop `missingForSubmission`; the admin checklist memo is per record object; the 409 handler is global; tutor bio and headline are not masked (pre-existing).
- **Review round 4 items 6 to 9** (one-mark trigger, modifier letters, `accepts()` length, keyword sweep) concern code that the removal deletes; they are recorded in CYCLE-LOG and need no fix.
- **Sender name:** rehearsal sends as "TrusTutor (Support)" (`mail.from.name`); the source is the `from_name` setting, which CC may not write.
- **Subjects row:** checked by reading the layout only; no browser tool.

## §7 Next step / Owner actions
No owner action is needed yet. Next, in order: remove the display name on the branch and verify; push; wait for CI on the new head; merge PR #47 under R190/R147; post-merge record; 14c (R189); rehearsal deploy; END with the owner actions (Daily retry test, sender name, unsubscribe link, UI-developer reviews, items 8 and 9, and the planner decision on "admin-approved display names").

## §8 Programme board
Programme "clarity" (cycle 14 r2).
| # | Item | State |
|---|------|-------|
| 1 | PLAN r1 commit, R184 | done |
| 2 | 14a R185 | merged `3aaac2c` (PR #46) |
| 3 | 14b R186-R188 | R192 loop reviewed; display name being removed; PR #47 open |
| 4 | 14c R189 | not started |
| 5 | Rehearsal deploy, R91, R169, END | not started |
Resume cap 0 of 8.

## Deferred
- **Admin-approved display names (R188(a))** — carried to the next cycle by R192; needs a PLAN revision for the status field and the admin action.
- **`MessageMasker` gaps (§6 a–d)** — combining digits, non-ASCII domain endings, the country-code heuristic's false positives on common names, short-word ". co" tails. Touches messaging and invariant 8; planner to schedule.
