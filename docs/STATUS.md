# STATUS — cycle 12 r1 — written 2026-10-07 12:40 (step boundary: 12a merged, 12b next)
Tests: 2085/2085 passed, 10492 assertions (full suite on the merged tree; `ledger:verify` OK; Pint, PHPStan 0 errors, vue-tsc, RTL check green) · Advisor: consulted 3 times in 12a, 0 so far in 12b (minimum 2 stands) · Review: PR #42 fresh-subagent review, 6 PASS / 0 FAIL, fix loop 0 of 2 · Context: not measured by the tool this write · Resume cap 0 of 8 · Running on, no halt yet.

## §1 Git state
`main` at `3f2dbdc` ("Merge pull request #42 from rizwanoor80/cp/12a-onboarding") plus this write's docs-only commit (`[skip ci]`, pushed immediately). PR #42 merged with a merge commit, CI green on the head `e6b923a` (run 37591707583). `cp/12a-onboarding` not deleted. `rehearsal` untouched, still at the cycle 11 deploy. No open PR. No production server exists.

## §2 Step map
1. PLAN commit — done (`8b38277`).
2. `cp/12a-onboarding` (R170, R171, R173) — **done**: merged under R175/R147. Done-means checked: the demo-tutor seeder still runs (local smoke, no error); search and booking tests cover approved-with-permit, approved-without-permit, approved-with-expired-permit and draft; `TEST RABIA` left as is.
3. `cp/12b-email-polish` (R174 a, b) — **next**, not started.
4. Deploy to rehearsal under R111, R91 checks with the R169 Horizon lines, END — not started.

## §3 What changed
- Permit optional for every tutor: `bookable()` reads `status = approved AND (permit_expires_at IS NULL OR permit_expires_at > today)`; `permitAllowsBooking()` is its PHP twin (R170, ADR-024).
- Submission minimum is name, country, CV-or-LinkedIn, agreement; subjects, rate and availability are checked at approval by `TutorApprovalReadiness`; `LinkedinUrlRule` checks the parsed host; admin `editSubjects`/`editRate` on-behalf actions are audited (R171). Nav entry and dashboard "what's missing" banner (R173).
- Docs per R176: DATA_MODEL v1.8, DECISIONS ADR-024, CHECKPOINTS CP1 note, PRD §2.1 and §9.
- After the first CI run failed, Pint and PHPStan findings were fixed (stray blank line; `findOrFail` replaced with `where('id', …)->firstOrFail()`; dead `timezone === null` checks removed because the column is non-nullable with a default). Test count unchanged.
- Owner reply `update — Saturday lessons joined` logged as ADVICE; rehearsal checked read-only (CYCLE-LOG 12:30 VERIFICATION): `lessons` 2, `payments` 2, `video_webhook_events` 0.

## §4 Decisions and by whom
- Owner (chat, 2026-10-07): `"update — Saturday lessons joined"`.
- Planner (PLAN cycle 12 r1): R170, R171, R173, R174, R175, R176.
- CC: wrote DATA_MODEL as v1.8, not R176's "v1.9" (CYCLE-LOG 03:10 DEVIATION). Merged PR #42 itself under R175/R147 after the review and a green head. Left the migration filename `2026_10_06_100000_…` as is.

## §5 Why stopping
Not stopping. Step 3 follows immediately. The cycle ends at END after step 4.

## §6 Mismatches
- **PRD §2.2 point 2 (~line 35)** still says a tutor needs "a valid permit" to appear in search. Stale under R170; outside R176's §2.1/§9 scope, so not edited. Planner to authorise the one-line fix.
- **R176 said "DATA_MODEL v1.9"**; the file's chain ended at v1.7, so v1.8 was written.
- **UI not browser-verified**: PLAN forbids CC a browser, so the onboarding checklist and banner were exercised through feature tests only. The owner walkthrough in §7 is the real check.
- **Process gap, mine:** before the PR I ran gates piecemeal and never the composite `composer test` that CI runs, so Pint and PHPStan failures reached CI first (CYCLE-LOG 04:05 BLOCKER). Fixed; 12b will run the composite chain before its PR.
- **Process slips, mine:** a commit on the PR branch carried `[skip ci]` against rule 6, which left no CI run on the head until an ordinary empty commit was pushed (04:25 DEVIATION, 12:25 VERIFICATION). I also used `gh pr checks --watch` once and made no-op `ScheduleWakeup` calls (12:31 NOTE).
- **Local tooling:** `composer test` run from PowerShell fails at `rtl:check` because `bash` there resolves to a missing WSL launcher; `bash scripts/rtl-check.sh` through Git Bash passes. CI is unaffected.
- **LinkedIn host check:** `parse_url()` and browsers disagree on backslashes (`https://evil.com\@linkedin.com/`). No exploit path today because the value is only shown in an `<input>`; revisit if an admin-facing clickable LinkedIn link is ever added.
- **Item 4 (Daily webhook proof) is partly corroborated:** `lessons` rose from 1 to 2 and `payments` is 2, so a second lesson was booked and paid. `video_webhook_events` is still 0 and the log tail has nothing, so no Daily attendance webhook has been recorded. The 3 Oct log is rotated into a gzip CC may not read. Not marked closed.
- Carried, non-blocking: AY, AZ; the `config/app.php` allow-list; the untraceable "R91" defining text; the lesson that a Horizon config change needs a daemon restart.

## §7 Next step / Owner actions
CC continues to step 3 (`cp/12b-email-polish`) in this run; nothing is needed from the owner to proceed. Owner actions that exist now, to be repeated in the END write:

**Owner action 1 (Recommended): settle PLAN item 4 (Daily webhook proof).** Either (a) open Admin → Video providers on rehearsal and look at the Daily dashboard's webhook delivery log for the 3 Oct joins, then reply `update — item 4 confirmed` or `update — item 4: <what you saw>`; or (b) book and join one fresh lesson on rehearsal after the 12b deploy and reply `update — joined`, and CC will check `video_webhook_events`. Reason: `video_webhook_events` is 0 rows, and booking alone never writes that table (`VideoWebhookController.php:74-94`), so only a join, or Daily's own delivery log, can prove the path.

**Owner action 2: finish TEST RABIA's onboarding on rehearsal with only a LinkedIn URL** (no CV, no permit), submit, then approve in admin, filling subjects and rate through the new edit-on-behalf actions, and confirm she appears in search. Walkthrough for after the deploy; reply `update — walkthrough done` or what broke.

**Owner action 3: R174(c), SendPulse unsubscribe setting.** Owner-only, no code; when you have done it, say so in `update`.

Carried unchanged, at the owner's pace (PLAN items 8, 9, 12): UI-developer reviews (booking screen PR #22, 7g branding, messaging/reviews/Report button walkthrough). Owner-blocking, carried: D-04 with counsel (then CP5); authorising `trustutor-production`. Also carried: Postmark wiring (R120). Roadmap items from 2026-10-01 (Daily recording, transcription, AI-drafted reports, parent digest, transcript library) stay gated on consent text with counsel.

## §8 Programme board
Programme "tutor-onboarding" (cycle 12 r1, R175): 4 steps.
| # | Step | State |
|---|------|-------|
| 1 | PLAN commit | done `8b38277` |
| 2 | 12a onboarding | merged `3f2dbdc` |
| 3 | 12b email polish | next |
| 4 | Deploy rehearsal, R91 checks, END | pending |
Resume cap 0 of 8.
