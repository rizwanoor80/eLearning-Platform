# STATUS — cycle 21 r1 — programme steady state IV, 21a in progress; written 2026-10-19 10:00
Tests: **2845 passed / 21297 assertions** on `main` `b8b568d` (docs-only since `f139489`) · Advisor: 0 consultations so far this cycle (minimum 1 for 21a) · Review: none yet · Context size: not measured by the tool (cloud session, no `/context`); compactions this session: 1. R222 window check: `CLAUDE_CODE_DISABLE_1M_CONTEXT`=1, `CLAUDE_CODE_AUTO_COMPACT_WINDOW`=200000, `CLAUDE_CODE_REMOTE`=true.

## §1 Git state
- `origin/main` = `b8b568d` (PLAN cycle 21 r1, docs-only) over code `f139489`; `rehearsal` = `9e204d9`. No open PR. Branch `cp/21a-suspension-settlement` about to be cut from `origin/main`. Local `cp/19b-masker-prepass`, `cp/20b-suspension-settlement` (no commits), `cp/20c-lesson-page-clock` remain (no branch deleted). Harness branch `claude/confident-mendel-80sp0u` is scratch (R210). No production deploy.

## §2 Step map
1. START, R222 check, board, close Owner action 15 — [done]. 2. 21a (R238) — [in progress]. 3. END — [pending].

## §3 What changed this cycle
- Docs only so far: START entry; Owner action 15 recorded as answered by R238 (supersedes R236).

## §4 Decisions and by whom
- Owner: R238 (suspended party is the no-show party, existing edges only) answers Owner action 15 (2026-10-19). Planner: R239.
- Advisor summary: not yet consulted this cycle.
- R214: `mail.from_name` = `trusTutor Team` (last read 2026-10-19; re-read at the 21a deploy). Owner action 5 stays open.

## §5 Why stopping
Not stopping: programme mode (R224), running 21a.

## §6 Mismatches
- None new. The R236 mismatch is resolved by R238 (existing edges `Confirmed → CancelledByParent → CompletedReported`, `InProgress → NoShowStudent → CompletedReported`, `InProgress → NoShowTutor → Refunded`, checked against `LessonStateMachine.php`). Cycle 19's §6 items (19c gaps 1–8, 19d notes, known over-masks) carry unchanged.

## §7 Next step / Owner actions
Parked PRs: none. Default-taken decisions: none.
Owner action 15: **answered** by R238 (2026-10-19); no reply needed.
Owner action 5 (`mail.from_name`, currently `trusTutor Team`) stays open and non-blocking; Owner actions 2, 3, 4, 6–12 carry unchanged (full text: `git show 467f057:docs/STATUS.md` §7). Owner-blocking, carried: D-04 with counsel; authorising `trustutor-production`; Postmark wiring (R120).

## §8 Programme board
| Sub-cycle | Branch | PR | Review | State |
|---|---|---|---|---|
| 21a | `cp/21a-suspension-settlement` | — | — | in progress |
Resume cap 12, 0 used.


