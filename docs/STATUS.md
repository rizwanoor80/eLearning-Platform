# STATUS — cycle 01 r5 — written 2026-09-17 15:58
Tests: 84 (was 59) · Advisor: consulted 3 times this cycle (6 times total across cycle 01) · Review: n/a

## §1 Git state
`main` at this commit on `origin/main` once pushed. Branch `cp/0-foundation` at `71be91e`, pushed to `origin/cp/0-foundation`. `git log origin/main..HEAD` on `cp/0-foundation`: `71be91e` "Add settings table, SettingsService and Settings facade", `09be70a` "Add Money value object (integer fils, no float leakage)", `c2bcc79` "Add tutor registration/onboarding pages and the role/auth test suite", `686f818` "Add tutor entry point, role-based gates and login/verify redirects", `a38827c` "Add Role enum, users.role column, User model/factory support", plus two merge-from-main commits and `582cf62` (step 3, prior run). No PR opened yet (step 8, not started). `.claude/settings.local.json` (R14) still not created — see §7 Owner action 1 (carried).

## §2 Step map (cycle 01 r5)
0. Record the rulings — [done] `8e67d3c`, `8bd220e` on `main`.
0b. Herd rule (R18) — [done] `8bd220e`, same commit.
1. Environment check — [done in r3]. Not redone.
2. Repo bootstrap — [done in r1]. Not redone.
3. Scaffold — [done in r3, `582cf62`]. Not redone.
4. Roles and auth — [done] `a38827c`, `686f818`, `c2bcc79` on `cp/0-foundation`.
5. Money + Settings — [done] `09be70a`, `71be91e` on `cp/0-foundation`. Full detail in §3.
6. Seeders — [not started] — next step.
7. Layout shell + RTL guard — [not started]
8. CI + branch protection + PR — [not started]
9. Review + halt — [not started]

## §3 What changed this run
- **Step 4 (prior boundary this run, unchanged):** full role/auth system — see cycle 01 r5's earlier STATUS.md revision or CYCLE-LOG.md (15:15 entries) for detail; not repeated here.
- **Step 5 (code, two commits on `cp/0-foundation`):** `App\Support\Money` — a `final readonly class` wrapping a single `int $fils`; `Money::fils(int)` and `Money::fromDecimalString(string)` constructors (deliberately no `fromFloat()` — the only float-adjacent path is string parsing, which rejects more than two decimal places, non-numeric input, and a leading minus via regex); `add`/`subtract`/`multiply`/`percentage` (half-up rounding on fils: `intdiv($fils*$pct+50,100)`)/`isZero`/`isNegative`/`equals`/`compare`/`format` (currency code as a caller-supplied parameter, e.g. `"AED 120.00"`, negative amounts get a leading sign since `subtract()` is allowed to go negative)/`jsonSerialize`; implements `Castable` via `App\Support\Casts\MoneyCast` so a future Eloquent price column can cast to/from `Money` directly. `settings` table migration: `key` (unique), `group` as a genuine DB-level enum (`platform|site|mail|features`, backed by `App\Enums\SettingGroup`), `value` (json), `updated_by` (nullable FK to `users`, `nullOnDelete`), `updated_at` only — no `created_at`, matching DATA_MODEL v1.2 literally. `App\Models\Setting` (no Eloquent timestamps, `group`/`value`/`updated_at` cast). `App\Services\Settings\SettingsService` + `App\Support\Facades\Settings`: `get(key, default)` (one `Cache::rememberForever('settings.{key}', ...)` per key, falls back to `config('settings.defaults.{key}')` when no row exists), `set(key, value, group, by)` (writes the DB row then busts the cache — never write-through-cache-first), `forget(key)`, and a typed `money(key)` reader for fils-valued keys (`payout_min`, etc.) so callers never do `Money::fils(Settings::get(...))` ad hoc. `config/settings.php` holds the full platform-group key defaults DATA_MODEL v1.2 lists (`commission_pct`, `trial_discount_pct`, `payout_min`, `vat_pct`, `currency_code`, all 20 platform keys) — this is `get()`'s fallback source, and step 6's seeder will read this same array rather than duplicating the list; **no rows are seeded this step**, per the plan's own scope note that step 6 owns seeding. `composer test`: Pint passed, PHPStan 0 errors, Pest **84/84, 206 assertions** (was 59/165, +25 tests/+41 assertions). `php artisan migrate --no-interaction` run against the local `elearning` dev database, not just the test DB. `npm run build` correctly skipped — no Vue/asset files touched this step. Full detail with every command and quoted output in docs/CYCLE-LOG.md (15:41 through 15:58).
- **One self-caught bug, fixed before commit:** `Money` originally declared both a static factory `fils(int): self` and an instance getter `fils(): int` — PHPStan caught the duplicate-method-name error on the first `composer test` run; the getter was renamed to `toFils()` throughout (`Money.php`, `MoneyCast.php`, both new test files) and the suite re-run green. Not a suppressed warning — a real bug, fixed at the root.

## §4 Decisions
- CC + ADVISOR (15:41, mandatory — money-handling code — even though PLAN.md r5's step 5 text doesn't itself name an ADVISOR requirement for this specific step): the full `Money`/`Settings` design — half-up percentage rounding, no `fromFloat()`, cache-then-bust (never write-through), `config('settings.defaults.*')` as the single source step 5's fallback and step 6's seeder both read, typed `Settings::money()` reader, deferring all seeding to step 6. Adopted in full.
- CC (own judgment, step 5): renamed `Money`'s instance getter from `fils()` to `toFils()` after PHPStan caught the collision with the static constructor of the same name — a straightforward fix, not requiring a fresh advisor consultation.

## §5 Why stopping
Step 5 (Money + Settings) is done — a self-contained unit with its own mandatory advisor consultation. Stopping here by choice before starting step 6 (Seeders), a fresh unit. Not a blocker, not a plan halt condition.

## §6 Mismatches
- **Local services are not currently Herd-managed** (carried from r4/r5, unchanged). `postgres.exe` and `redis-server.exe` are still running as processes started directly in an earlier run, not through Herd's service manager. Confirmed reachable again at the start of this run via `fsockopen` checks on 5432/6379 (both `true`) before any work began, per R18. Owner action 2 below, still not urgent.

## §7 Next step and owner actions
Owner action 1 (carried from r3/r4/r5, still open): install the R14 permission allow-list yourself — CC is blocked from writing its own permission-settings file by design. Create `.claude/settings.local.json` in `C:\project elearning` with the content quoted in docs/DECISIONS.md ADR-002, then restart this Claude Code session before the next `update`. Optional — CC continues under interactive approval either way.

Owner action 2 (carried, still not urgent): at a convenient point, open the Herd app and use its Services panel to properly (re)start PostgreSQL and Redis, replacing the two manually-started processes with Herd-managed ones. Nothing needs to happen before the next `update` — both services are confirmed reachable and CC's tests pass against them.

No owner action is required to proceed to step 6 (Seeders) — reply `update` when ready and CC will continue from there.

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
