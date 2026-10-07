# STATUS — cycle 12 r1 — written 2026-10-07 15:00 (step boundary: 12b merged `3a175f9`; waiting for main CI on the merge commit before the R111 deploy)
Tests: 2096/2096 passed, 10529 assertions (full suite on the 12b head `437977d`); `ledger:verify` OK; Pint, PHPStan 0 errors, vue-tsc, RTL check (Git Bash) and `npm run build` green · Advisor: 3 in 12a; 2 verified in 12b (the 13:00 entry is retracted), total 5 · Review: PR #43 fresh subagent, PASS WITH NOTE, 0 High / 0 Medium, 2 Low fixed, fix loop 1 of 2 · Context: not measured by the tool this write · Resume cap 0 of 8 · Short wait, see §5.

## §1 Git state
`main` at the 12b merge commit `3a175f9` (PR #43, merge commit, CI green on head `437977d`) plus docs-only commits (`e5a7c20` post-merge record, and this write; both `[skip ci]`). `cp/12b-email-polish` not deleted. `rehearsal` untouched, still at the cycle 11 deploy. No production server exists.

## §2 Step map
1. PLAN commit — done (`8b38277`).
2. `cp/12a-onboarding` — done, merged `3f2dbdc`.
3. `cp/12b-email-polish` (R174 a, b) — **Merged `3a175f9`** under R175/R147 (review PASS WITH NOTE, CI green on `437977d`). Post-merge smoke green: Mail tests 11/11, local migrate (one pending 12a migration applied), `DemoTutorSeeder` exit 0 twice.
4. Deploy to rehearsal under R111, R91 checks with the R169 Horizon lines, END — not started (needs 12b merged).

## §3 What changed (12b, on the PR branch)
- Sender name = `from_name` setting, default `TrusTutor`; `site_name` is out of the chain.
- One branded layout for all 29 mailables; the two mail-channel notifications (reset, verify) use rebranded published `vendor/mail` views and theme; first-name greetings; footer and support line from settings, null-guarded.
- Tests: `BrandedMailTest` (11 tests); `ManageSettingsTest` updated to R174(a) (CYCLE-LOG 14:05).
- Review fix 1: greeting name is Markdown-escaped in notifications; docblock repaired; red/green proof logged.

## §4 Decisions and by whom
- Planner (PLAN cycle 12 r1): R174, R175, R176.
- Owner (chat): `update` (after the DB halt).
- CC: dropped `site_name` from the From chain (advisor 1 agreed); changed one existing test's expectations under R174(a); fixed both Low review findings under R175's fix loop although CLAUDE.md rule 6 reads more strictly (§6).

## §5 Why stopping
Short wait, not an owner decision. The CI run for `3a175f9` on `main` was `in_progress` at 15:00, and R111 deploys only to a green `main` commit (cycle 11 precedent); polling CI is forbidden, so nothing more can run until the owner replies `update`. Done: 12b merged, post-merge record, smoke. Next: confirm main CI green on `3a175f9`, fast-forward `rehearsal`, R91 checks, END.

## §6 Mismatches
- **"trusTutor Team" is not in the repo, nor in the server environment.** Rehearsal's `config:show mail.from.name` is `TrusTutor (Support)`. The string must be a `settings` row typed in Admin (`from_name` or `site_name`) or SendPulse's own From override. After 12b a null row falls to `TrusTutor`; a typed value is kept (R29). See Owner action 1.
- **Rule wording tension for the planner:** CLAUDE.md "Owner loop" rule 6 returns code-level findings to the owner, R175 allows a fix loop (cap 2) with only a High halting. Both Low review findings were code; I fixed them under R175 (CYCLE-LOG 14:30).
- **Review follow-ups, deferred, not owed:** (a) the plain-text alternative of the two notifications is not Markdown-rendered, so a hostile name would show backslashes there (no ordinary name hits the escaped set); (b) `resources/views/vendor/mail/html/themes/default.css:68` (h3) has `text-align: left`, which the RTL grep does not match (v1 is ltr only); (c) the sweep tests are substring checks and only one mailable is rendered; per-mail tests already cover content; (d) Mailables have no plain-text alternative, as before this PR; (e) the wordmark URL needs `APP_URL` to be right on each server (checked at deploy).
- **Advisor record corrected (my mistake):** CYCLE-LOG 13:28 retracts the 13:00 ADVISOR entry; 13:50 and 14:28 are the verified ones.
- **PRD §2.2 point 2 (~line 35)** still says a tutor needs "a valid permit" to appear in search; stale under R170, outside R176's scope. Planner to authorise the one-line fix.
- **R176 said "DATA_MODEL v1.9"**; the chain ended at v1.7, so v1.8 was written (12a).
- **UI and emails not browser- or inbox-verified**: PLAN forbids CC a browser and real email. Rendered-HTML tests only; the owner walkthrough is the real check.
- **Local tooling:** `composer test` from PowerShell fails at `rtl:check` (no WSL `bash`); the steps were run individually. CI is unaffected.
- **LinkedIn host check:** `parse_url()` and browsers disagree on backslashes; no exploit path today (value only shown in an `<input>`).
- **Item 4 (Daily webhook proof) is partly corroborated:** rehearsal `lessons` 2, `payments` 2, `video_webhook_events` 0. Not marked closed.
- Carried, non-blocking: AY, AZ; the `config/app.php` allow-list; the untraceable "R91" defining text; the lesson that a Horizon config change needs a daemon restart.

## §7 Next step / Owner actions
**Owner action 0 (Recommended): reply `update`.** Reason: main CI on `3a175f9` was still running; one reply lets me read it once and, if green, deploy rehearsal in the same run. Owner actions that exist now, repeated at END:

**Owner action 1 (Recommended): check the sender name on rehearsal after the deploy.** In Admin → Settings → Mail → From name, then Site → Site name, look for "trusTutor Team". If neither holds it, it is SendPulse's From override. Clear or correct whichever holds it; CC cannot read either. Reason: the server environment is ruled out (`TrusTutor (Support)`), so only these two places remain. Reply `update — sender name <what you saw>`.

**Owner action 2: settle PLAN item 4 (Daily webhook proof).** Either (a) check the Daily dashboard's webhook delivery log for the 3 Oct joins and reply `update — item 4 confirmed` or `update — item 4: <what you saw>`; or (b) book and join one fresh lesson on rehearsal after the deploy and reply `update — joined`. Reason: `video_webhook_events` is 0 rows and only a join or Daily's own log can prove the path.

**Owner action 3: finish TEST RABIA's onboarding on rehearsal with only a LinkedIn URL** (no CV, no permit), submit, approve in admin filling subjects and rate, confirm she appears in search. After the deploy; reply `update — walkthrough done` or what broke.

**Owner action 4: R174(c), the SendPulse "Unsubscribe" link.** Owner-only, no code; say so in `update` once done.

Carried unchanged, at the owner's pace (PLAN items 8, 9, 12): UI-developer reviews (booking screen PR #22, 7g branding, messaging/reviews/Report button walkthrough). Owner-blocking, carried: D-04 with counsel (then CP5); authorising `trustutor-production`. Also carried: Postmark wiring (R120). Roadmap items from 2026-10-01 stay gated on consent text with counsel.

## §8 Programme board
Programme "tutor-onboarding" (cycle 12 r1, R175): 4 steps.
| # | Step | State |
|---|------|-------|
| 1 | PLAN commit | done `8b38277` |
| 2 | 12a onboarding | merged `3f2dbdc` |
| 3 | 12b email polish | merged `3a175f9` |
| 4 | Deploy rehearsal, R91 checks, END | pending |
Resume cap 0 of 8.
