# STATUS — cycle 16 r1 — step 2 (16a): PR #52 open, review found two Medium FAILs, fix loop round 1 next; written 2026-10-15 23:02 (programme "cloud-fixes"; resume cap 0 of 8)
Tests: **2610 tests, 2610 passed, 13284 assertions** on the 16a head `b76ca9c` — the sum of 35 per-path slices, every exit code 0 (CYCLE-LOG VERIFICATION 22:27); baseline 2602 / 13256 plus the 8 tests / 28 assertions of `ComposerLockPortabilityTest`, so the suite did not shrink · `ledger:verify` OK · Pint passed · RTL grep passed · PHPStan (the pinned phar, Larastan extension) 0 errors, phar SHA-256 equals the pin · `npm run types:check` and `npm run build` exit 0, no tracked file written · CI on `b76ca9c` (run 38785444719): both PostgreSQL jobs green, the first real phar fetch printed "checksum verified" · Advisor: 2 consultations this cycle (13:17 design, 22:28 push boundary), the plan's minimum for 16a is 2 of 2, counted on the session's configured `advisorModel` basis (`claude-fable-5-1`) with the disclosure in §6; an unlogged call at about 22:20 is not counted · Review verdict (PR #52, `b76ca9c`, CYCLE-LOG REVIEW 22:58): **no High; 2 FAIL Medium and 1 FAIL Low open**, 15 PASS WITH NOTE (Low), 17 PASS; not approved for merge · Context size: not measured by the tool (this session has been compacted at least three times).

## §1 Git state
`main` = `origin/main` = `98be728` before this write (docs only: the plan, log entries and the owner's R178 restatement `a06acd9`); this STATUS and the log entries of 22:58–22:59 (PR note, REVIEW, DECISION) are committed on `main` with `[skip ci]` and pushed next, after which the checkout goes back to `cp/16a-tooling-portable` for fix round 1. Branch `cp/16a-tooling-portable` is pushed at `b76ca9c`, PR [#52](https://github.com/rizwanoor80/eLearning-Platform/pull/52) open against `main`. `rehearsal` is unchanged at `528ae6b`. `git log origin/main..cp/16a-tooling-portable`:
```
b76ca9c CP8 16a: Cloud sessions notes in CLAUDE.md and ENVIRONMENT.md for the portable toolchain (R202)
d115423 CP8 16a: portable tooling - PHPStan phar out of Composer, Wayfinder on install (R200, ADR-028)
```
Neither carries `[skip ci]`; each carries the Co-Authored-By footer. After the docs push the branch sits one docs-only commit behind `origin/main`, touching `docs/` only; the branch changes no `docs/` file except `docs/cloud/ENVIRONMENT.md`, which the docs commit does not touch, so it merges without a conflict. It is not rebased again.

## §2 Step map
1. Docs-only commit of PLAN r1 and the refreshed `docs/cloud/ENVIRONMENT.md` — **done**, `abb52a4`; START entry `50b02f3`.
2. `cp/16a-tooling-portable` (R200) — **in the fix loop (round 0 of 2 used)**: pushed, PR #52 open, CI green on `b76ca9c`, review done (all four R201a targets hold; three FAILs, two Medium, all cloud-side). Round 1 closes them (the project's `preferred-install: dist` overriding the cloud's global `source`; the phar fetch ignoring proxy variables; a mismatching old phar left in place) plus the cheap notes in the same files, then a narrow fresh-subagent re-review, CI on both PostgreSQL jobs (R177), self-merge (R201a / R147), post-merge record (ADR-028, CHECKPOINTS CP8 line).
3. `cp/16b-cloud-build` (R201) — **not started** (waits for 16a to merge).
4. Rehearsal deploy (R111), R91 + R169 checks, END — **not started**.

## §3 What changed
Everything below is on `cp/16a-tooling-portable` at `b76ca9c` (pushed, PR #52); fix round 1 will change it, and §3 is rewritten when it lands.
- **PHPStan leaves Composer's lock (R200).** `composer.json` gains `"replace": {"phpstan/phpstan": "2.2.14"}`; `composer.lock` loses only the `phpstan/phpstan` block (content hash changed, nothing else); `composer types:check` runs `tools/phpstan.phar`; `/tools/` is git-ignored. `scripts/fetch-phpstan.php` (run by `post-install-cmd` and `post-update-cmd`) downloads the phar for the tag in `scripts/phpstan.sha256` from `raw.githubusercontent.com`, writes it to a temporary file, checks its SHA-256 against the pin, and only then renames it into place; a mismatch deletes the temporary file and exits 1. It never executes the file. `COMPOSER_DEV_MODE=0` (Forge's `--no-dev`) skips it. The pin is `2.2.14` / `a7d45c01…77e1`, proved equal to the phar Composer had installed and to the phar on disk now.
- **Wayfinder is generated on install (R200).** `post-autoload-dump` gains `@php artisan wayfinder:generate --with-form`; the three output directories stay git-ignored (`git status` is empty after a build).
- **`tests/Unit/ComposerLockPortabilityTest.php` (8 tests, 28 assertions).** Every locked package has a git source, URL and commit; PHPStan is absent from the lock while Larastan is present; the `replace` version, the pin file and the three composer hooks agree; the fetched phar hashes to the pin; the fetcher is exercised in a throw-away root (matching phar untouched without network, `--no-dev` skips, three malformed pins refused before any network call).
- **CLAUDE.md "Cloud sessions" and `docs/cloud/ENVIRONMENT.md` (R202)** describe the new toolchain (five differences instead of four: PHPStan phar, `preferred-install source`, `COMPOSER_ALLOW_SUPERUSER`, Wayfinder on install); the stale `api.daily.co` note is replaced by a note that `raw.githubusercontent.com` is on the default list.
- **On `main` this step:** the owner's R178 restatement committed unchanged as `a06acd9` (CYCLE-LOG DECISION 21:56); log entries of 21:56 and 22:26–22:28; this file.
- Not yet written, by the plan's precedent they land in the post-merge record: ADR-028 in `docs/DECISIONS.md` (with the bump procedure and the `--no-dev` Wayfinder note), the CHECKPOINTS CP8 hardening line.
- Untouched: `LessonStateMachine.php`, `LedgerService.php`, `app/Services/Payments/*`, the ledger migration, `scripts/cloud-session-start.sh`, `.claude/settings.json`, `vite.config.ts` (16b).

## §4 Decisions and by whom
- Planner, accepted by the owner: PLAN cycle 16 r1, R199–R203.
- Owner: the R178 restatement in CLAUDE.md (2026-10-15), committed by CC as `a06acd9`; `update` after the halt.
- CC, 16a: (a) **`replace` instead of "remove from require-dev"** — `phpstan/phpstan` is not in `require-dev`, it is Larastan's dependency (DECISION 13:17, §6); (b) **the fetcher is PHP, not shell** (DEVIATION 13:17, §6); (c) advisor consultations 1 of 2 (13:17) and 2 of 2 (22:28), the first adopted with both adaptations above, the second confirming the push order; (d) the slices were rerun without `--no-interaction` (NOTE 22:26); (e) the branch was rebased onto `main` (NOTE 22:26); (f) the owner's CLAUDE.md edit was committed to `main` unchanged (DECISION 21:56).

## §5 Why stopping
Not stopping: this is a step-boundary write (rule 5), not a halt. The next actions are the docs push, the branch push, the PR and the review, all authorised by the plan. The run halts only at a High finding (R109), a refused merge (R147), a failed CI after the fix loop, or the plan's END.
- **Done:** step 1; 16a implemented, rebased, green on every gate (CYCLE-LOG VERIFICATION 22:27), pushed, PR #52 opened, CI green on the head, adversarial review complete (REVIEW 22:58).
- **Next:** fix loop round 1 on `cp/16a-tooling-portable` (FAIL 1, 2, 3 and the cheap notes; DECISION 22:59), full-suite slices, push; narrow fresh-subagent re-review; CI on both PostgreSQL jobs read through the PR status tool (no polling of my own); one merge attempt under R147 only if no Medium+ is open; post-merge record; then 16b and step 4. After round 2, an open Medium leaves PR #52 open with an Owner action here.
- **Ruled out:** starting Herd or any service (the services are up, the ports answer; nothing was started or stopped by me); pushing 16a before the suite was green; a second rebase of the branch after the docs push; `[skip ci]` on code commits; touching the `pos-app` processes on this machine.

## §6 Mismatches
- **Review of PR #52 (CYCLE-LOG REVIEW 22:58): the toolchain is correct in the repository, but two things written for the cloud were wrong or unproven.** (1) The cloud setup script's `composer config -g preferred-install source` does nothing here, because `composer.json` sets `"preferred-install": "dist"` itself and a project setting beats the global one (reproduced on Composer 2.10.1); R199 had proved only the `--prefer-source` flag. CLAUDE.md:31 and `docs/cloud/ENVIRONMENT.md:93` said the opposite. (2) The phar fetch used PHP's http stream wrapper, which ignores `HTTP(S)_PROXY`; if the cloud's egress is proxy-only it fails closed at `composer install`. Whether `raw.githubusercontent.com/phpstan/phpstan` is reachable from the cloud at all has still never been observed; only the R203 pilot re-run can show it. (3) A stale phar whose hash differs from the pin was kept after a failed refresh. Planner: the decision to run the fix loop rather than hand these back is DECISION 22:59 (R201a names the loop); say so if you read the rule differently. Left as notes, not fixed in 16a: redirects to any host (the hash is the gate), the Wayfinder hook's 43 s on a refused database port on Windows, DB-dependent generated binding types, `composer validate --check-lock` for pin bumps (ADR-028 will name the lock refresh). **For the planner:** HOW-WE-WORK §17 still says "the same four"; CLAUDE.md "Cloud sessions" now has five.
- **Plan versus repository: `phpstan/phpstan` is not in `require-dev`.** R200 (1) says to remove it from `require-dev`. It is only Larastan's requirement (`^2.2.14`), so the lock cannot lose it without a root `replace`. Built and proved as `replace` pinned to `2.2.14` (DECISION 13:17); `phpstan.neon` is unchanged. Planner: say whether you want Larastan dropped instead (it would lose the Laravel-aware rules).
- **Plan versus machine: `scripts/fetch-phpstan.php`, not `.sh` (DEVIATION 13:17).** On this machine `bash` under PowerShell is the WSL launcher stub, which would make `composer install` fail on the owner's own shell. Same contract: pinned tag, SHA-256 check, no execution of the download.
- **`--no-dev` deploys still run Wayfinder.** `laravel/wayfinder` is in `require`, so Forge's `composer install --no-dev` runs `wayfinder:generate --with-form` too (the phar fetch is skipped by `COMPOSER_DEV_MODE=0`). It writes only git-ignored files and boots the app, which a deploy can do. ADR-028 will say so; step 4's rehearsal deploy must be watched for it, and a `public/build/manifest.json` comparison is in the plan.
- **`.env` ordering in the setup script.** `cp -n .env.example .env` runs after `composer install`, so the install's artisan hooks run with no `.env`. A clean clone with no `.env` installed with exit 0 (417 s), so the order stays as the planner wrote it; if a hook ever needs the `.env`, the copy must move before the install.
- **The owner's CLAUDE.md edit was committed by me as a docs-only commit on `main` (`a06acd9`, `[skip ci]`).** The text is the owner's, unchanged. That is a reading of "docs-only commits go to main", not a named authorisation (rule 8); it was done because the uncommitted edit would have blocked every branch switch. Revert with `git revert a06acd9` if you disagree.
- **Owner action 11 (start the services) was satisfied by evidence, not by a reply:** when the run resumed, ports 5432, 6379 and 2525 accepted connections. I did not start anything. The 14:05 BLOCKER was correct under the wording then in force; the owner has since restated R178 so that CC starts the services itself before halting.
- **BLOCKER 13:51: the first `composer install` after the 16a edits passed 10 minutes.** Stopped (my own composer processes only), rerun with the output to a file, and it ended with exit 0 in 417 s on a clean clone. Root cause not proven.
- **NOTE 13:51: I committed log entries on the feature branch and tried a fast-forward merge into `main`, which the permission classifier denied.** I did not work around it: I undid my own unpushed commit (`git reset --soft HEAD~1`) and committed the record on `main`.
- **`php artisan test` takes no `--no-interaction`** (PHPUnit rejects it), so CLAUDE.md's "every artisan command non-interactive" cannot apply to it. My first slice run used the flag and was void (NOTE 22:26). Same as cycle 15.
- **`composer` is not on Git Bash's PATH here**, so `composer types:check` cannot run from the Bash tool; it runs from PowerShell, and for this gate I ran the exact command it wraps (`php tools/phpstan.phar analyse --memory-limit=512M`).
- **Advisor model not named by the tool; one new fact.** The tool result does not carry the answering model's name, so entries are counted on the `advisorModel` basis (`claude-fable-5-1`) with that disclosure, which rule 11 may not accept (carried from cycle 14 r2). New: the session transcript file does record `"type":"advisor_message","model":"claude-fable-5-1"` for the 22:20 call, which supports the basis but is not something the tool tells me while it works. One call (about 22:20) was made but not logged at the time and cannot be quoted afterwards (the answer is stored encrypted), so it does not count (NOTE 22:26).
- **Carried from cycle 15 r1, unchanged and not touched this cycle** (full wording: `git show 5faef33:docs/STATUS.md`): the first authenticated `/ops/status` read is possible only if a token exists (none did on 2026-10-13; planner to say where a local session finds `TRUSTUTOR_STATUS_TOKEN`); `crontab -l` for `forge` prints "no crontab" on rehearsal, the scheduler is proved from `/home/forge/.forge/scheduled-*.log` instead; the 15c Low doc-only FAIL at `app/Rules/DisplayNameRule.php:47-49` and notes 8 and 13; the 15c admitted-by-design classes; outside findings A (`TutorDashboardController` puts the tutor's own decrypted IBAN in page props) and B (a display-name request can land on an anonymised tutor); the literal throwaway password in `scripts/cloud-session-start.sh` line 6 (Owner action 8); the cloud hook's relative path, its `migrate` on every compaction and missing `set -e`; the cloud unknowns (rehearsal push from a cloud session, `/ops/status` IP allow-list, `DB_DATABASE`, PG 16 only as the CI image); 15b round-2 wording notes (a)–(d) in CLAUDE.md and HOW-WE-WORK; doubled CI minutes; Appendix B versus rule 13; the audit scope of `/ops/status` (Owner action 7); the 15a Low notes; the cycle 14 r2 items (R189 a and b, the `MessageMasker` gaps a–d, the sender name, five pending local migrations). (The "R178 versus CLAUDE.md Local environment" item of earlier lists is resolved by the owner's restatement.)
- **Resolved by this cycle's step 1:** the planner refreshed `docs/cloud/ENVIRONMENT.md`, which was stale in cycle 15.

## §7 Next step / Owner actions
No owner action is needed to continue: the run goes on to fix round 1, the re-review and CI. The carried actions below are at your own pace. Per PLAN cycle 16, the R203 pilot re-run comes first once this cycle has ended and `main` carries 16a and 16b; at the END I will write it out in full. If the permission check refuses my merge of the 16a PR, I will not retry; the owner merges on GitHub and replies `update` (R147).

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
| 2 | 16a portable tooling (R200) | PR #52, CI green on `b76ca9c` (2610 / 13284); review: no High, 2 Medium + 1 Low FAIL open; fix loop round 1 next (cap 2), then re-review, CI, merge |
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
