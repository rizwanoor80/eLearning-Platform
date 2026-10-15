# STATUS — cycle 16 r1 — HALT in step 2 (16a): PostgreSQL and Redis are down on this machine, so the suite cannot run; written 2026-10-15 14:12 (programme "cloud-fixes"; resume cap 0 of 8)
Tests: **no full-suite run on the 16a head yet.** The baseline is 2602 passed / 13256 assertions (cycle 15, 15c head and `main`). On `5cd175a` only the database-free gates have run: the new `ComposerLockPortabilityTest` 8 tests / 28 assertions passed alone; the first suite slice, `tests/Unit`, ran with the database down and gave 316 tests, 305 passed, 11 errors, all `Connection refused` to 127.0.0.1:5432 (CYCLE-LOG BLOCKER 14:05) — harness failures, not evidence about the code; the second slice was stopped. `ledger:verify` not run (needs the database) · `composer types:check` 0 errors, Pint, `npm run types:check`, `npm run build` and the RTL grep green · CI: none yet (nothing pushed) · Advisor: 1 consultation this cycle (13:17, 16a design), the plan's minimum for 16a is 2, so one more is due before the PR; the tool does not name its model, so it is counted on the session's configured `advisorModel` basis (`claude-fable-5-1`) with that disclosure (§6) · Review verdict: none yet (no PR) · Context size: not measured by the tool (this session has been compacted at least once).

## §1 Git state
`main` = `origin/main` = `50b02f3` (the START entry), plus the docs-only commit that carries this file and the log entries, pushed at once with `[skip ci]`. Branch `cp/16a-tooling-portable` exists **locally only**, two commits above `50b02f3`, **not pushed** (rule 7: the suite must be green with the literal count first); the checkout is left on that branch. `rehearsal` is unchanged at `528ae6b`. The commits on the branch, `git log origin/main..HEAD`:
```
5cd175a CP8 16a: Cloud sessions notes in CLAUDE.md and ENVIRONMENT.md for the portable toolchain (R202)
0447d05 CP8 16a: portable tooling - PHPStan phar out of Composer, Wayfinder on install (R200, ADR-028)
```
Neither carries `[skip ci]`; each carries the Co-Authored-By footer. `tools/` (the fetched phar) is git-ignored on the branch and shows as untracked only while `main` is checked out.

## §2 Step map
1. Docs-only commit of PLAN r1 and the refreshed `docs/cloud/ENVIRONMENT.md` — **done**, `abb52a4`; START entry `50b02f3`.
2. `cp/16a-tooling-portable` (R200) — **implemented and committed locally, gate blocked**: the code and the docs are written and every database-free check is green; the full suite, `ledger:verify`, the second advisor consultation, the push, the PR, the review, CI and the merge are all still to do.
3. `cp/16b-cloud-build` (R201) — **not started** (waits for 16a to merge).
4. Rehearsal deploy (R111), R91 + R169 checks, END — **not started**.

## §3 What changed
Everything below is on the local branch only.
- **PHPStan leaves Composer's lock (R200).** `composer.json` gains `"replace": {"phpstan/phpstan": "2.2.14"}`; `composer.lock` loses only the `phpstan/phpstan` block (content hash changed, nothing else); `composer types:check` runs `tools/phpstan.phar`; `/tools/` is git-ignored. `scripts/fetch-phpstan.php` (run by `post-install-cmd` and `post-update-cmd`) downloads the phar for the tag in `scripts/phpstan.sha256` from `raw.githubusercontent.com`, writes it to a temporary file, checks its SHA-256 against the pin, and only then renames it into place; a mismatch deletes the temporary file and exits 1. It never executes the file. `COMPOSER_DEV_MODE=0` (Forge's `--no-dev`) skips it. The pin is `2.2.14` / `a7d45c01…77e1`, proved equal to the phar Composer had installed.
- **Wayfinder is generated on install (R200).** `post-autoload-dump` gains `@php artisan wayfinder:generate --with-form`; the three output directories stay git-ignored.
- **`tests/Unit/ComposerLockPortabilityTest.php` (8 tests).** Every locked package has a git source, URL and commit; PHPStan is absent from the lock while Larastan is present; the `replace` version, the pin file and the three composer hooks agree; the fetched phar hashes to the pin; the fetcher is exercised in a throw-away root (matching phar untouched without network, `--no-dev` skips, three malformed pins refused before any network call).
- **CLAUDE.md "Cloud sessions" and `docs/cloud/ENVIRONMENT.md` (R202)** describe the new toolchain; the stale `api.daily.co` note is replaced by a note that `raw.githubusercontent.com` is on the default list; the setup script is unchanged.
- Not yet written, by the plan's precedent they land in the post-merge record: ADR-028 in `docs/DECISIONS.md` (with the bump procedure and the `--no-dev` Wayfinder note), the CHECKPOINTS CP8 hardening line.
- Untouched: `LessonStateMachine.php`, `LedgerService.php`, `app/Services/Payments/*`, the ledger migration, `scripts/cloud-session-start.sh`, `.claude/settings.json`, `vite.config.ts` (16b).

