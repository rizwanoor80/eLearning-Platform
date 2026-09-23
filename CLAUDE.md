# CLAUDE.md — project-elearning

Read docs/HOW-WE-WORK.md, docs/PROJECT_BRIEF.md, docs/PRD.md, docs/DATA_MODEL.md, docs/CHECKPOINTS.md, docs/PLAN.md, docs/STATUS.md and the tail of docs/CYCLE-LOG.md at the start of every session, in that order. HOW-WE-WORK.md is binding. Work only on what the current docs/PLAN.md authorises; CHECKPOINTS.md is the backlog the plans are cut from, not a licence to run ahead.

## Stack
Laravel 13 · PHP 8.4 · PostgreSQL 18 · Redis · Horizon · Reverb · Inertia 3 + Vue 3 (D-01) · Tailwind 4 · Filament 5 · Pest.
Video via `App\Services\Video\VideoRoomProvider` (driver: Daily). Payments via `App\Services\Payments\PaymentGateway` (driver: see D-02).

## Repository
- The only checkout: `C:\project elearning` — the path contains a space; quote it in every shell command.
- Remote `origin`: `https://github.com/rizwanoor80/eLearning-Platform.git` — **public** repository, default branch `main`. Nothing that must stay private ever enters it: no secrets, no `.env`, no `CLAUDE.local.md`, no real names, emails, phones or documents (see HOW-WE-WORK §8).

