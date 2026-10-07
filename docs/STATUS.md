# STATUS — cycle 12 r2 — written 2026-10-07 15:28 (END: all four steps done, rehearsal deployed at `421cb3f`; halting for the owner walkthroughs)
Tests: 2096/2096 passed, 10529 assertions (full suite on the 12b head `437977d`); `ledger:verify` OK locally and on rehearsal; Pint, PHPStan 0 errors, vue-tsc, RTL check (Git Bash) and `npm run build` green; CI green on `main` at `3a175f9` · Advisor: 5 verified consultations (3 in 12a, 2 in 12b), 1 retracted entry (CYCLE-LOG 13:28) · Review: PR #42 6 PASS / 0 FAIL; PR #43 PASS WITH NOTE, 2 Low fixed, fix loop 1 of 2 · Context: not measured by the tool this write · Resume cap 0 of 8 · Halted at END.

## §1 Git state
`main` and `origin/main` at the END docs commit (after `421cb3f`), working tree clean. `rehearsal` at `421cb3f` (fast-forward from `2cd8cb1`, deployed by Forge push-to-deploy). Merged: PR #42 `3f2dbdc`, PR #43 `3a175f9`. `cp/12a-onboarding` and `cp/12b-email-polish` not deleted. No open PR. No production server exists.

## §2 Step map
1. PLAN commit — done (`8b38277`; r2 revision `3370497`).
2. `cp/12a-onboarding` (R170, R171, R173) — done, merged `3f2dbdc`.
3. `cp/12b-email-polish` (R174 a, b) — done, merged `3a175f9`. Done-means: a rendered-mail test asserts the logo lockup and the setting-driven sender name (`BrandedMailTest`); a sweep test asserts every `emails` view extends the layout, every mailable uses the settings sender, and every mail-channel notification calls `MailBrand::brandNotification`.
4. Deploy rehearsal under R111, R91 checks with the R169 Horizon lines — done (CYCLE-LOG 15:15 and 15:20): environment `rehearsal`, debug OFF, Horizon running with `supervisor-1` (new master PID), `jobs` 0 and `failed_jobs` 0, 0 pending migrations (the 12a migration ran), ledger OK, `app.url` is `https://rehearsal.trustutor.com`, the wordmark image returns 200 `image/png`, home 200, no dated lines in the last 200 log lines.

## §3 What changed
- 12a: permit optional for every tutor (`bookable()` = approved AND permit null or unexpired); submission minimum is name, country, CV-or-LinkedIn, agreement; subjects, rate and availability checked at approval; admin edit-on-behalf actions audited; onboarding nav entry and dashboard banner. Docs per R176 (DATA_MODEL v1.8, ADR-024, CP1 note, PRD §2.1/§9).
- 12b: sender name from the `from_name` setting, default `TrusTutor` (`site_name` out of the chain); one branded layout (maroon bar, wordmark lockup, tagline, first-name greeting, settings footer and support line) for all 29 mailables; Laravel's default notification views published and rebranded for the password-reset and email-verification mails; notification greeting is Markdown-escaped; 11 new tests; `ManageSettingsTest` updated to R174(a).
- Plan r2 (R177, R178) committed as `3370497`.

