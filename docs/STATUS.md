# STATUS — cycle 18 r1 — HALT: 18b has a Medium in code (PR #56 open; fix held on hold/18b-fix); written 2026-10-18 10:00
Tests: **2711 passed / 13574 assertions on `hold/18b-fix`** (`composer test` exit 0, CYCLE-LOG BLOCKER 09:56; `main` is 2655 / 13496; PR #56's own head `1b55e5d` was 2706 / 13561); CI on PR #56 was started by the push, result not read before the halt · Advisor: **consulted 2 times (2 confirmed in part)**: 18a design (flags 1, 5, 7 adopted or adopted in part) and 18b design (flags 1-4 adopted, the year exclusion not), models named in their own reports (Fable 5.1), counted on the configured `advisorModel` basis (R63) · Review: 18a **11 verdicts, 0 Medium, 0 High** (merged); 18b **17 verdicts, 7 PASS, 6 PASS WITH NOTE, 4 FAIL (1 Medium in app code, 3 Low), 0 High** · Context size: not measured by the tool (cloud session, no `/context`).

## §1 Git state
- `origin/main` = **`081f359`** (docs-only; the code-bearing commit is **`feab912`**, PR #55, push CI run 39115587858 success on both jobs). Rehearsal untouched this cycle (still `6fdef0c`).
- Open PR: [#56](https://github.com/rizwanoor80/eLearning-Platform/pull/56) `cp/18b-masker-spelled` at `1b55e5d` (one commit on `feab912`) — **not to be merged as it stands**. `hold/18b-fix` = `37bce02`, one commit on `1b55e5d`, pushed, holds the fix. `cp/18a-privacy-props` is left on the remote. The harness branch `claude/clever-ramanujan-1iq82k` is scratch (R210) and equals `origin/main`.

## §2 Step map
1. START, STATUS header, docs to `main` — **[done]** (`6bb872c`).
2. 18a under R217 — **[done]**: PR #55 merged `feab912`, push CI green on both jobs, 2655 quoted.
3. 18b under R218 — **[blocked]**: built, 2706 / 13561, reviewed; the review found a Medium in code (below), which the plan's merge rule does not cover; the fix is built and proved on `hold/18b-fix`, waiting for the owner's GO.
4. 18c under R219 — **[not started]** (follows 18b's post-merge record).
5. Rehearsal deploy, R213, R214, END — **[not started]**.

## §3 What changed this run
- **18a (merged):** see the previous section of this file in git history (`git show 081f359:docs/STATUS.md`) and CYCLE-LOG 09:20-09:32: `TutorProfile::$hidden` gains the four bank attributes, the dashboard reads its profile with a query, `DisplayNameRequests::assertAccountOpen()` in the request and approval, the queue excludes anonymised tutors; 9 tests.
- **18b (PR #56, `1b55e5d`):** `MessageMasker` hides a spelled-out e-mail (`at`/`[at]`/`(at)`/`@` with `dot`/`[dot]`/`(dot)`/tight `.`), five or more digit words that are not a plain count, and a messenger name followed within three tokens by an `@handle` or four or more grouped digits; the two old "Known limits" rows flip to masked under their old keys; 22 positive and 18 negative rows, 5 timing shapes, 3 helper tests; docblock rewritten. Red: 17 failures on the old class. Full gate 2706 / 13561.
- **The Medium (CYCLE-LOG REVIEW item 1):** MESSENGER's token group is cubic in the punctuation of one space-free run; about 200 characters after `discord:`/`Signal:`/`whatsapp` exhaust the backtrack limit and the message is refused ("The message could not be masked, so it was not stored"). Three reproducing inputs are in the log; they fail on `1b55e5d` and pass on `hold/18b-fix`.
- **`hold/18b-fix` (`37bce02`):** disjoint filler and gap classes (linear), the dead placeholder alternative removed, NUMBER_WORDS separators any non-alphanumeric, the wrong "sub-threshold" row text fixed, the vacuous test removed, docblock limits extended; +2 positive rows, +1 negative, +4 timing rows. 2711 / 13574.

## §4 Decisions and by whom
- Owner (PLAN r1): R216-R220 and the 18b text. Advisor (design): adopted its flags 1-4 and the em dash; rejected the year exclusion (over-masking is the declared safe side).
- CC: kept the two old Known-limits rows under their original keys with flipped expectations (the limits they pinned are the ones R218 closes; flagged as such in the PR and the docblock). CC: exempted strictly counting runs and one repeated word from the digit-word rule (a deviation from R218's "five or more", disclosed in the PR and the docblock; advisor-suggested). CC: did **not** fix the Medium on the PR branch or merge, because R220 gives the fix loop to Lows in docs and tests only; built the fix as held code instead (rule 7).
- Reviewer's own claim "FAIL (Medium)" was confirmed by me before acting (three inputs, refused on `1b55e5d`).

## §5 Why stopping
**Gate reached: an owner decision.** A Medium in app code (CYCLE-LOG REVIEW item 1) stops the cycle under HOW-WE-WORK §9 and R220. This is a halt for a GO, not a clear: the files carry the state (below), and the next `update` resumes in a new cloud session.
- **Done:** steps 1 and 2; 18b built, proved, reviewed; the fix built and proved on a hold branch; every entry written (START, NOTE, ADVISOR ×2, VERIFICATION ×2, REVIEW ×2, DEVIATION, DECISION, NOTE, BLOCKER).
- **Next, after the GO:** put `37bce02` on `cp/18b-masker-spelled` (fast-forward), read CI on both jobs, run the round-1-of-2 re-review with a fresh subagent, merge under R147, write the post-merge record, then 18c (R219), then the rehearsal deploy and END.
- **Ruled out:** merging PR #56 at `1b55e5d`; pushing the fix to the PR branch before the GO; starting 18c before 18b's record; widening 18b beyond R218 (the year exclusion, a presence gate, new shapes).

## §6 Mismatches
- **Stale/ambiguous plan text, R218:** "up to two interior `dot` segments" and "a run of five or more digit words … masked as a phone candidate" are implemented as written; the counting exemption is the one addition (above). "within three tokens" is implemented on the held fix as three *words* (letters, digits, `_`), since a punctuation-containing token was what made the pattern cubic.
- **Class docblock cites ADR-019** for "Known limits"; DECISIONS.md was not touched (the plan does not authorise it). For the planner: ADR-019 now describes limits that R218 narrowed.
- **For the planner (`docs/cloud/ENVIRONMENT.md`):** as in the previous STATUS (readiness took about 25 minutes for `composer install`, one `npm ci` needed a retry, `--parallel` takes one path).
- R220 wording: "0 Medium/High and the fix loop (cap 2) for Lows in docs/tests only" leaves a Medium in app code with no route except an owner GO; if the planner wants a Medium fixed in-cycle with a re-review, say so in the next revision.
- Carried unchanged from cycle 17 §6: HOW-WE-WORK §17 "the same four" versus CLAUDE.md's five cloud lines; the R178 wording; the advisor model basis; the empty-`DB_PASSWORD` mismatch; `mail.from_name` reads `trusTutor Team` (Owner action 5).

## §7 Next step / Owner actions
**Owner action 14: the Medium on PR #56 (the 18b masker).** Choose: 1. (Recommended) GO to apply the held fix: CC fast-forwards `cp/18b-masker-spelled` to `hold/18b-fix` (`37bce02`), reads CI, runs one fresh re-review (round 1 of 2), merges under R147 if it finds 0 Medium/High, then does 18c and the rehearsal deploy; reason: the fix is built, reproduces the Medium red and goes green, the full suite passes (2711), and it adds no scope beyond the review's findings. 2. Do not fix: CC closes PR #56 unmerged, leaves `MessageMasker` as on `main`, logs the three shapes as Deferred, and goes on to 18c. 3. Something else (write it in the reply). Reply `update — 14: 1` (or `2`, or the text) in a new session.

**Owner action 5 (reopened by R214): the mail sender name.** `/ops/status` reports `mail.from_name` = `trusTutor Team`; ADR-006 spells the brand `TrusTutor`. 1. (Recommended) In the admin mail settings set the sender name to `TrusTutor Team`; if that field is blank, set `MAIL_FROM_NAME` in Forge's environment for rehearsal; reason: it keeps your wording and fixes only the capital, and the surface that renders it is the status endpoint, which CC re-reads at step 5. 2. Leave it. Reply `update` when done.

Carried unchanged (full text: `git show 467f057:docs/STATUS.md` §7): **Owner action 2**, **3**, **4**, **6**, **7**, **8**, **9**, **10** (option 1 Recommended: keep as built), **12**. Owner-blocking, carried: D-04 with counsel; authorising `trustutor-production`; Postmark wiring (R120).

## §8 Programme board
none. Cycle 18 r1 is a plain five-step cycle (resume cap 10, 0 used).

## Deferred
- **Cycle-17 follow-ups found by the 17a review (none built, all outside R212's text):** `<server … force="true"/>` twins in `phpunit.xml` so single-process runs are protected too; a `::warning::` annotation when the build only goes green on a retry; the hook creating `elearning_testing` and one `pg_isready` line; the empty-`DB_PASSWORD` mismatch between the role and Laravel; `APP_ENV`/`DB_CONNECTION` and the two published passwords in `docs/cloud/ENVIRONMENT.md` (planner's file).
- **`max_participants: 2` on Daily rooms** — a Daily property that cannot be run from here.
- **Inertia history encryption** — app-wide; page props (including the lesson title) stay in the browser's history state on a shared device.
- **English title literals in `LessonRoomView`** — "with" and "for" not yet translated; wait for the i18n pass.
- **`MessageMasker` gaps (cycle 14 r2 §6)** — touches messaging and invariant 8; planner to schedule.
- **`YearGroupMigrationTest` per-curriculum count map cannot tell two curricula with equal counts apart; `y8` looked up without a curriculum filter** — the two Low notes of the one-file review of R204, test-only.
- **A screen in our own markup** (R189(a) as worded) — only if the planner accepts the cost recorded in cycle 14 r2 §6.
- **Withdraw a display-name request, clear an approved name, email on approval** — R195 asks for none; a later plan if wanted.
- **A guard against `VITE_FONTS_INLINE=0` being set where Bunny is reachable** (a warning in the build output) — the reviewer's Low note 8; not built, the switch is explicit by design.
- **Low notes of the 16b re-review (R207, three lenses; all in code or tests)** — fix the stale comment at `app/Providers/Filament/AdminPanelProvider.php:58`; make the consumer-scan test also match `@fonts ('x')`, `$vite->fonts()` and `app(Vite::class)->fonts()`; replace the self-comparison at `tests/Unit/ViteFontsFallbackTest.php:138`; name `VITE_FONTS_INLINE` in `docs/cloud/ENVIRONMENT.md`; `.font-<alias>` classes, preloads and `&subset=latin` in flag-0 builds; derive the flag-0 CSS variables from the plugin's own manifest builder; guard the SSR rule on `env.isSsrBuild` as well. Detail: CYCLE-LOG REVIEW 2026-10-16 18:04.
- **Closed in cycle 18a (PR #55) and removed from the list:** the tutor's own IBAN in dashboard page props; a display-name request landing on an anonymised tutor (queue, request and approval).
- **Closed in cycle 17 and removed from the list:** the five exported variables overriding `phpunit.xml` (R211, R212(1)); the hook's silent `key:generate`/`migrate` and its literal password (R212(3)); the R209 candidate "harden CI against a Bunny timeout" (R212(2)).
- **18a review notes (cycle 18, REVIEW 09:25, items 2, 7, 8):** `SendDisplayNameRejectedMail` mails the tombstone address when an anonymisation commits between a rejection and the queue worker (`user()` is `withTrashed`, no `trashed()` guard, `RejectDisplayName` has no `assertAccountOpen`); `RequestDisplayName` and `AnonymizeUser` run at the same instant can deadlock on the user row (audit FK `FOR KEY SHARE` vs `FOR UPDATE`) into a 500 with nothing written; `TutorLeadTimeController.php:26`, `RequestDisplayNameRequest.php:17`, `DisplayNameController.php:22` still load `tutorProfile` onto the request user (redirect-only, covered by `$hidden`); no test pins the display-name queue badge count with an anonymised tutor.
- **18b review notes (cycle 18, REVIEW 09:52, items 4, 5, 7, 8, 9):** spelled-out limits left on purpose (all listed in the rewritten `MessageMasker` docblock on `hold/18b-fix`): `whats app`, `ig`, `fb`, an address with no ending, `period`/`point`/`nought`/`double`/`triple`, Cyrillic lookalike letters, a spaced literal dot (`sara at gmail . com` hides the domain and leaves the local part); over-masking accepted: `signal lost at 1500 metres`, `whatsapp group has 1234 members`, counting by twos (`one three five seven nine`).
