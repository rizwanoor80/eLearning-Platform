# STATUS — cycle 17 r1 — END (all three steps done; context handoff to a new session); written 2026-10-17 17:12
Tests: **2646 passed / 13412 assertions in the cloud sandbox twice** (the five variables exported, then unset; `composer test` exit 0 both times; `ledger:verify` OK; CYCLE-LOG VERIFICATION 16:30; was 2646 / 13419 locally at cycle 16 END, 13412 in CI), and CI success on both PostgreSQL jobs for `e7d0be9`, `6a90aca` and the merge commit `6fdef0c` (runs 39049646071, 39051344817, 39051742276) · Advisor: **consulted 1 time (1 confirmed in part: 7 of 8 flags adopted, 1 rejected after a test)**, model named in its own report, counted on the configured `advisorModel` basis (R63) · Review: **17 verdicts, 9 PASS, 6 PASS WITH NOTE, 2 FAIL (Low, doc-only, fixed in round 1 of 2), 0 Medium, 0 High** · Context size: not measured by the tool (cloud session, no `/context`).

## §1 Git state
- `main` = `origin/main`: the code-bearing commit is **`6fdef0c`** (PR [#54](https://github.com/rizwanoor80/eLearning-Platform/pull/54), squash); the docs-only commits above it are `598ac14`, `f10742b` and the commit that carries this file. `git log origin/main..HEAD` is empty before that last commit.
- **`rehearsal` = `6fdef0c`**, confirmed by `/ops/status` (`app.git_sha`, release 80474430) at 17:05:59. Production untouched.
- Open PRs: none. `cp/17a-cloud-hardening` (head `6a90aca`) is left on the remote, as are `cp/16a-*` and `cp/16b-*`. The harness branch `claude/amazing-brown-rjmvya` is scratch under R210 and carries only docs commits that were also pushed to `main`.

## §2 Step map
1. Planner: PLAN r1 and `docs/cloud/ENVIRONMENT.md` on `main` — **[done]** (`e8af468`).
2. 17a under R212 and R210 — **[done]**: PR #54 merged, push CI green on both jobs, both proof runs with literal counts (2646 / 13412), ADR-028 addendum 2 and the two R215 sentences on `main`.
3. Rehearsal deploy and verification under R213, R214 value recorded, END — **[done]**: R213 green in full (below); the R214 value is recorded.

## §3 What changed this run
- **Code (PR #54, 6 files, 40 insertions, 21 deletions):** `phpunit.xml:22-35` all 14 `<env>` entries carry `force="true"`; `.github/workflows/ci.yml` "Build frontend assets" tries `npm run build` three times, 20 s apart, red only after the third; `scripts/cloud-session-start.sh` takes the role password from `${DB_PASSWORD:-postgres}` through psql's literal quoting on stdin, prints one line and skips `key:generate`/`migrate` when `vendor/autoload.php` is absent, and shows the last three lines of both otherwise; `docs/DECISIONS.md` ADR-028 addendum 2 (row 37); one R215 sentence each in `docs/HOW-WE-WORK.md:33` and `CLAUDE.md:85`. No file under `app/`, `database/`, `resources/` or `config/` changed.
- **Proof (CYCLE-LOG VERIFICATION 16:30):** with `CACHE_STORE=database SESSION_DRIVER=database QUEUE_CONNECTION=redis MAIL_MAILER=log DB_DATABASE=elearning` exported: Pest 2646 passed / 13412 assertions, `ledger:verify` OK, `exit=0`; with them unset: the same. The red counterpart is the 12:25 entry (497 failed, unforced file, same variables).
- **Deploy and verification (CYCLE-LOG VERIFICATION 17:07):** `94d8afa..6fdef0c` fast-forward at 17:04:55; the status read showed the new commit within 40 s; 0 pending migrations, Horizon running (master PID unchanged), queue 0 / failed 0, scheduler ok, ledger ok (0 unbalanced, 0 stranded); `public/build/manifest.json` 82 keys, 25081 bytes, byte-identical before and after; `/`, `/login`, `/admin/login`, `/tutors` 200 and unauthenticated `/ops/status` 404, before and after.
- **R214, the value seen:** `mail.from_name` = `trusTutor Team` (Owner action 5 below).
- **Repo-side result for the planner's list:** the Owner action 11 literal password is gone from the hook; the cloud environment variables R211 asked for are gone from the sandbox (checked by name at 16:12).

## §4 Decisions and by whom
- Owner (PLAN r1): R210–R215, the scope boundary, and removing the five variables from the environment (R211, done before this run).
- Advisor (1 consultation, design before the first edit): all entries forced, the `if`-guarded retry loop, stderr hidden on the password line, no `pipefail`. **Rejected after testing:** its `psql -v … -c "… :'pw'"` form (psql does not interpolate inside `-c`; the committed line pipes the statement on stdin).
- CC: the ADR row first claimed `force` makes the suite immune to any exported variable. That was my overreach on top of the advisor's narrower answer; the review caught it, and the row now says the protection holds for `--parallel` runs only (`vendor/phpunit/phpunit/src/TextUI/Configuration/PhpHandler.php:160-167` sets `putenv` and `$_ENV`, never `$_SERVER`).
- CC: PR creation, check reading and the one merge attempt went through the GitHub MCP tools because `gh` cannot authenticate here (the harness `GH_TOKEN` is invalid); squash, one attempt, no refusal (R147).
- CC: not built, because they exceed R212's text (all under Deferred): `<server … force>` twins, a `::warning::` annotation on a retried build, `elearning_testing` creation and a `pg_isready` line in the hook.
- Owner: the `update` that started this run.

## §5 Why stopping
**Plan complete; END reached, and the method halts here (step 3 says halt: yes, END). Context handoff:** this cloud session has no `/clear`, so the next `update` is a new session; the files carry the state.
- **Done:** 17a built, proved twice, reviewed, merged, record on `main`, push CI green; rehearsal deployed and verified; every CYCLE-LOG entry (START, ADVISOR, VERIFICATION ×3, NOTE ×2, REVIEW, DEVIATION, DECISION ×2, END, HANDOFF) is written.
- **Next:** the planner reads §6 and cuts cycle 18; the owner actions in §7 are independent of it.
- **Ruled out:** production deploy; any state-changing server command; CP5; frozen files (`LessonStateMachine.php`, `LedgerService.php`, `app/Services/Payments/*`); `docs/cloud/ENVIRONMENT.md` and `.claude/settings.json`; deleting any `cp/*` branch; a PR from the harness branch.

## §6 Mismatches
For the planner (none stops anything; all found by the 17a review or the proof runs):
- **`force="true"` protects `--parallel` runs only.** `composer test` and CI are covered; `php artisan test --filter=…`, `vendor/bin/pest <file>` and an IDE run still read an exported value, because Laravel reads `$_SERVER` first and PHPUnit's `force` never writes it. R211's rule (the environment exports no key `phpunit.xml` sets) is therefore still the guard there: a re-exported `DB_DATABASE=elearning` with a `RefreshDatabase` test would wipe the base database. A one-line follow-up exists (`<server name="…" value="…" force="true"/>` for the same names); it is a config change and needs a plan step.
- **`docs/cloud/ENVIRONMENT.md` (planner's file)** still lists `APP_ENV=testing` and `DB_CONNECTION=pgsql` (lines 21, 24), which `phpunit.xml` also sets, against its own rule at line 38 (harmless: equal values); and it still publishes `DB_PASSWORD=postgres` and `ADMIN_SEED_PASSWORD=cloud-test-only` (lines 28, 32), so a secret scanner that flagged the old hook can still flag it. Owner action 11 is half closed for that reason.
- **A fresh cloud session is not ready to run `composer test`.** After the roughly 9.5-minute `composer install --prefer-source` (measured 16:13:45–16:23:15) it also needs `npm run build` (otherwise about 280 tests fail with "Vite manifest not found", which is how my first attempt failed) and `createdb elearning_testing` (the hook creates only `elearning`; CI provides the other as its service database). `ENVIRONMENT.md` §3b could say so.
- The hook's "vendor/ missing" line does not mention `key:generate`, so following it by hand leaves `APP_KEY` empty until the next session start generates it (wording fixed by R212).
- A retry that only goes green on the second or third try leaves a stderr line and no annotation, so a Bunny flake can pass unnoticed (review item 8).
- An exported but empty `DB_PASSWORD` sets the sandbox role to `postgres` while Laravel sends an empty password (review item 10).
- `mail.from_name` reads `trusTutor Team` (R214, Owner action 5).
- Carried, unchanged from the 12:27 STATUS §6: HOW-WE-WORK §17 "the same four" versus CLAUDE.md's five cloud lines; the R178 wording; the advisor model basis (the harness tool names no model; the 17a consultation did name one in its own report); the Low notes of PR #52 and #53; the setup script's `composer install` not surviving into the session.

## §7 Next step / Owner actions
**Owner action 13: start the next cycle.** Open a new planning conversation in the Project and ask the planner `where are we?` with this file (cycle 17 is closed; §6 holds findings for it). Then start a new Claude Code session and reply `update` when the planner has written the next plan.

**Owner action 5 (reopened by R214): the mail sender name.** `/ops/status` reports `mail.from_name` = `trusTutor Team`; ADR-006 spells the brand `TrusTutor`. 1. (Recommended) In the admin mail settings set the sender name to `TrusTutor Team`; if that field is blank, set `MAIL_FROM_NAME` in Forge's environment for rehearsal; reason: it keeps your wording and fixes only the capital, and the surface that renders it is the status endpoint, which CC re-reads. 2. Leave it. Reply `update` when done.

Carried unchanged from the 12:27 STATUS (full text: `git show 467f057:docs/STATUS.md` §7): **Owner action 2** (the pre-join screen and the two new Daily properties on rehearsal), **3** (the Daily retry test, R184, after 2), **4** (the SendPulse sender display name and unsubscribe link, R174(c)), **6** (UI-developer review of the booking screen), **7** (UI-developer review of the 7g branding), **8** (UI-developer review of the onboarding checklist and copy), **9** (the Subjects form at 1280 and 375 px), **10** (the audit scope of `/ops/status`; option 1 Recommended: keep as built), **12** (see the display-name queue once). **Owner action 11 is half closed:** the hook no longer holds a literal password (this cycle), `ENVIRONMENT.md` does; it closes when the planner removes the two literals from that file. Owner action 1 of 12:27 is closed by R211 and R212.
Owner-blocking, carried: D-04 with counsel (overseas tutor payouts and tax, recording consent); authorising `trustutor-production`; Postmark wiring (R120).

To answer an action, reply `update — <n>: <answer>` in a new session.

## §8 Programme board
none. Cycle 17 r1 was a plain three-step cycle (resume cap 8, 0 used); no programme is active.

## Deferred
- **Cycle-17 follow-ups found by the 17a review (none built, all outside R212's text):** `<server … force="true"/>` twins in `phpunit.xml` so single-process runs are protected too; a `::warning::` annotation when the build only goes green on a retry; the hook creating `elearning_testing` and one `pg_isready` line; the empty-`DB_PASSWORD` mismatch between the role and Laravel; `APP_ENV`/`DB_CONNECTION` and the two published passwords in `docs/cloud/ENVIRONMENT.md` (planner's file).
- **`max_participants: 2` on Daily rooms** — a Daily property that cannot be run from here.
- **Inertia history encryption** — app-wide; page props (including the lesson title) stay in the browser's history state on a shared device.
- **English title literals in `LessonRoomView`** — "with" and "for" not yet translated; wait for the i18n pass.
- **`MessageMasker` gaps (cycle 14 r2 §6)** — touches messaging and invariant 8; planner to schedule.
- **`YearGroupMigrationTest` per-curriculum count map cannot tell two curricula with equal counts apart; `y8` looked up without a curriculum filter** — the two Low notes of the one-file review of R204, test-only.
- **A screen in our own markup** (R189(a) as worded) — only if the planner accepts the cost recorded in cycle 14 r2 §6.
- **Withdraw a display-name request, clear an approved name, email on approval** — R195 asks for none; a later plan if wanted.
- **Tutor dashboard shares the tutor's own IBAN in page props; a request can land on an anonymised tutor** — cycle 15 §6, outside findings A and B.
- **A guard against `VITE_FONTS_INLINE=0` being set where Bunny is reachable** (a warning in the build output) — the reviewer's Low note 8; not built, the switch is explicit by design.
- **Low notes of the 16b re-review (R207, three lenses; all in code or tests)** — fix the stale comment at `app/Providers/Filament/AdminPanelProvider.php:58`; make the consumer-scan test also match `@fonts ('x')`, `$vite->fonts()` and `app(Vite::class)->fonts()`; replace the self-comparison at `tests/Unit/ViteFontsFallbackTest.php:138`; name `VITE_FONTS_INLINE` in `docs/cloud/ENVIRONMENT.md`; `.font-<alias>` classes, preloads and `&subset=latin` in flag-0 builds; derive the flag-0 CSS variables from the plugin's own manifest builder; guard the SSR rule on `env.isSsrBuild` as well. Detail: CYCLE-LOG REVIEW 2026-10-16 18:04.
- **Closed this cycle and removed from the list:** the five exported variables overriding `phpunit.xml` (R211, R212(1)); the hook's silent `key:generate`/`migrate` and its literal password (R212(3)); the R209 candidate "harden CI against a Bunny timeout" (R212(2)).