## §4 Decisions and by whom
- Planner, accepted by the owner: PLAN cycle 16 r1, R199–R203.
- CC, 16a: (a) **`replace` instead of "remove from require-dev"** — `phpstan/phpstan` is not in `require-dev`, it is Larastan's dependency (DECISION 13:17, §6); (b) **the fetcher is PHP, not shell** (DEVIATION 13:17, §6); (c) advisor consultation 1 of 2 (13:17), adopted with both adaptations above; (d) the slice run was stopped when the database turned out to be down, and nothing was restarted (BLOCKER 14:05).
- CC: halting rather than starting Herd or any service, as CLAUDE.md requires.

## §5 Why stopping
A service is down and only the owner may start it (CLAUDE.md "Local environment"): PostgreSQL (5432), Redis (6379) and the mail catcher (2525) all refuse connections, and the Herd application is not running (only `HerdHelper`). This is a handoff for a clear (rule 13).
- **Done:** step 1; 16a implemented and committed locally; all database-free gates green (CYCLE-LOG VERIFICATION 14:04).
- **Next:** after the owner starts the services, run the 16a suite in per-path slices (one `php artisan test --parallel <path>` for `tests/Unit`, each `tests/Feature/<dir>`, each top-level `tests/Feature/*Test.php`; a single full run passed the 10-minute stall line in cycle 15, BLOCKER 19:22), sum against the 2602 / 13256 baseline plus the 8 new tests, `ledger:verify`, ADVISOR 2 of 2, push the branch, open the PR, fresh-subagent review (the four targets in PLAN R201a), fix loop (cap 2), CI green on both PostgreSQL jobs, self-merge under R201a / R147, post-merge record, then 16b and step 4.
- **Ruled out:** starting Herd, PostgreSQL or Redis from this shell; pushing 16a with no suite evidence; running the slices again before the database is up; touching the `pos-app` processes on this machine.