## Local environment
Native Windows via Laravel Herd Pro — no Docker, no Sail, no WSL (D-07 / ADR-001; Docker Desktop crash-loops here, see docker/desktop-feedback #460). PHP 8.4 everywhere (local, CI, servers) — no 8.3 fallback. Composer/Node from Herd; PostgreSQL 18 and Redis on localhost, plus a Herd mail catcher. Horizon is installed but never run locally (`php artisan queue:work redis` in dev); Reverb likewise may be deferred to CP7 if it cannot boot on Windows. Local service details live only in `CLAUDE.local.md` under `## Local services` and in `.env` — both git-ignored; never quote a password, connection string or password-bearing URL in any log entry, STATUS.md, commit, report or PR.
Herd and its services (PostgreSQL, Redis, mail) are started and stopped only by the owner in the Herd app. Never launch, restart or "clean-relaunch" Herd or a service from this shell; if a service is down, halt with an Owner action.

## Commands
- `composer test` → full Pest suite. Must be green before any checkpoint closes.
- `php artisan ledger:verify` → asserts every lesson's ledger sums to zero. Must be green before any checkpoint from CP5 onward closes.
- `php artisan recurring:generate` / `recurring:charge` → the two weekly-slot jobs. Both must be safe to run twice.
- `npm run build` must succeed before a checkpoint closes.
- Every artisan, composer, npm/npx and gh command runs with its non-interactive flag (`--no-interaction`/`-n`, `--yes`, `--no-input`). Some `*:install` commands prompt by default — never assume.
- A single command still running after 10 minutes is a stall: stop it, log a BLOCKER naming the command, work around it. Never wait on it.

## Scope guard
v1 is: 1-on-1 60-minute lessons · tutor vetting · search + match request · trial lesson · single booking · recurring weekly slot with saved-card auto-charge · escrow ledger · lesson room · progress + trial reports emailed + parent portal · messaging · reviews · report button · two-dial disputes · admin.
NOT in v1: courses, group classes, packs, subscriptions, credits, learning plans, homework, assessments, multi-currency, B2B organisations, mobile apps, AI features, open tutor registration.
A weekly slot is NOT a subscription or a pack: it is a standing reservation where each lesson is charged individually. Do not introduce a credit balance to implement it.
If a task or a "helpful" abstraction would introduce one of these, do not build it. Add a line under `## Deferred` in docs/STATUS.md and continue.

## Domain invariants (never violate, never "temporarily" bypass)
1. **Money moves only through `LedgerService`.** `ledger_entries` is append-only: no updates, no deletes, no direct inserts outside the service. Every lesson's entries sum to zero after each transaction.
2. **Lesson status changes only through `LessonStateMachine::transition()`.** No `$lesson->status = ...` anywhere else. Every transition has a feature test.
3. **All money is integer fils.** No floats, no `round()` on currency, no arithmetic outside `Money` value object.
4. **All timestamps stored UTC.** Convert at the edge (request in, response out) using the user's `timezone`. Availability rules are the single exception (stored in tutor local time, converted when computing slots).
5. **A tutor is bookable only via `TutorProfile::bookable()` scope** (`approved` AND permit not expired). Search, booking, and match suggestions must all use this scope.
6. **Price is validated against the price band at booking time**, and `price`, `commission_pct`, `commission_amount`, `tutor_amount` are frozen on the lesson row. Settings changes never touch existing lessons.
7. **Minors never have logins.** Any feature that emails, messages, or shows data to a learner directly is a bug. Everything goes to `learners.account_user_id`.
8. **Conversations exist only after a paid booking** between that account and tutor. Contact details are masked until `first_lesson_completed_at` is set.
9. **Document uploads go to a private disk** with signed, expiring URLs. Never public storage.
10. **No group/course/organisation concepts in the schema.** A lesson has exactly one learner and one tutor. Do not add nullable "group_id" or "organization_id" columns "for later".
11. **Policy is frozen on the lesson.** `commission_pct`, `cancel_window_hours`, and grace minutes are copied at creation and read from the lesson row, never from settings, when evaluating that lesson.
12. **Trial = first non-cancelled lesson for a learner–tutor pair, decided by `BookLesson`, enforced by a DB partial unique index.** Never let the client choose `type`.
13. **Refund-to-parent and pay-to-tutor are independent decisions.** `LedgerService::settle()` takes both percentages; nothing else may infer one from the other.
14. **Recurring jobs are idempotent.** Generation keys on `(recurring_slot_id, starts_at)`; charging uses the lesson id as the gateway idempotency key. Running either job twice must be a no-op.
15. **Never store card numbers.** `payment_methods` holds the gateway token, brand, last four, expiry — nothing else.
16. **Integration credentials and provider choice live in the admin registries** (`payment_gateways`, `video_providers` — PRD §12): encrypted at rest, masked in the UI, never logged, never in the repo; drivers are resolved from the active row, never hard-wired.

## Code conventions
- Business logic in `app/Actions` (single-purpose, invokable) and `app/Services`. Controllers are thin; Form Requests validate; Policies authorise.
- Enums (`app/Enums`) for every status/type column. No magic strings.
- Side effects (emails, room creation, ledger release) fire from Events → queued Listeners. Never send mail synchronously in a request.
- Factories for every model. Seeders: curricula, subjects, price bands, settings, one admin user, 5 demo tutors, 2 demo parents.
- Feature tests for every user-facing flow; unit tests for `Money`, `SlotCalculator`, `LedgerService`, `LessonStateMachine`.
- Migrations are forward-only once CP0 is merged. Fix with a new migration.
- Naming: tables plural snake_case, models singular, Actions as verbs (`BookLesson`, `SubmitProgressReport`).
- **RTL-ready layout:** Tailwind logical properties only (`ms-`, `me-`, `ps-`, `pe-`, `start-`, `end-`, `text-start`). CI greps for `ml-|mr-|pl-|pr-|left-|right-|text-left|text-right` and fails. `<html dir>` comes from the user's locale (always `ltr` in v1).
- Tutor balance is never a single stored number. Always compute the five buckets (pending, on hold, available, processing, paid to date) from lessons + ledger.

## Checkpoint protocol
- A plan cycle usually equals one checkpoint or one slice of it. Branch per cycle: `cp/<n>-<slug>`. PR to `main`.
- Before opening the PR: `composer test` green with the literal count, `ledger:verify` green (CP5+), `npm run build` green, RTL grep green, `git log origin/main..HEAD` quoted in the log, STATUS.md rewritten.
- Every PR gets the fresh-subagent adversarial review (REVIEW entry with numbered verdicts) before merge. Merge only under the plan's merge rule or Rizwan's GO. Fix loop cap: 2.
- Do not start the next checkpoint until the current PR is merged and the post-merge record is on `main`.
- Never run `git push --force`, `git reset --hard`, `git checkout .`, `php artisan migrate:fresh` against anything but local, or delete branches other than a merged `hold/<step>`.

## Frozen files (full protocol per HOW-WE-WORK §11 once each is first merged)
- `app/Services/Ledger/LedgerService.php` — frozen after CP5
- `app/Services/Lessons/LessonStateMachine.php` — frozen after CP3
- `app/Services/Payments/*` — frozen after CP5
- `database/migrations/*_create_ledger_entries_table.php` — frozen after CP5

### Owner loop (standing protocol — no exceptions; full text in docs/HOW-WE-WORK.md)
The owner, the planning seat (a separate Claude session that writes only docs/PLAN.md) and
Claude Code work in one fixed loop: owner says "go ahead" → planner writes docs/PLAN.md into the
checkout at C:\project elearning, uncommitted → owner tells Claude Code `update` → Claude Code works,
logs, writes docs/STATUS.md → owner asks the planner "where are we?" → repeat.
1. On every `update`: git fetch; fast-forward main; read docs/PLAN.md; if newer than the
   committed one, commit it as "PLAN.md cycle NN rN" before any other work; refuse a revision
   that already has an END in docs/CYCLE-LOG.md; execute from the first unfinished step. No new
   work → NOTE entry + STATUS.md, never silence.
2. One window per checkout, one `update` at a time, no worktrees. A window runs many sessions in
   sequence (rule 13); that is required, not an exception. Independence comes from a fresh
   subagent with no prior context, never a second window.
3. Log while working in docs/CYCLE-LOG.md using the kinds START, ADVICE (owner), ADVISOR (tool),
   DECISION, DEVIATION, BLOCKER, VERIFICATION, REVIEW, HANDOFF, NOTE, END. Results, hashes and
   counts go in the entry that reports them. END carries the advisor summary; "0 times" is written.
4. Never ask the owner for anything in chat. Every GO, credential, script run, session clear or
   decision is a numbered "Owner action N:" line in STATUS.md §7 with the exact thing to do,
   ending "reply `update` when done". Every question carries "(Recommended)" on option 1 with a
   one-line reason. Check the evidence before restating an action.
5. STATUS.md has the fixed eight-section shape (§1 Git state, §2 Step map, §3 What changed,
   §4 Decisions and by whom, §5 Why stopping, §6 Mismatches, §7 Next step / Owner actions,
   §8 Programme board) and a header carrying tests, advisor counts, review verdict and the
   context size at write. Rewritten at every step boundary, push and halt, and at the end of every
   run no matter what — when in doubt, STATUS.md first. A halt IS a STATUS.md write.
6. Docs-only commits (PLAN revision, STATUS, CYCLE-LOG, reports, DECISIONS when authorised,
   post-merge record) go to main and push immediately, with `[skip ci]` in the message. Code goes
   on a branch per cycle → PR → fresh-subagent adversarial review with a numbered PASS / PASS WITH
   NOTE / FAIL(severity, file, line, scenario) list logged as REVIEW → fix loop (cap 2) → merge
   only under the plan's merge rule or an owner GO. Low doc-only findings fixed in-cycle; Medium+
   or anything in code/config/routes/migrations/tests stops and returns to the owner. Code commits
   and PR branches never carry `[skip ci]`.
7. Before every feature-branch push: full suite green with the literal count; `git log
   origin/main..HEAD` quoted. Suite never shrinks. Code held behind a GO is pushed as
   hold/<step>, never to main. Post-merge record lands on main directly.
8. Named authorisation, one per action, quoted in the log before acting: merge to main, deploy,
   remote migration, real identities, real email/messages, any live-money switch, deleting
   production data. Nobody self-authorises a gate the plan states. Owner advice in chat is
   logged as ADVICE before it is acted on; scope-widening advice needs an explicit "yes".
9. Servers follow the allow-list in CLAUDE.local.md: read-only free; state-changing per-command
   "yes"; the never-list (secrets, .env, tokens, interactive shells, prod seeding/reset,
   rollbacks, raw SQL on prod, deletes, sudo, key changes, composer setup) is absolute. Root only
   via a recipe the owner runs; never ask for a sudo password or API token. No real name, email,
   phone, key, secret or webhook payload in any report, log, fixture, seeder, commit or STATUS.
10. Every server-script change runs on the rehearsal server before production; rollback open
    before any cutover; failed check → rollback, no second attempt the same day. Frozen files
    (listed in CLAUDE.md) change only under build → prove → halt → GO with a red/green proof.
11. Consult the advisor, and log an ADVISOR entry, at least the minimum the plan states and
    always when touching auth/tenancy, money/ledger/payout code, a frozen file, a plan–repo
    conflict, or before a production-affecting judgment call. Every ADVISOR entry names the model
    that answered and quotes one line of what came back; an entry that cannot name the model is a
    tool failure, not a consultation, and does not count toward the minimum. Confirm on the first
    consultation of each cycle that the advisor actually answered. A timeout is a tool failure,
    not advice. Stale plan text → disclose in STATUS.md §6 and correct the report; never silently
    redo finished work, never silently do something else.
12. Read back and diff every file after writing it. Cite file:line or quoted output for every
    claim about behaviour. Own mistakes in plain words.
13. One cycle per session. Run every step and gate of the cycle without asking for a clear. Only
    at the cycle's END: write STATUS.md with §5 "context handoff", log a HANDOFF entry with the
    context size and the number of compactions this session, and raise "Owner action N: `/clear`
    this session, then reply `update`". Raise it only when PLAN.md, STATUS.md and CYCLE-LOG carry
    what is done, what is next and what was ruled out — if they do not, write that first. A halt
    for an owner GO is not a clear; wait, and resume on the next `update`. You cannot clear
    yourself; the owner does it.
14. 200k context only; the 1M window is never enabled except by an owner ruling for one cycle.
    Auto-compaction inside a cycle is normal and expected — it fires around 180k, you carry on.
    Never ask for a clear because compaction is approaching, and never stop work because it fired.
    STATUS.md at every step boundary is what makes compaction safe; keep that rule. Manual /compact
    is rarely needed and never at cycle END. A programme runs across compactions the same way and
    stops only on a failed compaction or its resume cap.
    Sessions are free — never consolidate work into a long-lived session to save cost, and never
    treat a fresh session as a cost. MCP servers, plugins and skills not needed this cycle stay
    off. Simple subagents (search, read, grep) run on the cheapest capable model; advisor and
    review subagents use the stronger one. Never estimate or log your own token spend; report only
    the measured context size.

## When unsure
Ask the advisor first for architecture questions and log it. For product questions, write an "Owner action" in STATUS.md §7 with a recommended option and pick the default listed in PRD §11 or the simplest option that keeps invariants intact until the owner answers. docs/PROJECT_BRIEF.md, docs/PRD.md and docs/reference/ are read-only for you; if you believe the PRD is wrong, say so in STATUS.md §6. If it conflicts with docs/reference/, the PRD wins.
