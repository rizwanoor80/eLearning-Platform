# STATUS — cycle 19 r1 — programme "steady state II", 19a merged, 19b parked, 19c next; written 2026-10-18 21:12
Tests: **2810 passed / 21136 assertions** on 19b head `eb2a92e` (base `main` 2701 / 13956) · Advisor: consulted 1 time this session (1 confirmed: Fable 5.1 on 19b; 19b minimum 1 met); cycle 19 total 1 · Review: 19b rounds 1–3: 21 verdicts (4 Medium), then 3 Medium, then 1 Medium, 0 High; fix cap 2 spent · Context size: not measured by the tool (cloud session, no `/context`); compactions this session: 2. R222 window check: `CLAUDE_CODE_DISABLE_1M_CONTEXT`=1, `CLAUDE_CODE_AUTO_COMPACT_WINDOW`=200000 (CYCLE-LOG 19:40).

## §1 Git state
- `origin/main` = `70f6034` (19a) plus docs records; `app/` on `main` is still `8c64d6f`; `rehearsal` = `8c64d6f`. Open PR: [#62](https://github.com/rizwanoor80/eLearning-Platform/pull/62) (19b, branch `cp/19b-masker-prepass`, head `eb2a92e`, 3 commits, CI green on both PostgreSQL jobs, **parked**). The harness branch `claude/confident-mendel-80sp0u` is scratch (R210).

## §2 Step map
1. START, R222 check — [done]. 2. 19a under R230 — [merged `70f6034`]. 3. 19b under R231 — [built, 3 reviews, **parked**: cap 2 spent, 1 Medium open]. 4. 19c under R232 — [starting]. 5. 19d under R233 — [not started]. 6. END — [not started].

## §3 What changed this run
- 19b (PR #62, not merged): `SpelledOutPrePass` (dot, at, digit words, ZWJ, messenger spans), `Shadow` (per-byte origin map, packed), `MessageMasker::mask($body, bool $spelledOut = true)` with a tracked path that unions the chain's placeholder spans over the identity copy and the rewritten copy and builds the output from the original text (monotonic versus `main` by construction; a body with no spelled-out shape takes the byte-identical plain path); `DisplayNameRule` calls `mask($form, false)` (R218). Tests: 304 in `tests/Unit/Messaging`; dataset 25+ positive and 13+ negative new rows; the 19a corpus green.
- CYCLE-LOG 21:11: ADVISOR, DEVIATION, REVIEW x3, VERIFICATION, DECISION (park).

## §4 Decisions and by whom
- Owner (PLAN r1): R229–R233 and the queue. CC: messenger spans hidden outright instead of a marker (DEVIATION); 19b parked rather than a third fix round beyond the cap (DECISION 21:11).

## §5 Why stopping
Not stopping: 19b is parked on the cap; the programme continues with 19c.

## §6 Mismatches
- **19b open Medium (review 3):** `Shadow::placeholderSpans` unpacks per byte on placeholder-heavy NFKC bodies (a performance edge, not a leak; fuzz max 5.4 ms). Fix: unpack the placeholder map once, or record each placeholder's range in `apply()`. Lows: stale "at most three separator characters" docblock in `MessageMasker` (about line 197); code points versus graphemes in the digit count; add a placeholder-heavy timing row and `Shadow` property tests with ids above 255.
- **Known over-masks, documented in the dataset:** zoom.us, "5.pm", "dot net", counting prose with 7+ digits in words.
- **`LessonPageTest` Dubai-midnight flake** (19:55–20:00 UTC) — a later plan: freeze the clock in that test.
- Carried: HOW-WE-WORK §17 wording (19d item 5); the empty-`DB_PASSWORD` mismatch (19d item 4); `mail.from_name` reads `trusTutor Team` (Owner action 5).

## §7 Next step / Owner actions
Owner action 13: authorise a third fix round for PR #62 (19b) — (Recommended: the one Medium is a small, understood change and every safety property already holds); to take it, reply `update — fix round 3` and the next run applies the fix list in §6, re-reviews, and merges under R224(2). Declining: reply `update — leave 19b parked`. Reply `update` when done.
Owner action 5 (`mail.from_name`) stays open and non-blocking; Owner actions 2, 3, 4, 6–12 carry unchanged (full text: `git show 467f057:docs/STATUS.md` §7). Owner-blocking, carried: D-04 with counsel; authorising `trustutor-production`; Postmark wiring (R120).

## §8 Programme board
| Sub-cycle | Branch | PR | Review | State |
|---|---|---|---|---|
| 19a | `cp/19a-masker-corpus` | [#61](https://github.com/rizwanoor80/eLearning-Platform/pull/61) | r1 12 verdicts (1 Medium fixed), re-review 0 FAIL | merged `70f6034` |
| 19b | `cp/19b-masker-prepass` | [#62](https://github.com/rizwanoor80/eLearning-Platform/pull/62) | r1 21 (4 Medium), r2 3 Medium, r3 1 Medium | parked, Owner action 13 |
| 19c | `cp/19c-suspension-video` | — | — | starting |
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
