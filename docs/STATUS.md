# STATUS — cycle 01 r5 — written 2026-09-17 15:20
Tests: 59 (was 39) · Advisor: consulted 2 times this cycle (5 times total across cycle 01) · Review: n/a

## §1 Git state
`main` at `8bd220e` on `origin/main`. Branch `cp/0-foundation` at `c2bcc79`, pushed to `origin/cp/0-foundation`. `git log origin/main..HEAD` on `cp/0-foundation`: `c2bcc79` "Add tutor registration/onboarding pages and the role/auth test suite", `686f818` "Add tutor entry point, role-based gates and login/verify redirects", `a38827c` "Add Role enum, users.role column, User model/factory support", plus two merge-from-main commits and `582cf62` (step 3, prior run). No PR opened yet (step 8, not started). `.claude/settings.local.json` (R14) still not created — see §7 Owner action 1 (carried).

## §2 Step map (cycle 01 r5)
0. Record the rulings — [done] `8e67d3c`, `8bd220e` on `main`.
0b. Herd rule (R18) — [done] `8bd220e`, same commit.
1. Environment check — [done in r3]. Not redone.
2. Repo bootstrap — [done in r1]. Not redone.
3. Scaffold — [done in r3, `582cf62`]. Not redone.
4. Roles and auth — [done] `a38827c`, `686f818`, `c2bcc79` on `cp/0-foundation`. Full detail in §3.
5. Money + Settings — [not started] — next step.
6. Seeders — [not started]
7. Layout shell + RTL guard — [not started]
8. CI + branch protection + PR — [not started]
9. Review + halt — [not started]

## §3 What changed this run
- **Step 0/0b (docs, `8e67d3c`/`8bd220e` on `main`):** committed PLAN.md r5 (adds R18 — Herd is owner-operated) and the CLAUDE.md line implementing it.
- **Step 4 (code, three commits on `cp/0-foundation`):** `App\Enums\Role`; `users.role` migration; `User` now implements `MustVerifyEmail` and `FilamentUser` (`canAccessPanel()` checks `role === Admin`), `role` cast but deliberately kept out of `#[Fillable(...)]`; `UserFactory` gets `tutor()`/`admin()` states. `CreateNewUser` (Fortify's own `/register`) forces `role = AccountOwner`; a new, separate `TutorRegisteredUserController` backs `/tutor/register` and forces `role = Tutor` — on both entry points any client-supplied `role` in the request is simply never read. `App\Support\RoleRedirect` maps a user's role to their landing route, bound into Fortify's `LoginResponse` and `VerifyEmailResponse` contracts so the one shared `/login` route sends each role to the right place. Two Gates (`access-parent-area`, `access-tutor-area`) in `AppServiceProvider`, applied as route middleware after `auth`/`verified` (so an unverified user is sent to `/email/verify`, not 403'd). No admin registration route exists — tested. `TutorRegister.vue` and a `TutorOnboarding.vue` placeholder page. Full test matrix: role-from-input rejected on both entry points, verified-gate ordering, all six cross-role 403 pairs, login redirects per role. `composer test`: Pint passed, PHPStan 0 errors, Pest **59/59, 165 assertions** (was 39/136). Full detail with every command and quoted output in docs/CYCLE-LOG.md (14:08 through 15:15).
- **Mid-run:** PLAN.md r5 arrived while step 4 was in progress (owner ruling on Herd, R18) — committed and applied per protocol before continuing; see §4 and §6.

## §4 Decisions
- CC + ADVISOR (14:08, mandatory — auth boundary): the full step-4 design (role decided by route not input, response-contract redirects, Gate-based area checks, test matrix, mail-catcher approach, stack gotchas). Adopted in full; every gotcha the advisor flagged came up during execution exactly as predicted.
- CC (own judgment, throughout step 4): several small stack fixes — Pint line-ending fixes on two artisan-generated files, a real PHPStan type error in Horizon's own stock `config/horizon.php` (cast, not suppressed), installing `@laravel/echo-vue`/`laravel-echo`/`pusher-js` by hand after `install:broadcasting --without-node` left the import unresolved, a duplicate `BROADCAST_CONNECTION` line in `.env` from the same command, cleaned up.
- CC, **owning a mistake plainly** (15:15): the Herd instability chased earlier this run (multiple crashes, `postgres.exe`/`redis-server.exe` PATH failures) was most likely CC's own doing — PLAN.md r5's R18 explains that launching Herd from CC's own shell ties its console to that shell and breaks it when the shell ends. CC had read it as an independent Herd bug at the time; it more likely wasn't. CC does not launch, restart or "clean-relaunch" Herd or its services again (R18, step 0b, CLAUDE.md).
- CC (R16 workaround, before R18 existed): when PostgreSQL/Redis were unreachable at the start of this run, CC removed a confirmed-stale `postmaster.pid` and started `postgres.exe`/`redis-server.exe` directly (full paths, correct data directories), bypassing Herd's broken spawner, rather than wait indefinitely or risk `services:delete` (which would have wiped the `elearning`/`elearning_testing` databases). This got step 4 unblocked without data loss. It also will not happen again under R18 — see §6.

## §5 Why stopping
Step 4 (Roles and auth) is done — a large unit on its own, made larger by the mid-run PLAN.md r5 arrival and the Herd/R18 detour. Stopping here by choice before starting step 5 (Money + Settings), a fresh independent unit. Not a blocker, not a plan halt condition.

## §6 Mismatches
- **Local services are not currently Herd-managed.** `postgres.exe` and `redis-server.exe` are running right now as processes CC started directly this run (before R18 existed), not through Herd's service manager. They are working (all 59 tests pass against them), but they are not what `herd.bat services:list` would call "running" — and per R18, if either dies, CC will **halt** and ask the owner to start it from the Herd app, not restart it itself. **Owner action 2** below explains what to do about this now, at your convenience — nothing is broken today, but the current arrangement should not be treated as permanent.
- **Laravel 13 vs "Laravel 12" in docs (from the previous run, now closed):** this was disclosed in cycle 01 r4's STATUS.md §6; r5's PLAN.md text and ADR-003 already reflect Laravel 13/Inertia 3 correctly, so this is resolved, not carried forward as an open mismatch.

## §7 Next step and owner actions
Owner action 1 (carried from r3/r4, still open): install the R14 permission allow-list yourself — CC is blocked from writing its own permission-settings file by design. Create `.claude/settings.local.json` in `C:\project elearning` with the content quoted in docs/DECISIONS.md ADR-002, then restart this Claude Code session before the next `update`. Optional — CC continues under interactive approval either way.

Owner action 2 (new, not urgent): at a convenient point, open the Herd app and use its Services panel to properly (re)start PostgreSQL and Redis — this will replace the two manually-started processes from this run with Herd-managed ones and is the cleaner state going forward. Nothing needs to happen before the next `update`; CC's tests all pass against the current processes and will keep working until/unless one of them dies. If you'd also like to independently confirm the tutor-registration verification email arrived, open Herd → Mail and look for a message with subject "Verify your email address" — this is non-blocking, CC already confirmed the SMTP handshake succeeded server-side (docs/CYCLE-LOG.md, 15:15).

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
