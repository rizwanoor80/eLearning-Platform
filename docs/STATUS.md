# STATUS — cycle 19 r1 — programme "steady state II", 19a PR #61 reviewed, CI re-run pending; written 2026-10-18 20:09
Tests: **2701 passed / 13956 assertions** on 19a head `d4baf94` (base `main` 2689 / 13928 + 12 new) · Advisor: consulted 0 times this session (0 confirmed; 19a's minimum is 0); cycle 19 total 0 · Review: 19a round 1 12 verdicts (1 Medium, 3 Low, 0 High), fixed in `d4baf94`; re-review 5 verdicts, 0 FAIL · Context size: not measured by the tool (cloud session, no `/context`); compactions this session: 1. R222 window check: `CLAUDE_CODE_DISABLE_1M_CONTEXT`=1, `CLAUDE_CODE_AUTO_COMPACT_WINDOW`=200000 (CYCLE-LOG 19:40).

## §1 Git state
- `origin/main` = `55a72ee` plus this record; code on `main` is `8c64d6f`; `rehearsal` = `8c64d6f`. Open PR: [#61](https://github.com/rizwanoor80/eLearning-Platform/pull/61) `cp/19a-masker-corpus` at `d4baf94` (test-only; `app/` untouched). The harness branch `claude/confident-mendel-80sp0u` is scratch (R210).

## §2 Step map
1. START, R222 check — [done]. 2. 19a under R230 — [PR open, reviewed, fixed, waiting on the CI re-run; merge next under R224(2)/R147]. 3. 19b under R231 — [not started, needs 19a merged]. 4. 19c under R232 — [not started]. 5. 19d under R233 — [not started]. 6. END — [not started].

## §3 What changed this run
- 19a: `tests/Support/Messaging/MaskerCorpus.php` (6962 deterministic inputs, seed 230: 8 synthetic sections plus the 162 dataset rows last), `tests/Fixtures/Messaging/masker_corpus.jsonl` (header plus one `<sha1> <output>` line per input, recorded from `main`'s unchanged masker), `tests/Fixtures/Messaging/masking_fixtures.php` (the existing dataset moved byte-identically), `tests/Support/Messaging/record_corpus.php` (regeneration, run from `main` only), `tests/Unit/Messaging/MessageMaskerCorpusTest.php` (12 tests: size, keyed coverage, monotonicity, 50 ms budget, no refusal, the checker's own tests). `MessageMasker.php` is unchanged.
- CYCLE-LOG 20:08: DEVIATION, REVIEW x2, VERIFICATION, DECISION (flake).

## §4 Decisions and by whom
- Owner (PLAN r1): R229–R233 and the queue. CC: recording is whole-output text keyed by `sha1(input)` rather than spans (DEVIATION, CYCLE-LOG 20:08); the Dubai-midnight flake is handled by a standing-down comment and one re-run, not by editing that test.

## §5 Why stopping
Not stopping: waiting on the single CI re-run of `d4baf94`, then merging 19a.

## §6 Mismatches
- **`LessonPageTest` "lists an in-progress lesson on the tutor dashboard for today" fails when CI runs within five minutes of Dubai midnight (19:55–20:00 UTC)** — both PostgreSQL jobs of run 39161181974 failed on it for a PR that touches no `app/` code. For the planner: a clock-sensitive test; a fix is a later plan (freeze the clock in that test).
- **Existing masker defect frozen into the 19a baseline:** 218 of 6962 recorded outputs (3.1%) carry garbled nested placeholders (for example an `ftp://EXAMPLE.COM/@sara_k` input becomes `[hidden until after your first lesson] until after your first lesson]`). Not a leak, but users see it. Fixing it is an `app/` change; it fits 19b (the corpus accepts a change that hides more or equal).
- **Corpus limits (disclosed in the class docblock):** an exposed stretch that repeats visible text can false-pass; a normalisation-only change reads as an exposure; a masker returning one placeholder for every input passes the corpus (only the exact dataset test pins that); the `masked` flag is unchecked; orphan recordings and the header count/seed are not asserted; masker output depends on the ICU/PCRE version.
- Carried: HOW-WE-WORK §17 wording (19d item 5); the empty-`DB_PASSWORD` mismatch (19d item 4); `mail.from_name` reads `trusTutor Team` (Owner action 5).

## §7 Next step / Owner actions
Nothing for the owner now. Owner action 5 (`mail.from_name`) stays open and non-blocking; Owner actions 2, 3, 4, 6–12 carry unchanged (full text: `git show 467f057:docs/STATUS.md` §7). Owner-blocking, carried: D-04 with counsel; authorising `trustutor-production`; Postmark wiring (R120).

## §8 Programme board
| Sub-cycle | Branch | PR | Review | State |
|---|---|---|---|---|
| 19a | `cp/19a-masker-corpus` | [#61](https://github.com/rizwanoor80/eLearning-Platform/pull/61) | r1 12 verdicts (1 Medium fixed), re-review 0 FAIL | CI re-run pending, then merge |
| 19b | `cp/19b-masker-prepass` | — | — | not started (depends on 19a) |
| 19c | `cp/19c-suspension-video` | — | — | not started |
| 19d | `cp/19d-hygiene` | — | — | not started |
Resume cap 12, 0 used.


## Deferred
- **19a corpus follow-ups (REVIEW 2026-10-18 20:08):** the garbled nested placeholders in 218 baseline outputs (a masker defect; 19b may fix it); the placeholder-for-everything and unchecked `masked` limits; the `LessonPageTest` Dubai-midnight flake (planner).
- **Cycle-17 follow-ups found by the 17a review (the twins and the hook lines closed in 18c):** a `::warning::` annotation when the build only goes green on a retry; the empty-`DB_PASSWORD` mismatch between the role and Laravel; `APP_ENV`/`DB_CONNECTION` and the two published passwords in `docs/cloud/ENVIRONMENT.md` (planner's file).
- **`max_participants: 2` on Daily rooms** — a Daily property that cannot be run from here.
- **18e review notes (REVIEW 2026-10-18 17:00):** behind a load balancer `$request->ip()` is the balancer's address until `TRUSTED_PROXIES` is set (R130, already under production hardening) — then registration is about 5 sign-ups a minute site-wide per form; the walk counts a Fortify own-limiter route as throttled through `throttle:fortify` although the dispatcher returns none for it (a null `fortify.limiters.login` would not turn it red); Horizon, Filament/Livewire and broadcasting auth carry no baseline limiter (allow-listed); the two sign-up forms have a counter each.
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
- **Closed in cycle 18d (PR #58) and removed from the list:** the tombstone-mail and Reject-on-anonymised gap, the `RequestDisplayName`/`AnonymizeUser` deadlock (users row `FOR SHARE` before the profile row), the three `tutorProfile` loads onto the request user, the missing badge-count test.
- **18d review notes (REVIEW 2026-10-18 16:20):** the profile-then-users lock inversion remains in `CompleteTutorOnboarding.php:39` (via `TransitionTutorStatus`/`TutorStatusTransitions.php:82`) and `ResetPermitScanOnPermitChange.php:46` — a rule for any new FK-to-tutor insert; the queued listener plus queued mailable fixes the recipient at listener time (sibling listeners the same; a re-check inside the mailable job is a later change); the Reject guard is belt-and-braces (`AnonymizeUser` already nulls the request); `FOR SHARE` briefly holds off UPDATEs of the tutor's users row.

- **Spelled-out contact details in `MessageMasker` — redesign before any retry (R223):** (1) first commit a regression corpus (the round-2 reviewer's approach: every input's output on `main`'s class, frozen as a dataset, so CI fails on any input `main` hides that a change exposes); (2) then add the shapes as one normalising pre-pass (`at`→`@`, `dot`→`.`, number words→digits) on a copy, never as new passes inside the chain; work on `hold/18b-fix3` (`303dd44`) is reference only. Also open on `DisplayNameRule`: moot with 18b closed. Masking stays the documented backstop; invariant 8's primary controls are unchanged.
