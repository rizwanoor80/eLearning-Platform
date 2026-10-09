# STATUS — cycle 14 r1 — written 2026-10-09 16:05 (HALT: 14b PR #47 not merged; review round 3 FAIL, both fix loops used)
Tests: 2289 passed / 11609 assertions on the 14b head `ab7cf8b` · `ledger:verify` OK · phpstan 0 · pint clean · `npm run build` exit 0 on `5ca1cc3` (no front-end file changed since) · CI on `ab7cf8b` completed success (run 37919899908) · Advisor: 7 consultations this cycle (14a ×2, 14b ×5, the last on this halt); three have no quotable line (replies stored encrypted) and do not count toward the minimum, four name `claude-fable-5-1` and quote a line (CYCLE-LOG) · Review: PR #47 round 1 FAIL (fixed loop 1), round 2 FAIL (fixed loop 2), **round 3 FAIL (High, Medium, Low/Medium) — open, cap spent** · Context: not measured by the tool this write · Resume cap 0 of 8 · **Halted, waiting on the owner.**

## §1 Git state
`main` and `origin/main` at `ecac339` plus the docs commit that carries this file and CYCLE-LOG. Branch `cp/14b-onboarding-clarity` at `ab7cf8b` (9 commits ahead of `ecac339`), pushed; PR #47 open and **not to be merged**. Rehearsal is still at `2f50206`. Uncommitted in the working tree and deliberately not on `main` yet: the R191 docs (DATA_MODEL v1.12, DECISIONS ADR-024 amendment, CHECKPOINTS notes, PRD §2.1/§5/§6) — they describe the `display_name` column, which is not on `main` until the PR merges. An untracked `docs/cloud/ENVIRONMENT.md` is not mine and is left alone.

## §2 Step map
1. Commit PLAN r1 and close R184 — done.
2. `cp/14a-approval-rule` (R185) — done, merged `3aaac2c`.
3. `cp/14b-onboarding-clarity` (R186, R187, R188) — code and tests done; **halted** on review round 3 (the display-name validator).
4. `cp/14c-lesson-polish` (R189) — not started (HOW-WE-WORK: the next checkpoint waits for the current PR to merge).
5. Rehearsal deploy (R111), R91/R169 checks, END — not started.

## §3 What changed (14b, on the branch, unmerged)
- **R186:** three-group tutor checklist with live ticks, Required/Optional tags, condensed dashboard checklist, admin review groups, parent Get started page and banner, rate band by highest level; locked items are plain lines; the dashboard checklist hides while the profile is with the review team. Copy: `docs/copy/onboarding.md`.
- **R187:** in-page handling of a 409 on form submit (`bootstrap/app.php`), Subjects row wraps, "No lessons today" keeps taught lessons. Details: `docs/reports/14b.md`.
- **R188:** tutor display name shown to parents, in emails and in the video room; full name admin-only. This is the part under review. Sender name and unsubscribe are settings, not code.
- No round found a fault in anything except the display-name validator.

## §4 Decisions and by whom
- Planner (PLAN r1): R184 to R191, the programme shape, the merge rule.
- CC, logged as DECISION: locked links plain; dashboard checklist hidden for `pending_review`; two display-name fix loops; **halt instead of a third fix or a merge** (CYCLE-LOG 15:55) because the cap is spent and the open findings are Medium and High in code.
- Advisor: smallest fix for dead links; disclose the residual rather than merge silently.

## §5 Why stopping
Round 3 of the fresh-subagent review, on the second fix commit, found: **(High)** Hangul and Khmer filler characters (U+115F, U+1160, U+3164, U+FFA0, U+17B4, U+17B5) pass the name rules and draw as blank, while the contact-detail masker strips them before matching, so `mysite.com` + filler + `Tutor` is accepted and shown to a parent as "mysite.com Tutor"; **(Medium)** more dot-shaped letters (e.g. U+1427, U+01C3, U+02D0) pass, so a website can be shown as a name; **(Low/Medium)** a joiner defeats the combining-mark limit. Two fix loops are used, and the rule is that Medium or above in code returns to the owner. Both earlier rounds were the same defect in a new form (the displayed text differing from the text the masker read), because I was extending a hand-made list over characters the rule admits. Handoff (CYCLE-LOG 16:00): done — everything in §3; next — the owner's answer to Owner action 1; ruled out — a third list-based patch, merging with a known High.

