# STATUS — cycle 17 r1 — step 2 in progress (PR #54 reviewed, fix round 1 pushed, waiting for CI on the new head); written 2026-10-17 16:55
Tests: **2646 passed / 13412 assertions in the cloud sandbox twice** (five variables exported, then unset; `composer test` exit 0 both times, `ledger:verify` OK; CYCLE-LOG VERIFICATION 16:30) and CI success on both PostgreSQL jobs for `e7d0be9` (run 39049646071) · Advisor: **consulted 1 time (1 confirmed in part: 1 flag rejected after a test)**, model named in its report, counted on the configured `advisorModel` basis (R63) · Review: **17 verdicts, 9 PASS, 6 PASS WITH NOTE, 2 FAIL (Low, doc-only, fixed in round 1 of 2), 0 Medium, 0 High** · Context size: not measured by the tool (cloud session, no `/context`).

## §1 Git state
- `origin/main` carries the docs-only commits of this cycle (START `e7729d0`, ADVISOR entry `baf0e73`, and the commit that carries this file); the code is not on `main` yet. Rule 7 count: `git log origin/main..HEAD` on `cp/17a-cloud-hardening` before the first push was `e7d0be9` only; after fix round 1 it is `e7d0be9` and `6a90aca`.
- Open PR: [#54](https://github.com/rizwanoor80/eLearning-Platform/pull/54) `cp/17a-cloud-hardening` → `main`, head `6a90aca`. CI run 39049646071 on `e7d0be9`: PostgreSQL 16 success (2 m 10 s), PostgreSQL 18 success (2 m 54 s). A new run for `6a90aca` is expected (the PR diff still holds non-ignored paths); its result is the merge condition.
- The harness branch `claude/amazing-brown-rjmvya` is scratch under R210 and carries only docs commits. `rehearsal` = `94d8afa` (unchanged; step 3 moves it). `cp/16a-*` and `cp/16b-*` untouched.

## §2 Step map
1. Planner: PLAN r1 and `docs/cloud/ENVIRONMENT.md` on `main` — **[done]** (`e8af468`).
2. 17a under R212 and R210 — **[in progress]**: (1) `phpunit.xml` forced, (2) CI retry loop, (3) hook, (4) ADR-028 addendum 2 and the two R215 sentences — all built and pushed; both proof runs recorded with literal counts; one independent review done; fix round 1 (doc-only) pushed. Left: CI green on `6a90aca`, one merge attempt under R147, the post-merge record, green push CI on `main`.
3. Rehearsal deploy and verification under R213, R214 value recorded, END — **[not started]**.

## §3 What changed this run
- **Code (PR #54, 6 files, 40 insertions, 21 deletions at `e7d0be9`):** `phpunit.xml:22-35` all 14 `<env>` entries `force="true"`; `.github/workflows/ci.yml` "Build frontend assets" tries `npm run build` three times, 20 s apart, red only after the third; `scripts/cloud-session-start.sh` takes the role password from `${DB_PASSWORD:-postgres}` through psql's literal quoting on stdin, prints one line and skips `key:generate`/`migrate` when `vendor/autoload.php` is absent, shows the last three lines of both otherwise; `docs/DECISIONS.md` ADR-028 addendum 2 (row 37); one R215 sentence each in `docs/HOW-WE-WORK.md:33` and `CLAUDE.md:85`.
- **Proof (CYCLE-LOG VERIFICATION 16:30):** proof 1 with `CACHE_STORE=database SESSION_DRIVER=database QUEUE_CONNECTION=redis MAIL_MAILER=log DB_DATABASE=elearning` exported: Pest 2646 passed / 13412 assertions, `ledger:verify` OK, `exit=0`; proof 2 with them unset: the same numbers, `exit=0`. The red control is the 12:25 entry (497 failed, unforced file, same variables). My first proof 1 attempt was invalid (no `npm run build`, 281 failures from "Vite manifest not found", 0 foreign-key lines); it is logged and not counted.
- **Review (CYCLE-LOG REVIEW 16:47):** no Medium or High. Two Low FAILs in the ADR row, fixed in `6a90aca` (DEVIATION 16:52).

## §4 Decisions and by whom
- Owner (PLAN r1): R210–R215 and the scope boundary.
- Advisor (1 consultation, design before the first edit): all 14 entries forced; the retry loop with an `if` guard; keep stderr hidden on the password line; do not add `pipefail`. **Rejected after testing:** its `psql -v … -c "… :'pw'"` form fails with a syntax error (psql does not interpolate inside `-c`); the committed line pipes the statement on stdin.
- CC: the ADR row first claimed `force` makes the suite immune to any exported variable. That was my overreach on top of the advisor's narrower answer; the review caught it and the row now says the protection holds for `--parallel` runs only (the `$_SERVER` mechanism, confirmed in `vendor/phpunit/phpunit/src/TextUI/Configuration/PhpHandler.php:160-167`).
- CC: PR created and (when it happens) merged through the GitHub MCP tools because `gh` cannot authenticate in this session; squash, one attempt (R147).
- CC: not built because they exceed R212's text, all under Deferred below: a `::warning::` annotation on a retried build; `<server … force>` twins; creating `elearning_testing` in the hook; a `pg_isready` line.

## §5 Why stopping
Not stopping. The next events are the CI result on `6a90aca`, then the merge attempt, then the post-merge record and step 3. This write is the step-boundary rewrite for the PR push and the review.

## §6 Mismatches
- **Forcing protects `--parallel` runs only** (review item 5, confirmed in source). `composer test` and CI are protected; `php artisan test --filter=…`, `vendor/bin/pest <file>` and an IDE run still read an exported value through `$_SERVER`. R211's rule (the environment exports no key `phpunit.xml` sets) is therefore still the guard for single-process runs, and a re-exported `DB_DATABASE=elearning` with a `RefreshDatabase` test would wipe the base database. Planner: this is a one-line follow-up if wanted (`<server name="…" value="…" force="true"/>` for the same names), a config change that needs a plan step.
- **`docs/cloud/ENVIRONMENT.md` still lists `APP_ENV=testing` and `DB_CONNECTION=pgsql`** (lines 21 and 24), which `phpunit.xml` also sets, against its own rule at line 38; they are harmless because they equal the forced values. It also still publishes `DB_PASSWORD=postgres` and `ADMIN_SEED_PASSWORD=cloud-test-only` (lines 28, 32), so a secret scanner that flagged the old hook can still flag that file; Owner action 11 is only half closed by this PR. Planner's file; not touched.
- **The hook's "vendor/ missing" line does not mention `key:generate`**, so following it by hand leaves `APP_KEY` empty until the next session start generates it (advisor item 6 and review item 12). Wording fixed by R212.
- **The hook still does not create `elearning_testing`.** `php artisan test --parallel` needs it (the proof runs failed at nothing here only because I created it by hand, as the 12:25 control did); CI provides it as the service database. A fresh cloud session must `createdb elearning_testing` before its first `composer test`, and it must run `npm run build` first (otherwise ~280 tests fail on the missing Vite manifest — my own first attempt).
- Carried unchanged from the 12:27 STATUS §6: HOW-WE-WORK §17 "the same four" versus CLAUDE.md's five cloud lines; the R178 wording; the advisor model basis; the Low notes of PR #52 and #53; the setup script's `composer install` not surviving into the session (a session installs itself, about 9.5 minutes measured at 16:13–16:23).

## §7 Next step / Owner actions
Next (CC, no halt): CI green on `6a90aca` (R177 wait) → one merge attempt of #54 under R147 → post-merge record on `main` (ADR row's PR and merge hash) → green push CI → step 3 (R213 deploy and verification, R214 value) → END.

No new owner action. Carried unchanged from the 12:27 STATUS (`git show 467f057:docs/STATUS.md` §7): **Owner actions 2, 3, 4, 6, 7, 8, 9, 10, 12**. **Owner action 11 is half closed:** the hook no longer holds a literal password (this PR), but `docs/cloud/ENVIRONMENT.md` still does; it closes when the planner removes the two literals from that file. Owner action 5 is reopened by R214 and CC changes nothing for it. Owner action 1 of 12:27 is closed by R211 and R212.
Owner-blocking, carried: D-04 with counsel; authorising `trustutor-production`; Postmark wiring (R120).

## §8 Programme board
none (cycle 17 r1 is a plain three-step cycle with a resume cap of 8; no programme is active).

## Deferred
Carried unchanged from the 12:27 STATUS (`git show 467f057:docs/STATUS.md` `## Deferred`), except: the two cloud items (the five exported variables; the hook's silent `key:generate`/`migrate` and the literal password) are this cycle's R211/R212 and leave the list when 17a merges; the cycle-17 candidate "harden CI against a Bunny timeout (R209)" is R212(2).
- **Cycle-17 follow-ups found by the 17a review (none built, all outside R212's text):** `<server … force="true"/>` twins in `phpunit.xml` so single-process runs are protected too (item 5); a `::warning::` annotation when the build only goes green on a retry (item 8); the hook creating `elearning_testing` and one `pg_isready` line (advisor items 2 and 7); the empty-`DB_PASSWORD` mismatch between the role (`postgres`) and Laravel (empty) (item 10); `APP_ENV`/`DB_CONNECTION` and the two published passwords in `docs/cloud/ENVIRONMENT.md` (items 6, 17; planner's file).