## §6 Mismatches
- **Plan versus repository: `phpstan/phpstan` is not in `require-dev`.** R200 (1) says to remove it from `require-dev`. It is only Larastan's requirement (`^2.2.14`), so the lock cannot lose it without a root `replace`. Built and proved as `replace` pinned to `2.2.14` (DECISION 13:17); `phpstan.neon` is unchanged. Planner: say whether you want Larastan dropped instead (it would lose the Laravel-aware rules).
- **Plan versus machine: `scripts/fetch-phpstan.php`, not `.sh` (DEVIATION 13:17).** On this machine `bash` under PowerShell is the WSL launcher stub, which would make `composer install` fail on the owner's own shell. Same contract: pinned tag, SHA-256 check, no execution of the download.
- **`--no-dev` deploys still run Wayfinder.** `laravel/wayfinder` is in `require`, so Forge's `composer install --no-dev` runs `wayfinder:generate --with-form` too (the phar fetch is skipped by `COMPOSER_DEV_MODE=0`). It writes only git-ignored files and boots the app, which a deploy can do. ADR-028 will say so; step 4's rehearsal deploy must be watched for it, and a `public/build/manifest.json` comparison is in the plan.
- **`.env` ordering in the setup script.** `cp -n .env.example .env` runs after `composer install`, so the install's artisan hooks run with no `.env`. A clean clone with no `.env` installed with exit 0 (417 s), so the order stays as the planner wrote it; if a hook ever needs the `.env`, the copy must move before the install.
- **BLOCKER 13:51: the first `composer install` after the 16a edits passed 10 minutes.** Stopped (my own composer processes only), rerun with the output to a file, and it ended with exit 0 in 417 s on a clean clone. Root cause not proven; the 14:05 BLOCKER notes a possible link to the local services being down and calls it a guess.
- **NOTE 13:51: I committed log entries on the feature branch and tried a fast-forward merge into `main`, which the permission classifier denied.** I did not work around it: I undid my own unpushed commit (`git reset --soft HEAD~1`), pushed nothing, and committed this record directly on `main`, which is where docs-only commits go.
- **`php artisan test` takes no `--no-interaction`** (PHPUnit rejects it), so CLAUDE.md's "every artisan command non-interactive" cannot apply to it. Same as cycle 15.
- **Advisor model not named.** The tool does not name the model that answered; the one consultation so far is counted on the `advisorModel` basis with that disclosure, which rule 11 may not accept (carried from cycle 14 r2).
- **Carried from cycle 15 r1, unchanged and not touched this cycle** (full wording: `git show 5faef33:docs/STATUS.md`): the first authenticated `/ops/status` read is possible only if a token exists (none did on 2026-10-13; planner to say where a local session finds `TRUSTUTOR_STATUS_TOKEN`); `crontab -l` for `forge` prints "no crontab" on rehearsal, the scheduler is proved from `/home/forge/.forge/scheduled-*.log` instead; the 15c Low doc-only FAIL at `app/Rules/DisplayNameRule.php:47-49` and notes 8 and 13; the 15c admitted-by-design classes; outside findings A (`TutorDashboardController` puts the tutor's own decrypted IBAN in page props) and B (a display-name request can land on an anonymised tutor); the literal throwaway password in `scripts/cloud-session-start.sh` line 6 (Owner action 8); the cloud hook's relative path, its `migrate` on every compaction and missing `set -e`; the cloud unknowns (rehearsal push from a cloud session, `/ops/status` IP allow-list, `DB_DATABASE`, PG 16 only as the CI image); 15b round-2 wording notes (a)–(d) in CLAUDE.md and HOW-WE-WORK; doubled CI minutes; R178 versus CLAUDE.md "Local environment"; Appendix B versus rule 13; the audit scope of `/ops/status` (Owner action 7); the 15a Low notes; the cycle 14 r2 items (R189 a and b, the `MessageMasker` gaps a–d, the sender name, five pending local migrations).
- **Resolved by this cycle's step 1:** the planner refreshed `docs/cloud/ENVIRONMENT.md`, which was stale in cycle 15 (§2 hook copy, "owner-only" settings, "until the status endpoint exists").

## §7 Next step / Owner actions
**Owner action 11 (do this now): start the local services.** (1) Open the Laravel Herd application (it is not running; only `HerdHelper` is). (2) Start PostgreSQL, Redis and the mail service in Herd. Check: PostgreSQL answers on 127.0.0.1:5432, Redis on 6379, the mail catcher on 2525. Reason: every database-backed test, `ledger:verify`, and therefore every gate before the 16a push, needs them, and CLAUDE.md lets only you start them. Then `/clear` this session and reply `update — services up`.

The carried actions below are at your own pace; none blocks Owner action 11. Per PLAN cycle 16, the R203 pilot re-run comes first once this cycle has ended and `main` carries 16a and 16b; at the END I will write it out in full.

**Owner action 1 (Recommended): prove the pre-join screen and both new Daily properties on rehearsal.** (1) As the demo parent, book a lesson with a demo tutor that starts **more than 15 minutes from now** (its room is created 15 minutes before the start). (2) Open the lesson page about 10 minutes before the start: the title reads "<subject> with <tutor first name> · for <learner>". Click "Join the lesson here": expect Daily's own check inside the frame (camera and microphone preview, device pickers, Daily's Join) and the Join button gone. Join, confirm you are in the call, leave. (3) As the tutor, the title reads "<subject> with <learner>". (4) If joining answers "The video room is unavailable right now", or the lesson never gets a room, reply with exactly that. Reply `update` when done.

**Owner action 2: the Daily retry test (R184), after Owner action 1 is clean.** Keep the real Daily webhook secret open in Daily's dashboard **before you start** (once overwritten it cannot be read back). (1) Admin → Video providers → Daily → Webhook secret: type `bm90LXRoZS1zZWNyZXQ=` → Save. (2) Book another demo-tutor lesson for the next top of the hour inside the tutor's availability, join as the parent, leave. (3) Reply `update`; CC reads `video:webhooks --since=1h` and should see deliveries rejected (401) and no stored events. (4) Paste the real secret back → Save. (5) Reply `update` again after an hour. "No redelivery" is an answer, not a failure.