## Deferred
- **19a corpus follow-ups (REVIEW 2026-10-18 20:08):** the garbled nested placeholders in 218 baseline outputs (a masker defect; 19b may fix it); the placeholder-for-everything and unchecked `masked` limits; (the `LessonPageTest` Dubai-midnight flake closed in 20c).
- **Cycle-17 follow-ups found by the 17a review (the twins and the hook lines closed in 18c):** a `::warning::` annotation when the build only goes green on a retry (the empty-`DB_PASSWORD` mismatch closed in 19d); `APP_ENV`/`DB_CONNECTION` and the two published passwords in `docs/cloud/ENVIRONMENT.md` (planner's file).
- **`max_participants: 2` on Daily rooms** — built and tested in 19c, unverified against live Daily (connection-count caveats in §6 gap 5).
- **18e review notes (REVIEW 2026-10-18 17:00):** behind a load balancer `$request->ip()` is the balancer's address until `TRUSTED_PROXIES` is set (R130, already under production hardening) — then registration is about 5 sign-ups a minute site-wide per form; the walk counts a Fortify own-limiter route as throttled through `throttle:fortify` although the dispatcher returns none for it (a null `fortify.limiters.login` would not turn it red); Horizon and Filament/Livewire carry no baseline limiter (allow-listed; broadcasting auth got one in 19d); the two sign-up forms have a counter each.
- **18f review notes (REVIEW 2026-10-18 17:24):** history is cleared on an explicit sign-out or a surviving suspended session only (the usual suspension and `AnonymizeUser` delete the user's `sessions` rows, so the other device arrives as a guest with no flag, and an expired session is the same); the Filament admin sign-out does not clear it (panel out of scope: an admin who opened an Inertia page such as settings in a tab keeps that tab's key); over plain HTTP on a non-localhost host the browser has no `crypto.subtle` and stores unencrypted with a console warning, so the protection is real only over HTTPS (rehearsal is HTTPS; confirm for production); no test for Fortify's JSON 204 sign-out.
- **English title literals in `LessonRoomView`** — "with" and "for" not yet translated; wait for the i18n pass.
- **A screen in our own markup** (R189(a) as worded) — only if the planner accepts the cost recorded in cycle 14 r2 §6.
- **Withdraw a display-name request, clear an approved name, email on approval** — R195 asks for none; a later plan if wanted.
- **A guard against `VITE_FONTS_INLINE=0` being set where Bunny is reachable** (a warning in the build output) — the reviewer's Low note 8; not built, the switch is explicit by design.
- **Low notes of the 16b re-review still open (R207):** name `VITE_FONTS_INLINE` in `docs/cloud/ENVIRONMENT.md` (planner's file); `.font-<alias>` classes, preloads and `&subset=latin` in flag-0 builds; derive the flag-0 CSS variables from the plugin's own manifest builder. Closed in 18c: the stale `AdminPanelProvider` comment, the wider consumer scan, the self-comparison, the SSR guard.
- **18c review notes (REVIEW 2026-10-18 16:04, all fail-safe or doc-only):** the directive regex's `\s*` crosses newlines (a bare `@fonts` followed by a line starting `(…'x'…)` would count as an aliased call; the test turns red, never green); `$this->vite->fonts()` and `resolve(Vite::class)->fonts()` are not scanned (none exist; the only consumer is `AdminPanelProvider`); the weights assertion compares two views of one helper; PHPUnit 13.3.3 ignores `force` on `<server>`; CLAUDE.md rule 15 does not say it supersedes rules 4, 6 and 13 where they differ, and HOW-WE-WORK §12's heading still says "(opt-in)"; a stray semicolon inside the bold run at CLAUDE.md:27.
- **Closed in cycle 18c (PR #57) and removed from the list:** the `<server>` twins, the hook's `pg_isready` line and `elearning_testing`, the two YearGroupMigrationTest Lows, four of the 16b Low notes.
- **Closed in cycle 18a (PR #55) and removed from the list:** the tutor's own IBAN in dashboard page props; a display-name request landing on an anonymised tutor (queue, request and approval).
- **Closed in cycle 17 and removed from the list:** the five exported variables overriding `phpunit.xml` (R211, R212(1)); the hook's silent `key:generate`/`migrate` and its literal password (R212(3)); the R209 candidate "harden CI against a Bunny timeout" (R212(2)).
- **Closed in cycle 19d (PR #64) and removed from the list:** the profile-then-users lock inversion (two sites), the `broadcasting/auth` limiter, the empty-`DB_PASSWORD` role-password mismatch, the `EnsureAccountActive` docblock, HOW-WE-WORK §17 wording.
- **Closed in cycle 18d (PR #58) and removed from the list:** the tombstone-mail and Reject-on-anonymised gap, the `RequestDisplayName`/`AnonymizeUser` deadlock (users row `FOR SHARE` before the profile row), the three `tutorProfile` loads onto the request user, the missing badge-count test.
- **18d review notes (REVIEW 2026-10-18 16:20):** the lock inversion noted here is closed in 19d (`lockOwner()` at the two tutor-actor sites; rule recorded in the `AnonymizeUser` and `transition()` docblocks); still open: the queued listener plus queued mailable fixes the recipient at listener time (sibling listeners the same; a re-check inside the mailable job is a later change); the Reject guard is belt-and-braces (`AnonymizeUser` already nulls the request); `FOR SHARE` briefly holds off UPDATEs of the tutor's users row.

- **Spelled-out contact details in `MessageMasker` — redesign before any retry (R223):** (1) first commit a regression corpus (the round-2 reviewer's approach: every input's output on `main`'s class, frozen as a dataset, so CI fails on any input `main` hides that a change exposes); (2) then add the shapes as one normalising pre-pass (`at`→`@`, `dot`→`.`, number words→digits) on a copy, never as new passes inside the chain; work on `hold/18b-fix3` (`303dd44`) is reference only. Also open on `DisplayNameRule`: moot with 18b closed. Masking stays the documented backstop; invariant 8's primary controls are unchanged.
