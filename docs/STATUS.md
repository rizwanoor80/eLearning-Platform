# STATUS — cycle 01 r3 — written 2026-09-17 11:30
Tests: 39 (was 0) · Advisor: consulted 2 times this cycle (4 times total across cycle 01) · Review: n/a

## §1 Git state
`main` at `0b5f0dd` on `origin/main` (unchanged this section of the run; the docs-only commit below will move it). Branch `cp/0-foundation` created from `0b5f0dd`, one commit ahead: `582cf62` "Scaffold CP0 foundation: Laravel 12 kit, Horizon, Reverb, Filament" — pushed to `origin/cp/0-foundation`. `git log origin/main..HEAD` on `cp/0-foundation`: `582cf62 Scaffold CP0 foundation: Laravel 12 kit, Horizon, Reverb, Filament`. No PR opened yet (step 8). `.claude/settings.local.json` (R14) still not created — see §7.

## §2 Step map (cycle 01 r3)
0. Record the rulings — [done] `0b5f0dd` pushed. Second half (create `.claude/settings.local.json`) — **not done**, blocked by a "Self-Modification" tool-permission guardrail CC cannot route around. ADR-002 records the intended content; Status column says "Written but not installed."
1. Environment check (r3) — [done] PHP 8.4.23, Composer 2.10.1, Node v26.4.0, npm 11.17.0, Herd 1.29.0 all present; PostgreSQL 18.4, Redis, and the mail catcher (port 2525) all reachable.
2. Repo bootstrap — [done in r1, not redone]
3. Scaffold on `cp/0-foundation` — [done] see §3 for the full list of what this covered.
4. Roles and auth — [not started] — next step, needs ADVISOR #3 (mandatory, auth boundary) before the first edit.
5. Money + Settings — [not started]
6. Seeders — [not started]
7. Layout shell + RTL guard — [not started]
8. CI + branch protection + PR — [not started]
9. Review + halt — [not started]

## §3 What changed this run
- **Step 0 (docs, `0b5f0dd`):** ADR-002 (permission allow-list, written not installed), PRD.md stack line + D-07 cell, CHECKPOINTS.md CP0 line, CLAUDE.md Stack line + `## Local environment` — all reflecting PHP 8.4 / PostgreSQL 18 / Redis 7 / Herd mail catcher.
- **Step 1:** all three local services confirmed reachable via PHP one-liners (no `psql`/`redis-cli` on PATH) — see docs/CYCLE-LOG.md VERIFICATION 00:27/01:xx for full quoted output.
- **Step 3 (code, `582cf62` on `cp/0-foundation`):** created `elearning`/`elearning_testing` Postgres databases; scaffolded the official Laravel Vue starter kit (Inertia 2, Vue 3, Tailwind 4, Fortify auth, Pest — verified, not React/WorkOS) in the scratchpad and merged it into the checkout without touching docs/, CLAUDE.md, CLAUDE.local.md*, `.git/`, `.claude/`; merged `.gitignore` by hand; removed `laravel/sail` per R13; wired `.env`/`.env.example` to the local services; set `composer.json` platform-check so Horizon resolves on Windows without pcntl/posix; installed and configured Horizon, Reverb, and Filament (panel at `/admin`); fixed a broken `reverb:install` (it hung on a Node prompt) by running `install:broadcasting --reverb --without-node --no-interaction` and then installing the Echo npm packages by hand; fixed two Pint line-ending failures and one real PHPStan type error in Horizon's stock config; got `composer test` green (39 tests, 136 assertions), `npm run build` green, and the site answering HTTP 200 via `herd link`. Full blow-by-blow with every command and quoted output is in docs/CYCLE-LOG.md (00:27 through 11:28).

