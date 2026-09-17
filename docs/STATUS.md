# STATUS — cycle 01 r6 — written 2026-09-17 18:10
Tests: 89 (unchanged from step 6) · Advisor: consulted 0 times this run so far (8 times total across cycle 01) · Review: n/a

## §1 Git state
`main` at this commit on `origin/main` once pushed. Branch `cp/0-foundation` at `230b794`, pushed to `origin/cp/0-foundation`. `git log origin/main..HEAD` on `cp/0-foundation`: `230b794` "Convert physical Tailwind utilities to logical properties (RTL-ready)", `fd79513` "Add RTL guard: <html dir> from locale, scripts/rtl-check.sh in composer test", `f32ede3` "Add CP0 seeders...", `84a81c7` "Add curricula/subjects/price_bands schema...", `71be91e`, `09be70a`, plus step 4's three commits, merge commits and `582cf62` from step 3. No PR opened yet (step 8, in progress this run). `.claude/settings.local.json` (R14) still not created — see §7 Owner action 1 (carried).

## §2 Step map (cycle 01 r6)
0. Record the rulings — [done] `8e67d3c`, `8bd220e` on `main` (r5); r6's own PLAN.md commit is `fa15151`.
0b. Herd rule (R18) — [done], same as above.
1. Environment check — [done in r3]. Not redone.
2. Repo bootstrap — [done in r1]. Not redone.
3. Scaffold — [done in r3, `582cf62`]. Not redone.
4. Roles and auth — [done in r5] `a38827c`, `686f818`, `c2bcc79`.
5. Money + Settings — [done in r5] `09be70a`, `71be91e`.
6. Seeders — [done in r5] `84a81c7`, `f32ede3`.
7. Layout shell + RTL guard — [done] `fd79513`, `230b794` on `cp/0-foundation`. Full detail in §3.
8. CI + branch protection + PR — [in progress this run] — next.
9. Review + halt — [not started] — this cycle's only planned halt (owner GO to merge).

## §3 What changed this run
- **r6 arrival:** PLAN.md found modified on disk at the start of this `update`, committed as `fa15151` "PLAN.md cycle 01 r6" before any other work. r6 confirms r5's steps 0b/4/5/6 closed exactly as this session's own record (no correction needed), adds the standing **no-stop-between-steps** rule (halts now exist only where the plan names one — step 9 — where a blocker or R18 outage forces one, or the session limit intervenes), and states Postgres/Redis are Herd-managed again — verified via `herd.bat services:list` (read-only) showing both `running`, dropping the prior §6 carry-over.
- **Step 7 (code, two commits on `cp/0-foundation`):** `resources/views/app.blade.php` gains `dir="{{ app()->getLocale() ... }}"` (always `ltr` in v1, locale-driven for later) — confirmed in served HTML via `curl`. `scripts/rtl-check.sh` greps `resources/` for physical Tailwind utilities and is wired into `composer test` as `@rtl:check`; the plan's required fail→pass proof was run against a temporary component (`ml-4` → fails quoting file:line, `ms-4` → passes), the file removed immediately after, never committed. Bulk-converted every matched physical utility to its logical equivalent across 30 files (`ml-`/`mr-`/`pl-`/`pr-` → `ms-`/`me-`/`ps-`/`pe-`, `left-`/`right-` → `start-`/`end-`, `text-left`/`text-right` → `text-start`/`text-end`), including the `PasswordInput.vue` `pr-10` flagged as a known violation in an earlier cycle. **R9 exclusion applied and disclosed here, not silently done:** the animate plugin's `slide-in-from-{left,right}`/`slide-out-to-{left,right}` keyframe utilities have no logical equivalent and are deliberately paired with Radix's own physical-only `data-side`/`data-motion` attributes in 5 generated components (Tooltip/DropdownMenu(Sub)/Select/NavigationMenu "Content"); `rtl-check.sh` strips only those specific tokens before matching, so a real violation elsewhere on the same line would still surface — no whole file is blanket-excluded. `npm run build` green (3361 modules, no errors). Manual browser check of `/login` and `/` given the scale of the bulk edit: both render correctly; `curl` confirmed `/register`, `/tutor/register`, `/dashboard` routing unaffected. `composer test`: Pint passed, PHPStan 0 errors, RTL check passed, Pest **89/89, 222 assertions** (unchanged — this step added no new Pest tests per its own file list; the fail/pass proof is the VERIFICATION entry itself). Full detail in docs/CYCLE-LOG.md (18:10).
- **One self-caught bug in this step's first pass, fixed before commit:** the initial blind `sed` conversion also renamed the 5 `slide-in-from-left`/`slide-in-from-right` occurrences above to nonexistent `slide-in-from-start`/`slide-in-from-end` classes, which would have silently broken those components' enter/exit animations (no error, just a missing animation). Caught by reading every diff before staging, reverted to the correct physical names before commit — not caught by any automated check, a reminder that `git diff` review before every commit is load-bearing, not a formality.

## §4 Decisions
- CC (own judgment, step 7): the R9 exclusion for the animate plugin's fixed `slide-in-from-*`/`slide-out-to-*` keyframe utility names — genuinely inconvertible (no logical equivalent exists in the plugin, and they're paired with Radix's own physical `data-side`/`data-motion` attributes), scoped as narrowly as possible (token-level, not file-level) in `scripts/rtl-check.sh` rather than excluding whole files from the grep.

## §5 Why stopping
Not stopping — r6's new standing rule says do not stop between steps. Continuing directly into step 8 (CI + branch protection + PR) in this same run. This STATUS.md write is the step-7 boundary record §6 of HOW-WE-WORK requires, not a halt.

## §6 Mismatches
- **Local services are now Herd-managed again** (resolved this run, was carried since r4/r5): confirmed via `herd.bat services:list` (read-only) — PostgreSQL and Redis both show `running` under Herd's own service manager, not the manually-started processes from earlier cycles. No longer a mismatch; nothing to carry forward.

## §7 Next step and owner actions
Owner action 1 (carried from r3/r4/r5/r6, still open): install the R14 permission allow-list yourself — CC is blocked from writing its own permission-settings file by design. Create `.claude/settings.local.json` in `C:\project elearning` with the content quoted in docs/DECISIONS.md ADR-002, then restart this Claude Code session before the next `update`. Optional — CC continues under interactive approval either way.

No other owner action required right now — CC is continuing into step 8 (CI + branch protection + PR) this same run per r6. Step 9 will be this cycle's first real halt: an owner GO to merge PR #1.

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
