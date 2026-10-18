# STATUS — cycle 18 r3 — programme "steady state", 18e done (merged, deployed, R213 green), 18f next; written 2026-10-18 17:12
Tests: **2683 passed / 13912 assertions** (measured on the 18e head `ebb1e83`; `main` is now `e0c2e8a`, the squash of it; PR CI green on both PostgreSQL jobs, push CI green) · Advisor: consulted 0 times this session (0 confirmed); cycle 18 total 4 (18a, 18b design, 18d lock order, 18e limiter design; Fable 5.1) · Review: 18a 11 verdicts, 0 M/H (merged); 18b closed unmerged (R223); 18c round 1: 13 verdicts, 0 M/H, 2 Low (fixed); round 2: 9 verdicts, 0 M/H, 1 Low (carried) — merged; 18d round 1: 15 verdicts, 0 M/H, 2 Low (fixed, docs only) — merged; 18e round 1: 16 verdicts, 0 M/H, 2 Low (fixed) — merged · Context size: not measured by the tool (cloud session, no `/context`); compactions this session: 4. R222 window check: `CLAUDE_CODE_DISABLE_1M_CONTEXT`=1, `CLAUDE_CODE_AUTO_COMPACT_WINDOW`=200000 (CYCLE-LOG 15:14).

## §1 Git state
- `origin/main` = `e0c2e8a` (PR #59, 18e, squash) plus this docs commit; before it the 18e log commits (`3a11489` and the post-merge record), `131d7f6`. Push CI on `e0c2e8a` (run 39148002406): success. `rehearsal` = `e0c2e8a` (fast-forwarded 17:10:29; R213 green, CYCLE-LOG VERIFICATION 17:12).
- PR #56 closed without merge (R223); `cp/18b-masker-spelled` (`5d89910`), `hold/18b-fix`, `hold/18b-fix2`, `hold/18b-fix3` (`303dd44`) untouched on the remote. `cp/18c-low-notes`, `cp/18d-display-name-races` (`0ffc4d9`) and `cp/18e-rate-limits` (`ebb1e83`) are merged and stay (no branch deleted). No open PRs. The harness branch `claude/confident-mendel-80sp0u` is scratch (R210).

## §2 Step map
1. START — [done]. 2. 18a, PR #55 `feab912` — [done]. 3. R222 entry, PR #56 closed (R223) — [done].
4. 18c under R219 + R224(7), PR #57 merged `c986be3` — [done]; post-merge record — [done]; rehearsal deploy and R213 (R228) — [done].
5. 18d under R225, PR #58 merged `6b2a1c8` — [done]; post-merge record — [done]; rehearsal deploy and R213 (R228) — [done].
6. 18e under R226, PR #59 merged `e0c2e8a` — [done]; post-merge record — [done]; rehearsal deploy and R213 (R228) — [done]. 7. 18f under R227 — [not started]. 8. END — [not started].

## §3 What changed this run
- PR #59 (18e, R226), 7 files: new limiters `password-reset` and `password-reset-use` (5/min each per e-mail + address), `registration` (5/min per address; the parent form through Fortify and the tutor form through its route have a counter each), `bookings` (10/min per user, shared by lesson, weekly slot and match request), `onboarding-uploads` (10/min per user, permit and documents routes), `account-writes` (60/min per user, GETs never counted) applied to every other authenticated write group, and a dispatching `fortify` limiter in `config/fortify.php` so Fortify's own routes are covered; two-factor is now keyed by the pending login (address when the session has none); login and reset keys are lower-cased, transliterated and array-safe; `Route::redirect('settings')` became `Route::get` (it was `any` by accident). `tests/Feature/Security/RateLimitingTest.php` (18 tests): a 429 test per limiter and a walk over the live router that fails on any unthrottled write route outside a four-entry allow-list (Horizon, `broadcasting/auth`, Livewire update, `admin/logout`, each with a reason). CHECKPOINTS: the compound hardening line is split and the rate-limit line ticked.
- CYCLE-LOG: 16:50 ADVISOR, DECISION (allow-list), DEVIATION (placement); 16:52 VERIFICATION (red/green, gate); 17:00 REVIEW (round 1); 17:02 DECISION (fix loop); 17:05 DECISION (merge); 17:11 NOTE and DECISION (deploy); 17:12 VERIFICATION (rehearsal at `e0c2e8a`, R213 green).

## §4 Decisions and by whom
- Owner (PLAN r3): R223 closes 18b; R224 programme mode; R225–R228 queue 18d–18f and the per-merge rehearsal deploy. CC: executed R223; merged 18c, 18d and 18e under R224(2)/R147 with the conditions quoted in the log; fixed every review Low under R224(1). Advisor (Fable 5.1): 18d lock order and 18e limiter design (both adopted except as logged).
- **Decided by default under R224, reversible (18e):** the route walk's allow-list names four vendor routes instead of only webhooks and `ops/status` (the advisor asked for the baseline middleware on Horizon, Filament and broadcasting instead; not taken because Filament polls and those files are outside 18e). Option 1 (taken, Recommended): keep the allow-list. Option 2: a later cycle adds the middleware and shrinks the list.

## §5 Why stopping
Not stopped: programme running (next: 18f under R227). Next halt is only per R224(5).

## §6 Mismatches
- None new. Carried from cycle 17/18 (re-confirmed by the 18c round-2 reviewer, Low, doc-only, left for a planner wording choice): HOW-WE-WORK §17:327 says "four lines … CLAUDE.md carries the same four" while CLAUDE.md lists four differences plus the `/clear` clarification and a toolchain bullet HOW-WE-WORK has no line for; the advisor model basis; the empty-`DB_PASSWORD` mismatch; `mail.from_name` reads `trusTutor Team` (Owner action 5).

## §7 Next step / Owner actions
**Owner action 5 (reopened by R214): the mail sender name.** `/ops/status` reports `mail.from_name` = `trusTutor Team`; ADR-006 spells the brand `TrusTutor`. 1. (Recommended) In the admin mail settings set the sender name to `TrusTutor Team`; if that field is blank, set `MAIL_FROM_NAME` in Forge's environment for rehearsal; reason: it keeps your wording and fixes only the capital, and the surface that renders it is the status endpoint, which CC re-reads at the last deploy. 2. Leave it. Reply `update` when done. (Not blocking.)

Owner action 16 is closed by R223. Carried unchanged (full text: `git show 467f057:docs/STATUS.md` §7): **Owner action 2**, **3**, **4**, **6**, **7**, **8**, **9**, **10** (option 1 Recommended: keep as built), **12**. Owner-blocking, carried: D-04 with counsel; authorising `trustutor-production`; Postmark wiring (R120).

## §8 Programme board
| Sub-cycle | Branch | PR | Review | State |
|---|---|---|---|---|
| 18a | `cp/18a-privacy-props` | #55 | 11 verdicts, 0 M/H | merged `feab912` |
| 18b | `cp/18b-masker-spelled` | #56 | 3 rounds, last 6 Medium | closed unmerged (R223) |
| 18c | `cp/18c-low-notes` | #57 | R1 13 verdicts, R2 9, 0 M/H | merged `c986be3`, deployed, R213 green |
| 18d | `cp/18d-display-name-races` | #58 | R1 15 verdicts, 0 M/H | merged `6b2a1c8`, deployed, R213 green |
| 18e | `cp/18e-rate-limits` | #59 | R1 16 verdicts, 0 M/H | merged `e0c2e8a`, deployed, R213 green |
| 18f | `cp/18f-history-encryption` | — | — | pending |
Resume cap 12, 0 used.

## Deferred
- **Cycle-17 follow-ups found by the 17a review (the twins and the hook lines closed in 18c):** a `::warning::` annotation when the build only goes green on a retry; the empty-`DB_PASSWORD` mismatch between the role and Laravel; `APP_ENV`/`DB_CONNECTION` and the two published passwords in `docs/cloud/ENVIRONMENT.md` (planner's file).
- **`max_participants: 2` on Daily rooms** — a Daily property that cannot be run from here.
- **18e review notes (REVIEW 2026-10-18 17:00):** behind a load balancer `$request->ip()` is the balancer's address until `TRUSTED_PROXIES` is set (R130, already under production hardening) — then registration is about 5 sign-ups a minute site-wide per form; the walk counts a Fortify own-limiter route as throttled through `throttle:fortify` although the dispatcher returns none for it (a null `fortify.limiters.login` would not turn it red); Horizon, Filament/Livewire and broadcasting auth carry no baseline limiter (allow-listed); the two sign-up forms have a counter each.
- **Inertia history encryption** — app-wide; page props (including the lesson title) stay in the browser's history state on a shared device.
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