## §4 Decisions
- CC + ADVISOR (00:23, ADVISOR #2): execute R14 as written (owner-invoked HOW-WE-WORK §8 allow-list mechanism); scope its rules narrowly (omit unsafe `git checkout <branch>`, add extra deny entries); gave the step 3 scaffold design. Adopted in full — except installing the file itself, which turned out to be blocked at a layer the advisor couldn't see (discovered during execution, not a gap in the advice).
- CC (own judgment, 01:31): removed `laravel/sail` from `composer.json` — direct application of R13 ("not referenced by any file in the repo"), not something the plan spelled out line-by-line.
- CC + ADVISOR (01:45, mandatory — plan–repo conflict): kept Laravel Framework 13.32.0 rather than force-downgrading to 12. Reason: Filament 5.8 doesn't require 13 specifically (`illuminate/contracts ^11.28|^12.0|^13.0`, checked), so this was the installer's own current default, not a forced dependency; R9's actual named properties (official kit, Inertia, Vue 3, Tailwind 4, Fortify, Pest) are all met regardless of major version; nothing downstream depends on the framework major version; Laravel 12 is in security-only maintenance now. Did not edit any doc beyond step 0's already-authorised, already-closed edits.
- CC (own judgment, 01:50): `config/horizon.php` — cast `env('APP_NAME', 'laravel')` to `(string)` to fix a real PHPStan type error in Horizon's own stock published config, rather than suppress it (PHPStan's own error output explicitly says not to add ignore comments or baselines).

## §5 Why stopping
Step 3 (a large unit) just closed clean. Stopping here by choice rather than starting step 4 (Roles and auth) in the same run — step 4 is its own substantial unit with a mandatory ADVISOR #3 (auth boundary) before its first edit, and this run is already long. Not a blocker, not a halt condition in the plan — just a sensible stopping point. `update` will resume at step 4.

## §6 Mismatches
- **Laravel version:** CLAUDE.md, PRD.md §10, CHECKPOINTS.md CP0, and PLAN.md R9 all say "Laravel 12" / "Inertia 2". The official installer, run today, produces **Laravel Framework 13.32.0** and Inertia 3 (`@inertiajs/vue3: ^3.0.0`, `@inertiajs/inertia-laravel: ^3.0`). CC kept 13 rather than force a downgrade — see §4 DECISION and docs/CYCLE-LOG.md ADVISOR 01:45 for the full reasoning and the discriminating check. CC did **not** edit these doc lines — step 0's plan-authorised edit list was already closed and didn't name the framework version, and CC does not self-authorise doc edits outside what a plan step names. The planner should correct "Laravel 12" / "Inertia 2" to 13/3 in the next PLAN.md revision (and PRD.md/CHECKPOINTS.md/CLAUDE.md whenever next authorised) so the docs stop being wrong on this point.
- **R14 (permission allow-list):** written into ADR-002 but not installed — CC is blocked from writing its own permission-settings file. This is a capability gap, not a scope decision; see Owner action 1.
- **`reverb:install` behavior:** hung/had to be killed rather than completing non-interactively despite no explicit `--interaction` flag being passed against it (only `install:broadcasting` was run with `--no-interaction`). Worth the planner/owner knowing for any future `*:install` Artisan command on this stack: pass `--no-interaction` explicitly even when the plan doesn't spell it out, since some of these commands prompt by default.

## §7 Next step and owner actions
Owner action 1: install the R14 permission allow-list yourself, since CC is blocked from writing its own permission-settings file (a guardrail below the plan/owner-loop layer — this is by design, not a bug to work around). **Recommended:** create `.claude/settings.local.json` in `C:\project elearning` with exactly the content quoted in docs/DECISIONS.md ADR-002 (copy the JSON shape described there: `{"permissions":{"allow":[...],"deny":[...]}}`), save it, then restart this Claude Code session before the next `update` so the rules are guaranteed loaded. If you'd rather not, that's fine too — CC continues under the existing interactive-approval mode either way; reply `update` when done (or just reply `update` to continue without it).

## §8 Programme board
none

## Deferred (out of v1 scope — do not build)
- Lesson packs / subscriptions / credits
- Group classes
- Courses / self-paced content
- Learning plans, homework, assessments
- B2B organisations / tenancy
- Multi-currency
- WhatsApp notifications
- Meilisearch
