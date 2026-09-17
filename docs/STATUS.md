# STATUS — cycle 01 r5 — written 2026-09-17 16:35
Tests: 89 (was 84) · Advisor: consulted 4 times this cycle (7 times total across cycle 01) · Review: n/a

## §1 Git state
`main` at this commit on `origin/main` once pushed. Branch `cp/0-foundation` at `f32ede3`, pushed to `origin/cp/0-foundation`. `git log origin/main..HEAD` on `cp/0-foundation`: `f32ede3` "Add CP0 seeders: curricula, subjects, price bands, settings, admin", `84a81c7` "Add curricula/subjects/price_bands schema, models and factories", `71be91e` "Add settings table, SettingsService and Settings facade", `09be70a` "Add Money value object (integer fils, no float leakage)", plus step 4's three commits, two merge commits and `582cf62` from step 3. No PR opened yet (step 8, not started). `.claude/settings.local.json` (R14) still not created — see §7 Owner action 1 (carried).

## §2 Step map (cycle 01 r5)
0. Record the rulings — [done] `8e67d3c`, `8bd220e` on `main`.
0b. Herd rule (R18) — [done] `8bd220e`, same commit.
1. Environment check — [done in r3]. Not redone.
2. Repo bootstrap — [done in r1]. Not redone.
3. Scaffold — [done in r3, `582cf62`]. Not redone.
4. Roles and auth — [done] `a38827c`, `686f818`, `c2bcc79` on `cp/0-foundation`.
5. Money + Settings — [done] `09be70a`, `71be91e` on `cp/0-foundation`.
6. Seeders — [done] `84a81c7`, `f32ede3` on `cp/0-foundation`. Full detail in §3.
7. Layout shell + RTL guard — [not started] — next step.
8. CI + branch protection + PR — [not started]
9. Review + halt — [not started]

## §3 What changed this run
- **Steps 4/5 (prior boundaries this run, unchanged):** see earlier cycle 01 r5 STATUS.md revisions or CYCLE-LOG.md for detail; not repeated here.
- **Step 6 (code, two commits on `cp/0-foundation`):** `curricula` (`code`/`name`/`sort`, `code` a DB-level enum backed by new `App\Enums\CurriculumCode`), `subjects` (`name`/`slug` unique/`sort`, global — not curriculum-specific, per DATA_MODEL), `price_bands` (`curriculum_id` FK restrict-on-delete, `level_tier` enum backed by new `App\Enums\LevelTier`, `min_rate`/`max_rate` cast to `App\Support\Money` — its first real use on a column — `effective_from` date, unique on curriculum+tier+effective_from) migrations, models and factories. `CurriculumCode::tiers()` is the single source of which level tiers exist for which curriculum code (GCSE→lower_secondary+exam_1, A_LEVEL→exam_2, IB_MYP→lower_secondary+exam_1, IB_DP→exam_2, CBSE→all three — the UK/IB tracks are already split by code, CBSE is the only unsplit one); `LevelTier::band()` holds PRD §3's illustrative fils bands per tier. Five seeders (`CurriculumSeeder`, `SubjectSeeder`, `PriceBandSeeder`, `SettingsSeeder`, `AdminUserSeeder`) orchestrated from `DatabaseSeeder`, every one idempotent via `updateOrCreate` on its natural key; the kit's default `test@example.com` row removed (no demo tutors/parents this step — `TutorProfile` doesn't exist until CP1). `SettingsSeeder` iterates `config('settings.defaults')` — the same list `SettingsService::get()` falls back to from step 5, one source, not duplicated. `AdminUserSeeder` reads `ADMIN_EMAIL`/`ADMIN_PASSWORD` via new `config/seeding.php` (never a raw `env()` call, so `config:cache` keeps working) and **throws if either is empty** — no default password (R10). `.env.example` gained the two placeholder keys (confirmed tracked in git before relying on it). `composer test`: Pint passed, PHPStan 0 errors, Pest **89/89, 222 assertions** (was 84/206, +5 tests/+16 assertions) — `tests/Feature/DatabaseSeederTest.php` seeds twice and asserts row counts don't change, since step 8's CI runs `composer test` not `migrate:fresh --seed`. Destructive proof, local `elearning` DB only (name-only confirmed via `config:show database.connections.pgsql.database`, no credentials printed): `php artisan migrate:fresh --seed --no-interaction` — all 10 migrations + 5 seeders DONE (this drops the step-4 tinker tutor, fake data, expected). `php artisan db:show --counts --no-interaction` quoted: **curricula 5, subjects 25, price_bands 9, settings 20, users 1** — exactly matching the plan's row-count requirement. `npm run build` correctly skipped, no Vue/assets touched. Full detail with every command and quoted output in docs/CYCLE-LOG.md (16:05 through 16:35).
- **Two self-caught PHPStan issues, fixed before commit, not suppressed:** (1) `$curriculum->code->tiers()` failed type-checking because Larastan doesn't infer an enum type from a `casts()`-method array the way it does the legacy `$casts` property — fixed with explicit `@property CurriculumCode $code` (etc.) docblocks on `Curriculum`/`PriceBand`, matching the convention `User` already uses. (2) Faker's `words($n, true)` stub types as `array|string` regardless of the literal `true` argument — `SubjectFactory` rewritten to concatenate two `fake()->unique()->word()` calls instead.

## §4 Decisions
- CC + ADVISOR (16:05, pricing-config judgment call per §11 — not one of this cycle's plan-mandated advisor points): the curriculum×level_tier price-band mapping (9 rows: GCSE 2, A_LEVEL 1, IB_MYP 2, IB_DP 1, CBSE 3), read from PRD §3's table + the five curriculum codes, confirmed correct and **not an Owner action** since the plan's own step 6 text ("only the tiers that exist for that curriculum") delegates this inference to CC. `CurriculumCode::tiers()` adopted as the single source, not duplicated in the seeder or (later) CP1's onboarding derivation.
- CC (own judgment, step 6): the two PHPStan fixes above (property docblocks for enum-cast inference, Faker `words()` rewrite) — straightforward root-cause fixes, not requiring a fresh advisor consultation.

## §5 Why stopping
Step 6 (Seeders) is done — its own self-contained unit, with a judgment-call advisor consultation of its own (the price-band mapping). Stopping here by choice before starting step 7 (Layout shell + RTL guard), a fresh unit. Not a blocker, not a plan halt condition.

## §6 Mismatches
- **Local services are not currently Herd-managed** (carried from r4/r5, unchanged). `postgres.exe` and `redis-server.exe` are still running as processes started directly in an earlier run, not through Herd's service manager. Confirmed reachable again at the start of this run via `fsockopen` checks on 5432/6379 (both `true`) before any work began, per R18. Owner action 2 below, still not urgent.

## §7 Next step and owner actions
Owner action 1 (carried from r3/r4/r5, still open): install the R14 permission allow-list yourself — CC is blocked from writing its own permission-settings file by design. Create `.claude/settings.local.json` in `C:\project elearning` with the content quoted in docs/DECISIONS.md ADR-002, then restart this Claude Code session before the next `update`. Optional — CC continues under interactive approval either way.

Owner action 2 (carried, still not urgent): at a convenient point, open the Herd app and use its Services panel to properly (re)start PostgreSQL and Redis, replacing the two manually-started processes with Herd-managed ones. Nothing needs to happen before the next `update` — both services are confirmed reachable and CC's tests pass against them.

No owner action is required to proceed to step 7 (Layout shell + RTL guard) — reply `update` when ready and CC will continue from there.

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
