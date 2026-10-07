# STATUS — cycle 12 r1 — written 2026-10-07 13:30 (HALT: local PostgreSQL and Redis down; 12b built and committed locally, suite unrun)
Tests: last full green 2085/2085, 10492 assertions on the merged 12a tree. 12b suite NOT run: `php artisan test tests/Feature/Mail tests/Feature/Auth/AuthMailSenderTest.php` errored 15 of 15 on `SQLSTATE[08006] connection refused` (127.0.0.1:5432). Database-free gates on the 12b tree: Pint passed, PHPStan 0 errors, `bash scripts/rtl-check.sh` passed · Advisor: 3 in 12a; 12b 0 verified (the 13:00 entry's quote could not be verified, corrected at 13:28; minimum 2 stands) · Review: 12a PR #42, 6 PASS / 0 FAIL; 12b not yet reviewed · Context: not measured by the tool this write · Resume cap 0 of 8.

## §1 Git state
`main` at `ccfb1dd` plus this write's docs-only commit (`[skip ci]`, pushed immediately). Local branch `cp/12b-email-polish` at `aa7834f` (one commit on top of `ccfb1dd`), **not pushed**, no PR. `cp/12a-onboarding` merged and not deleted. `rehearsal` untouched, still at the cycle 11 deploy. No production server exists.

## §2 Step map
1. PLAN commit — done (`8b38277`).
2. `cp/12a-onboarding` — done, merged `3f2dbdc`.
3. `cp/12b-email-polish` (R174 a, b) — **code written and committed locally, blocked on verification.** Built: sender name from the `from_name` setting defaulting to `TrusTutor`; one `MailBrand` helper; rebuilt `emails/layout.blade.php` (maroon bar, wordmark lockup, tagline, bone background, settings footer); 23 mailable templates greet by first name; Laravel's default notification views published and branded so the verification and reset mails no longer show "Hello!" or the black button; 10 new tests in `tests/Feature/Mail/BrandedMailTest.php`. Not yet done: Pest run, composite gate, push, PR, review, second advisor consultation, merge.
4. Deploy to rehearsal under R111, R91 checks with the R169 Horizon lines, END — not started (needs 12b merged).

## §3 What changed
- Nothing new on `main` except CYCLE-LOG and this STATUS since the 12a post-merge record.
- On the local 12b branch (`aa7834f`): see §2 step 3. `config/settings.php` `from_name` default is `TrusTutor`; `UsesSettingsSender` takes the From name from `MailBrand::senderName()`; `site_name` is out of the chain (CYCLE-LOG 13:10 DECISION). Only `ResetPasswordNotification` and `VerifyEmailNotification` use the mail channel; the other seven notifications are database-only.

## §4 Decisions and by whom
- Planner (PLAN cycle 12 r1): R174, R175, R176.
- CC: dropped `site_name` from the From-name chain so a blank `from_name` yields `TrusTutor` as R174(a) says. Left `recurring-slots/paused-admin` ("Hello,") and the admin mails unchanged because they have no single recipient. Both were made without a verified advisor consultation, so the second consultation reviews them (§6).

## §5 Why stopping
Halt for an owner action. Local PostgreSQL (127.0.0.1:5432) and Redis (127.0.0.1:6379) both refuse connections, so the Pest suite cannot run. CLAUDE.md allows only the owner to start Herd services, and HOW-WE-WORK rule 7 forbids pushing a feature branch without a green full suite. Done: 12b code, local commit, three database-free gates. Next: run the 12b tests, composite gate, push, PR, review. Ruled out: starting Herd or a service from this shell; pushing 12b unverified.

## §6 Mismatches
- **"trusTutor Team" is not in the repository.** A case-insensitive search of `app`, `config`, `database`, `resources`, `routes` and `tests` finds only brand-asset names. The old From chain was `from_name` → `site_name` → `config('app.name')`; the string can only come from a settings row the owner typed, the server `.env`, or SendPulse's own From handling. None is readable under the allow-list. After 12b deploys, a null `from_name` row resolves to `TrusTutor`, but a value typed into the row is kept (R29): see Owner action 2.
- **Advisor record corrected (my mistake):** the 13:00 ADVISOR entry quoted a line I cannot reproduce, because the result is stored encrypted. It does not count (CYCLE-LOG 13:28 NOTE). The consultation is re-run before the 12b PR.
- **PRD §2.2 point 2 (~line 35)** still says a tutor needs "a valid permit" to appear in search. Stale under R170; outside R176's scope. Planner to authorise the one-line fix.
- **R176 said "DATA_MODEL v1.9"**; the file's chain ended at v1.7, so v1.8 was written (12a).
- **UI not browser-verified**: PLAN forbids CC a browser. The onboarding screens and the new email layout are checked by tests and rendered-HTML assertions only; the owner walkthrough is the real check.
- **Local tooling:** `composer test` from PowerShell fails at `rtl:check` because `bash` there resolves to a missing WSL launcher; `bash scripts/rtl-check.sh` through Git Bash passes. CI is unaffected.
- **LinkedIn host check:** `parse_url()` and browsers disagree on backslashes; no exploit path today (value is only shown in an `<input>`).
- **Item 4 (Daily webhook proof) is partly corroborated:** rehearsal `lessons` 2, `payments` 2, `video_webhook_events` 0. No attendance webhook recorded. Not marked closed.
- Process slips already logged (12a): a `[skip ci]` commit on the PR branch; one `gh pr checks --watch` and no-op `ScheduleWakeup` calls (12:31 NOTE).
- Carried, non-blocking: AY, AZ; the `config/app.php` allow-list; the untraceable "R91" defining text; the lesson that a Horizon config change needs a daemon restart.

## §7 Next step / Owner actions
**Owner action 1 (Recommended): start PostgreSQL 18 and Redis in the Herd app, then reply `update` when done.** Reason: both ports refuse connections and only you may start Herd services, so 12b cannot be tested, pushed or deployed until they are up. Alternative (not recommended): leave 12b unpushed and have CC stop the cycle here, which delays the rehearsal deploy and leaves the sender-name and branded-email fixes unshipped.

**Owner action 2: check the sender name on rehearsal after the deploy.** If mails still show "trusTutor Team", that text is in rehearsal's Settings (Admin → Settings → From name or Site name), the server `.env`, or SendPulse's From override. Clear or correct whichever holds it; CC cannot read any of them. Reply `update — sender name <what you saw>`.

**Owner action 3: settle PLAN item 4 (Daily webhook proof).** Either (a) check the Daily dashboard's webhook delivery log for the 3 Oct joins and reply `update — item 4 confirmed` or `update — item 4: <what you saw>`; or (b) book and join one fresh lesson on rehearsal after the 12b deploy and reply `update — joined`. Reason: `video_webhook_events` is 0 rows and only a join or Daily's own log can prove the path.

**Owner action 4: finish TEST RABIA's onboarding on rehearsal with only a LinkedIn URL** (no CV, no permit), submit, approve in admin filling subjects and rate, and confirm she appears in search. After the deploy; reply `update — walkthrough done` or what broke.

**Owner action 5: R174(c), the SendPulse "Unsubscribe" link.** Owner-only, no code; say so in `update` once done.

Carried unchanged, at the owner's pace (PLAN items 8, 9, 12): UI-developer reviews (booking screen PR #22, 7g branding, messaging/reviews/Report button walkthrough). Owner-blocking, carried: D-04 with counsel (then CP5); authorising `trustutor-production`. Also carried: Postmark wiring (R120). Roadmap items from 2026-10-01 stay gated on consent text with counsel.

## §8 Programme board
Programme "tutor-onboarding" (cycle 12 r1, R175): 4 steps.
| # | Step | State |
|---|------|-------|
| 1 | PLAN commit | done `8b38277` |
| 2 | 12a onboarding | merged `3f2dbdc` |
| 3 | 12b email polish | built, local `aa7834f`, blocked: DB down (Owner action 1) |
| 4 | Deploy rehearsal, R91 checks, END | pending |
Resume cap 0 of 8.