## §6 Mismatches
- **The merge rule cannot be used:** PLAN R190/R147 lets me self-merge with CI green on the head, but not past an unfixed review FAIL.
- **Residual if the display name ships as it is on the branch (plain words):** a name can still be built from look-alike letters and invisible filler characters so that a parent sees a website or email address that the check did not see. Reviewers proved concrete strings for it. The cost is a tutor putting contact details in front of a parent before a first paid lesson, which invariant 8 says must stay masked.
- **Review Low notes on PR #47, not fixed:** LinkedIn tag wording when an admin marks the CV type required; the "contact" tick also needs the account name (unreachable); `bookable()` does not check rate or documents, and the availability button does nothing for an approved tutor; the "profile is with the review team" lock message also shows for approved, rejected and suspended tutors; **a draft or changes-requested tutor no longer sees the onboarding banner or the requested-changes nudge on the dashboard (the sections are still highlighted on the onboarding page)**; the unused prop `missingForSubmission`; the admin checklist memo is per record object; the 409 handler is global; tutor bio and headline are not masked (pre-existing); plus false refusals of some legitimate names (names joined by a middle dot, pointed Hebrew with a cantillation mark, "Bo.Li" read as a domain).
- **Plan wording, judgment call:** the dashboard checklist is hidden for `pending_review`, rejected and suspended; the plan does not say so.
- **"No lessons today":** cause found and tested, but the owner's exact lesson is not reproduced.
- **Sender name:** rehearsal sends as "TrusTutor (Support)" (`mail.from.name`); "trusTutor Team" is not in the code; the source is the `from_name` setting, which CC may not write.
- **Subjects row:** checked by reading the layout only; no browser tool.
- **14a items standing:** the permit and required-document bar in `ApproveTutor`; four 14a Low notes (see the previous STATUS in git history).
- **Advisor minimum:** three of six consultations cannot be quoted (the replies are stored encrypted), so they do not count toward the minimum under rule 11.

## §7 Next step / Owner actions
Owner action 1: Decide how the 14b display name is finished. Reply with the number.
1. **(Recommended)** Authorise a third fix loop for the display name only, with the validator rebuilt on rules rather than a list: refuse every code point `MessageMasker` strips (read from the masker, not copied), admit only named scripts (Latin, Arabic, Hebrew, Devanagari, Han, Kana, Hangul syllables only — not the Jamo blocks the fillers live in — Thai and similar) instead of all letters and marks, and count combining marks through joiners; then a fourth fresh review and the merge under R190/R147. Reason: it closes the whole class that two rounds found piecemeal, and it keeps all of R186 to R188.
2. Take the free-text display name out of 14b (parents keep seeing the first name; R188(a) moves to a later cycle) and merge the rest. Reason it is not first: it drops a feature you asked for and costs rework.
3. Keep the feature but have an admin approve each display name before parents see it (the safe closure; needs a PLAN revision for the status and the admin action).
Then `/clear` this session and reply `update — <1, 2 or 3>`.

Owner actions that fall due at END, listed now so nothing is lost (do none of them yet):
- Owner action 2: in Admin → Settings → Mail set **From name** to "trusTutor Team" if that is what you want; reply `update` when done.
- Owner action 3: check the unsubscribe link in your mail provider's settings; reply `update` when done.
- Owner action 4: have the UI developer read `docs/copy/onboarding.md` and the onboarding checklist on rehearsal; reply `update` when done.
- Owner action 5: open the tutor Subjects form at 1280px and 375px and compare with `docs/reports/14b.md` §1; reply `update` when done.
- Owner action 6: the Daily retry test (R181) and carried items 8 and 9; reply `update` when done.

## §8 Programme board
Programme "clarity" (cycle 14 r1).
| # | Item | State |
|---|------|-------|
| 1 | PLAN r1 commit, R184 | done |
| 2 | 14a R185 | merged `3aaac2c` (PR #46) |
| 3 | 14b R186-R188 | **halted**: PR #47 head `ab7cf8b`, review round 3 FAIL, awaiting Owner action 1 |
| 4 | 14c R189 | not started |
| 5 | Rehearsal deploy, R91, R169, END | not started |
Resume cap 0 of 8.