**Owner action 3: the sender name.**
 1. (Recommended) In Admin → Settings → Mail, set "From name" to `trusTutor Team`. Reason: rehearsal sends as "TrusTutor (Support)" today and CC may not write settings.
 2. Leave "TrusTutor (Support)" and tell the planner it is the intended name.
Reply `update` when done.

**Owner action 4: SendPulse sender display name and unsubscribe link (R174(c)).**
 1. (Recommended) In SendPulse set the sender display name to `TrusTutor` and turn off list-unsubscribe and unsubscribe-link insertion for transactional SMTP. Reason: both are SendPulse account settings, not ours, and these mails are transactional.
 2. Leave them as they are and tell the planner.
Reply `update` when done.

**Owner action 5: the Subjects form.** Open the tutor onboarding Subjects step on rehearsal at 1280 px and 375 px wide and confirm the subject row wraps without overflow. Reply `update` when done.

**Owner action 6: UI-developer review** of the onboarding checklist and copy (tutor onboarding, tutor dashboard, admin review page, parent "Get started", `docs/copy/onboarding.md`); carried items 8 and 9 stay at the owner's pace. Reply `update` when done.

**Owner action 7: the audit scope of `/ops/status`.**
 1. (Recommended) Keep it as built: successful reads are audited with the caller address; rejected calls are in `laravel.log` with the reason and address. Reason: a rejection has no subject row to attach to, and writing one audit row per probe lets anyone who finds the URL fill the audit table.
 2. Ask for one audit row per rejected call as well (CC adds one `audit_logs` write in the middleware's `reject()` with a fixed subject, in a small follow-up PR).
Reply `update` when done.

**Owner action 8: the literal password in the cloud hook.**
 1. (Recommended) Ask the planner to change `scripts/cloud-session-start.sh` to read the sandbox database password from a variable set in the `trustutor` cloud environment (CC may not edit that file). Reason: a literal credential in a public repository is a bad habit and trips secret scanners, even when the value only protects a throwaway sandbox database.
 2. Leave it as it is: the value guards nothing outside the cloud VM.
Reply `update` when done.

**Owner action 9: see the display-name queue once on rehearsal.** As the demo tutor, ask for a display name in Settings → Profile; as the admin, open Display name requests, approve one and reject one with a note; as the demo parent, check the name shows only after approval. Reply `update` when done. (The demo seeder sets no names, so nothing shows until you do this.)

**Owner action 10: the cloud pilot (R196)** — done on 2026-10-15; its findings are R199 a–g and this cycle fixes them. The re-run is R203, after this cycle's END.

Owner-blocking, carried: D-04 with counsel (overseas tutor payouts and tax, recording consent); authorising `trustutor-production`; Postmark wiring (R120).

## §8 Programme board
Programme "cloud-fixes" (cycle 16 r1).
| # | Item | State |
|---|------|-------|
| 1 | PLAN r1 commit + refreshed ENVIRONMENT.md | done `abb52a4`, START `50b02f3` |
| 2 | 16a portable tooling (R200) | implemented locally (`0447d05`, `5cd175a`); suite blocked: PostgreSQL and Redis down (Owner action 11) |
| 3 | 16b cloud build (R201) | not started |
| 4 | Rehearsal deploy, R91, R169, END | not started |
Resume cap 0 of 8.

## Deferred
- **`max_participants: 2` on Daily rooms** — a Daily property that cannot be run from here.
- **Inertia history encryption** — app-wide; page props (including the lesson title) stay in the browser's history state on a shared device.
- **English title literals in `LessonRoomView`** — "with" and "for" not yet translated; wait for the i18n pass.
- **`MessageMasker` gaps (cycle 14 r2 §6)** — touches messaging and invariant 8; planner to schedule.
- **`YearGroupMigrationTest` line 27 is order-dependent** — add an `ORDER BY` or compare with `toEqual`; a test-only fix.
- **A screen in our own markup** (R189(a) as worded) — only if the planner accepts the cost recorded in cycle 14 r2 §6.
- **Withdraw a display-name request, clear an approved name, email on approval** — R195 asks for none; a later plan if wanted.
- **Tutor dashboard shares the tutor's own IBAN in page props; a request can land on an anonymised tutor** — cycle 15 §6, outside findings A and B.
