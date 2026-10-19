# STATUS — cycle 20 r1 — programme "steady state III", 20a merged and on rehearsal, 20b parked, 20c in progress; written 2026-10-19 09:10
Tests: **2845 passed / 21297 assertions** on `main` `9e204d9` (measured on the 19b head `7be1d20`; `8456a7c` was comments only) · Advisor: consulted 1 time this cycle (Opus 5.5, 20b; a Fable 5.1 attempt failed on a rate limit and is not counted) · Review: 20a round 3 PASS WITH NOTES, 0 Medium, 0 High · Context size: not measured by the tool (cloud session, no `/context`); compactions this session: 1. R222 window check: `CLAUDE_CODE_DISABLE_1M_CONTEXT`=1, `CLAUDE_CODE_AUTO_COMPACT_WINDOW`=200000.

## §1 Git state
- `origin/main` = `9e204d9` (PR #62 squash) plus docs-only commits after it; `rehearsal` = `9e204d9`, verified through `/ops/status` (release 80561433). PR #62 merged. Local branch `cp/20b-suspension-settlement` has no commits and is not pushed; `cp/19b-masker-prepass` still exists locally and on the remote (no branch deleted, per plan). Harness branch `claude/confident-mendel-80sp0u` is scratch (R210). No production deploy.

## §2 Step map
1. START, R222 check — [done]. 2. 20a (19b round 3, R235) — [done: merged `9e204d9`, rehearsal verified]. 3. 20b (R236) — [parked: no `confirmed → no_show_student` edge]. 4. 20c (R237) — [in progress]. 5. END — [not started].

## §3 What changed this run
- 20a: `Shadow::placeholderSpans` reads its byte maps a word-aligned 4,096-word chunk at a time instead of a byte at a time; red/green on a 100,000-byte placeholder (96.6 ms before, about 17 ms after). Tests added for ids above 255 and 20,000 separate placeholders; three comments and one test name the review flagged corrected. Merged as `9e204d9`; push CI green on both PostgreSQL jobs; rehearsal fast-forwarded and verified (R213).
- 20b: advisor consulted, then parked with the transition table cited (CYCLE-LOG DECISION 2026-10-19). No code changed.

## §4 Decisions and by whom
- Owner: R234–R237 (PLAN r1). Owner action 13 answered "yes" (R235); Owner action 14 answered by R236.
- CC: merge of PR #62 under R224(2)/R147; rehearsal deploy under R228; park 20b under R236's last sentence on the advisor's reading (Opus 5.5).

## §5 Why stopping
Not stopped: programme running (20c next).

## §6 Mismatches
- **20b / R236 against the state machine.** R236(a) needs `NoShowStudent` from a lesson nobody joined; that lesson is `confirmed` and `Confirmed → [CancelledByParent, CancelledByTutor, InProgress, NoShowBoth, ProviderFailure]` (`LessonStateMachine.php:52-63`). The plan said to park, not add the edge. So the cycle-19 behaviour stands: a suspended parent's unattended lesson is refunded via `no_show_both`.
- A tutor-suspended `confirmed` lesson never reaches settlement (`CancelSuspendedTutorLessons` already cancels and refunds it), so R236(b) only matters for the `in_progress` case (19c gap 2: tutor joined, learner not, then stuck).
- Not covered by R236 and still open: a suspended party whose counterpart joined while the suspended party never did; both accounts suspended (two audit rows, no precedence rule).
- The Fable 5.1 advisor hit its usage limit during this session; Opus 5.5 answered instead.
- Ordering slip: the rehearsal push preceded its DECISION entry (disclosed in the entry).
- Cycle 19's §6 items (19c gaps 1–8, 19d notes, known over-masks) carry unchanged.

## §7 Next step / Owner actions
Next: 20c, then END.
Owner action 15: decide 20b. (1) (Recommended) Grant a frozen-file GO to add the edge `confirmed → no_show_student` to `LessonStateMachine` under build → prove → halt → GO with a red/green proof; it is the only way to give a suspended parent's unattended lesson the student-no-show outcome R236(a) asked for, and a later plan then ships all of R236 whole. (2) Accept a partial ruling: a later plan authorises only (a1) parent suspended / tutor joined / `in_progress` and (b2) tutor suspended / tutor joined / `in_progress` with existing edges, and (a2) stays refunded and disclosed. (3) Leave settlement as it is and rely on the admin's two-dial dispute. Reply `update — <1, 2 or 3>`.
Owner action 5 (`mail.from_name`, currently `trusTutor Team`) stays open and non-blocking; Owner actions 2, 3, 4, 6–12 carry unchanged (full text: `git show 467f057:docs/STATUS.md` §7). Owner-blocking, carried: D-04 with counsel; authorising `trustutor-production`; Postmark wiring (R120).

## §8 Programme board
| Sub-cycle | Branch | PR | Review | State |
|---|---|---|---|---|
| 20a | `cp/19b-masker-prepass` | [#62](https://github.com/rizwanoor80/eLearning-Platform/pull/62) | PASS WITH NOTES (0 Medium, 0 High) | merged `9e204d9`, rehearsal verified |
| 20b | `cp/20b-suspension-settlement` | — | — | parked (Owner action 15) |
| 20c | `cp/20c-lesson-page-clock` | — | — | in progress |
Resume cap 12, 0 used.


## Deferred
- **19a corpus follow-ups (REVIEW 2026-10-18 20:08):** the garbled nested placeholders in 218 baseline outputs (a masker defect; 19b may fix it); the placeholder-for-everything and unchecked `masked` limits; the `LessonPageTest` Dubai-midnight flake (planner).
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
