# STATUS — cycle 18 r3 — programme "steady state", 18c in progress; written 2026-10-18 15:20
Tests: **2655 passed / 13496 assertions on `main`** (last measured, cycle 18a merge; nothing run yet this session) · Advisor: consulted 0 times this session (0 confirmed); cycle 18 total 2 (18a, 18b design, Fable 5.1) · Review: 18a 11 verdicts, 0 Medium, 0 High (merged); 18b closed unmerged (R223) · Context size: not measured by the tool (cloud session, no `/context`); compactions this session: 0. R222 window check: `CLAUDE_CODE_DISABLE_1M_CONTEXT`=1, `CLAUDE_CODE_AUTO_COMPACT_WINDOW`=200000 (CYCLE-LOG 15:14).

## §1 Git state
- `origin/main` = `0359bcb` (PLAN r3) before this docs commit; code-bearing commit `feab912` (PR #55). Rehearsal untouched this cycle (`6fdef0c`).
- PR #56 **closed without merge** (R223; comment posted). `cp/18b-masker-spelled` (`5d89910`), `hold/18b-fix` (`37bce02`), `hold/18b-fix2` (`4ad0dd2`), `hold/18b-fix3` (`303dd44`) stay on the remote untouched. No open PRs. The harness branch `claude/confident-mendel-80sp0u` is scratch (R210).

## §2 Step map
1. START — [done] (r1). 2. 18a, PR #55 merged `feab912` — [done] (r1).
3. R222 START entry, close PR #56 under R223, STATUS header and board — [done] (this commit).
4. 18c under R219 (+ R224(7)) — [in progress].
5. 18d under R225 — [not started]. 6. 18e under R226 — [not started]. 7. 18f under R227 — [not started]. 8. END — [not started].

## §3 What changed this run
- CYCLE-LOG 15:14 START and R222 VERIFICATION. PR #56 closed with one comment citing R223. STATUS `## Deferred`: the four 18b lines replaced by the single R223 line.

## §4 Decisions and by whom
- Owner (PLAN r3): R223 closes 18b unmerged; R224 programme mode standing; R225–R228 queue 18d–18f and the per-merge rehearsal deploy. CC: executed R223 to the letter (no branch deleted, none touched).

## §5 Why stopping
Not stopped: programme running. Next halt is only per R224(5).

## §6 Mismatches
- None new. Carried from cycle 17/18: HOW-WE-WORK §17 "the same four" versus CLAUDE.md's five cloud lines; the advisor model basis; the empty-`DB_PASSWORD` mismatch; `mail.from_name` reads `trusTutor Team` (Owner action 5).

## §7 Next step / Owner actions
**Owner action 5 (reopened by R214): the mail sender name.** `/ops/status` reports `mail.from_name` = `trusTutor Team`; ADR-006 spells the brand `TrusTutor`. 1. (Recommended) In the admin mail settings set the sender name to `TrusTutor Team`; if that field is blank, set `MAIL_FROM_NAME` in Forge's environment for rehearsal; reason: it keeps your wording and fixes only the capital, and the surface that renders it is the status endpoint, which CC re-reads at the last deploy. 2. Leave it. Reply `update` when done. (Not blocking.)

Owner action 16 is closed by R223. Carried unchanged (full text: `git show 467f057:docs/STATUS.md` §7): **Owner action 2**, **3**, **4**, **6**, **7**, **8**, **9**, **10** (option 1 Recommended: keep as built), **12**. Owner-blocking, carried: D-04 with counsel; authorising `trustutor-production`; Postmark wiring (R120).

## §8 Programme board
| Sub-cycle | Branch | PR | Review | State |
|---|---|---|---|---|
| 18a | `cp/18a-privacy-props` | #55 | 11 verdicts, 0 M/H | merged `feab912` |
| 18b | `cp/18b-masker-spelled` | #56 | 3 rounds, last 6 Medium | closed unmerged (R223) |
| 18c | `cp/18c-low-notes` | — | — | in progress |
| 18d | `cp/18d-display-name-races` | — | — | pending |
| 18e | `cp/18e-rate-limits` | — | — | pending |
| 18f | `cp/18f-history-encryption` | — | — | pending |
Resume cap 12, 0 used.

## Deferred
- **Cycle-17 follow-ups found by the 17a review (none built, all outside R212's text):** `<server … force="true"/>` twins in `phpunit.xml` so single-process runs are protected too; a `::warning::` annotation when the build only goes green on a retry; the hook creating `elearning_testing` and one `pg_isready` line; the empty-`DB_PASSWORD` mismatch between the role and Laravel; `APP_ENV`/`DB_CONNECTION` and the two published passwords in `docs/cloud/ENVIRONMENT.md` (planner's file).
- **`max_participants: 2` on Daily rooms** — a Daily property that cannot be run from here.
- **Inertia history encryption** — app-wide; page props (including the lesson title) stay in the browser's history state on a shared device.
- **English title literals in `LessonRoomView`** — "with" and "for" not yet translated; wait for the i18n pass.
- **`YearGroupMigrationTest` per-curriculum count map cannot tell two curricula with equal counts apart; `y8` looked up without a curriculum filter** — the two Low notes of the one-file review of R204, test-only.
- **A screen in our own markup** (R189(a) as worded) — only if the planner accepts the cost recorded in cycle 14 r2 §6.
- **Withdraw a display-name request, clear an approved name, email on approval** — R195 asks for none; a later plan if wanted.
- **A guard against `VITE_FONTS_INLINE=0` being set where Bunny is reachable** (a warning in the build output) — the reviewer's Low note 8; not built, the switch is explicit by design.
- **Low notes of the 16b re-review (R207, three lenses; all in code or tests)** — fix the stale comment at `app/Providers/Filament/AdminPanelProvider.php:58`; make the consumer-scan test also match `@fonts ('x')`, `$vite->fonts()` and `app(Vite::class)->fonts()`; replace the self-comparison at `tests/Unit/ViteFontsFallbackTest.php:138`; name `VITE_FONTS_INLINE` in `docs/cloud/ENVIRONMENT.md`; `.font-<alias>` classes, preloads and `&subset=latin` in flag-0 builds; derive the flag-0 CSS variables from the plugin's own manifest builder; guard the SSR rule on `env.isSsrBuild` as well. Detail: CYCLE-LOG REVIEW 2026-10-16 18:04.
- **Closed in cycle 18a (PR #55) and removed from the list:** the tutor's own IBAN in dashboard page props; a display-name request landing on an anonymised tutor (queue, request and approval).
- **Closed in cycle 17 and removed from the list:** the five exported variables overriding `phpunit.xml` (R211, R212(1)); the hook's silent `key:generate`/`migrate` and its literal password (R212(3)); the R209 candidate "harden CI against a Bunny timeout" (R212(2)).
- **18a review notes (cycle 18, REVIEW 09:25, items 2, 7, 8):** `SendDisplayNameRejectedMail` mails the tombstone address when an anonymisation commits between a rejection and the queue worker (`user()` is `withTrashed`, no `trashed()` guard, `RejectDisplayName` has no `assertAccountOpen`); `RequestDisplayName` and `AnonymizeUser` run at the same instant can deadlock on the user row (audit FK `FOR KEY SHARE` vs `FOR UPDATE`) into a 500 with nothing written; `TutorLeadTimeController.php:26`, `RequestDisplayNameRequest.php:17`, `DisplayNameController.php:22` still load `tutorProfile` onto the request user (redirect-only, covered by `$hidden`); no test pins the display-name queue badge count with an anonymised tutor.

- **Spelled-out contact details in `MessageMasker` — redesign before any retry (R223):** (1) first commit a regression corpus (the round-2 reviewer's approach: every input's output on `main`'s class, frozen as a dataset, so CI fails on any input `main` hides that a change exposes); (2) then add the shapes as one normalising pre-pass (`at`→`@`, `dot`→`.`, number words→digits) on a copy, never as new passes inside the chain; work on `hold/18b-fix3` (`303dd44`) is reference only. Also open on `DisplayNameRule`: moot with 18b closed. Masking stays the documented backstop; invariant 8's primary controls are unchanged.