## §4 Decisions and by whom
- Owner (chat, 2026-10-07): `update — Saturday lessons joined`; `update` ×3.
- Planner (PLAN): R170–R176; r2 added R177 (waiting for CI is not a halt) and R178 (CC may start Herd's PostgreSQL and Redis).
- CC: `site_name` dropped from the From chain (advisor agreed); one existing test's expectations changed under R174(a); both Low review findings fixed under R175's fix loop; deployed `origin/main` (`421cb3f`, docs-only commits above the green `3a175f9`) to rehearsal.

## §5 Why stopping
The plan's END: halt yes. Everything the plan authorised is done. What remains needs the owner's eyes and accounts: a real inbox, the admin UI, SendPulse, Daily. Nothing more is authorised, so this is an END, not a blocker.

## §6 Mismatches
- **"trusTutor Team" is not in the repo, nor in the server environment.** Rehearsal's `config:show mail.from.name` is `TrusTutor (Support)`. It can only be a `settings` row typed in Admin (`from_name` or `site_name`) or SendPulse's From override. After this deploy a null row gives `TrusTutor`; a typed value is kept (R29). Owner action 1.
- **CLAUDE.md and HOW-WE-WORK are now behind the plan.** R177 (CI waiting) and R178 (Herd services) supersede lines in CLAUDE.md; the planner is to carry them into HOW-WE-WORK v1.3. CLAUDE.md is not mine to edit. Separately, CLAUDE.md "Owner loop" rule 6 returns code-level review findings to the owner while R175 allows a fix loop; I followed R175 (CYCLE-LOG 14:30). Planner to reconcile.
- **Review follow-ups, deferred, not owed:** (a) the plain-text alternative of the two notifications is not Markdown-rendered, so a hostile name would show backslashes there; (b) `resources/views/vendor/mail/html/themes/default.css:68` (h3) has `text-align: left`, which the RTL grep does not match (v1 is ltr only); (c) the sweep tests are substring checks and one mailable is rendered; per-mail tests cover content; (d) Mailables have no plain-text alternative, as before.
- **PRD §2.2 point 2 (~line 35)** still says a tutor needs "a valid permit" to appear in search; stale under R170, outside R176's scope. Planner to authorise the one-line fix.
- **R176 said "DATA_MODEL v1.9"**; the chain ended at v1.7, so v1.8 was written.
- **Not verified by CC, by rule:** the new email layout in a real inbox and the onboarding screens in a browser. PLAN forbids CC a browser and real email. Rendered-HTML tests only.
- **Local tooling:** `composer test` from PowerShell fails at `rtl:check` (no WSL `bash`); the steps were run individually. CI is unaffected.
- **LinkedIn host check:** `parse_url()` and browsers disagree on backslashes; no exploit path today.
- **Item 4 (Daily webhook proof)** is still open: rehearsal `lessons` 2, `video_webhook_events` was 0 at the last count. Not marked closed.
- My process record this cycle: the 12a `[skip ci]` branch commit and `gh --watch` use; the unverifiable 13:00 advisor quote (retracted); the two sweep tests passing a message to `toContain` (fixed). All logged.
- Carried, non-blocking: AY, AZ; the `config/app.php` allow-list; the untraceable "R91" defining text; the lesson that a Horizon config change needs a daemon restart.

## §7 Next step / Owner actions
**Owner action 1 (Recommended): look at one real email from rehearsal and the sender name.** Trigger a password reset for a test account on `https://rehearsal.trustutor.com` and open it in your inbox. Check the logo lockup, "Hi <first name>,", a maroon button and the footer. Then check the sender name. If it still reads "trusTutor Team", open Admin → Settings → Mail → From name, then Site → Site name, and clear or correct whichever holds it; if neither does, it is SendPulse's From override. Reason: the server environment is ruled out, and I may not send real email or read your settings rows. Reply `update — email looks right` or `update — sender name <what you saw>`.

**Owner action 2: finish TEST RABIA's onboarding on rehearsal with only a LinkedIn URL** (no CV, no permit), submit, then approve in admin, filling subjects and rate through the new edit-on-behalf actions, and confirm she appears in search. Reply `update — walkthrough done` or what broke.

**Owner action 3: settle PLAN item 4 (Daily webhook proof).** Either check the Daily dashboard's webhook delivery log for the 3 Oct joins and reply `update — item 4 confirmed` or `update — item 4: <what you saw>`; or book and join one fresh lesson on rehearsal and reply `update — joined`, and I will check `video_webhook_events`.

**Owner action 4: R174(c), the SendPulse "Unsubscribe" link.** Owner-only, no code. Say so in `update` once done.

Carried unchanged, at the owner's pace (PLAN items 8, 9, 12): UI-developer reviews (booking screen PR #22, 7g branding, messaging/reviews/Report button walkthrough). Owner-blocking, carried: D-04 with counsel (then CP5); authorising `trustutor-production`. Also carried: Postmark wiring (R120). Roadmap items from 2026-10-01 stay gated on consent text with counsel.

## §8 Programme board
Programme "tutor-onboarding" (cycle 12 r2, R175): 4 steps.
| # | Step | State |
|---|------|-------|
| 1 | PLAN commit | done `8b38277` (r2 `3370497`) |
| 2 | 12a onboarding | merged `3f2dbdc` |
| 3 | 12b email polish | merged `3a175f9` |
| 4 | Deploy rehearsal, R91 checks, END | done, rehearsal at `421cb3f`; END logged 15:25 |
Resume cap 0 of 8.
