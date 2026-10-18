# STATUS — cycle 18 r1 — step 2 done (18a merged); step 3 (18b) next; written 2026-10-18 09:35
Tests: **2655 passed / 13496 assertions** (`composer test` exit 0 on the PR head's code, CYCLE-LOG VERIFICATION 09:20; was 2646 / 13412); CI success on both PostgreSQL jobs for PR head `8d0ba66` (run 39115369946) · Advisor: **consulted 1 time so far this cycle (1 confirmed in part: flags 1, 5, 7 adopted or adopted in part, nothing rejected)**, model named in its own report, counted on the configured `advisorModel` basis (R63) · Review (18a): **11 verdicts, 6 PASS, 3 PASS WITH NOTE, 2 FAIL (Low: one test-only fixed in round 1 of 2, one older app-code gap to Deferred), 0 Medium, 0 High** · Context size: not measured by the tool (cloud session, no `/context`).

## §1 Git state
- `origin/main` = **`feab912`** (PR [#55](https://github.com/rizwanoor80/eLearning-Platform/pull/55), squash of `7cd8732` + `8d0ba66`); the docs commits above it are this file's. Rehearsal untouched this cycle so far (still `6fdef0c`). Open PRs: none. `cp/18a-privacy-props` is left on the remote like the earlier `cp/*` branches. The harness branch `claude/clever-ramanujan-1iq82k` is scratch (R210).

## §2 Step map
1. START entry, STATUS header, docs to `main` — **[done]** (`6bb872c`).
2. 18a under R217 — **[done]**: PR #55 merged as `feab912`; the `iban in body` test and the three display-name tests green; 2646 + 9 = 2655 quoted. Push CI on `feab912` is read at the start of step 3.
3. 18b under R218 (`MessageMasker` spelled-out shapes) — **[not started]**.
4. 18c under R219 — **[not started]**.
5. Rehearsal deploy, R213 verification, R214 value, END — **[not started]**.

## §3 What changed this run
- **Code (PR #55, 8 files, 209 insertions, 5 deletions):** `TutorProfile::$hidden` gains the four bank attributes (`app/Models/TutorProfile.php`); `TutorDashboardController` reads its profile with a query; `DisplayNameRequests::assertAccountOpen()` is called inside the profile lock by `RequestDisplayName` and `ApproveDisplayName`; `DisplayNameRequestResource::getEloquentQuery()` excludes anonymised tutors with a `whereExists` on `users.deleted_at`. Two new test files, 9 tests.
- **Proof:** red before any app edit, 5 of the 9 failing, including "Expecting '<!DOCTYPE html>…' not to contain" the plaintext IBAN (the cycle 15 `iban in body=true`); green after; full gate 2655 / 13496 (CYCLE-LOG VERIFICATION 09:20). The reviewer reproduced the same 5/4 split on a scratch copy.
- **Environment (CYCLE-LOG NOTE 09:27):** the readiness chain took about 25 minutes for `composer install` this session (9.5 on 2026-10-17); `npm ci` hit `ECONNRESET` once; my `&&` chain then skipped the build, `createdb` and `key:generate`. Fixed by hand; nothing wrong in the repo.

## §4 Decisions and by whom
- Owner (PLAN r1): R216-R220 and the 18a text.
- Advisor (design, before the first edit): adopted `$hidden` + dashboard query, a plain user read after the profile lock instead of a user-row lock (simpler), `whereExists` over `whereHas(withoutTrashed)` (the `bookable()` precedent); logged the three redirect-only controllers instead of widening R217.
- CC: used `users.deleted_at` as the anonymised predicate (`AnonymizeUser` soft-deletes the row and writes no other marker). CC: fixed the vacuous parent-pages test in round 1 (Low, test-only). CC: left review items 7 and 8 and the badge-count test out of the PR (R220: a Low in app code is fixed in-cycle only when the reviewer names file, line and a one-line fix for this change).
- CC: PR creation, check reading and the one merge attempt through the GitHub MCP tools (`gh` is not authenticated here); squash, one attempt, no refusal (R147).
- Owner: the `update` that started this run.

## §5 Why stopping
Not stopping: this is a step-boundary rewrite. Step 3 (18b) starts from `feab912`.

## §6 Mismatches
- **Stale plan text (R217):** it says the IBAN is expected "`=true` only on the bank step". The bank step receives `bank_iban_masked` only (`TutorOnboardingController.php:113`, `Onboarding.vue:104,570`); the full IBAN is in no page. The test asserts the full IBAN appears in no body and the masked form on the bank step. Nothing else in R217 was stale.
- **For the planner (`docs/cloud/ENVIRONMENT.md` §3b):** a fresh session's readiness took about 25 minutes for `composer install` and one `npm ci` needed a retry; chain the four steps with `;`-plus-checks rather than `&&`, or mention the retry; `vendor/bin/pest --parallel` accepts one path only, so targeted runs are single-process.
- R216's owner action is done (none of the variables is set; CYCLE-LOG START).
- Carried unchanged from cycle 17 §6: HOW-WE-WORK §17 "the same four" versus CLAUDE.md's five cloud lines; the R178 wording; the advisor model basis; the empty-`DB_PASSWORD` mismatch; `mail.from_name` reads `trusTutor Team` (Owner action 5).

## §7 Next step / Owner actions
Next: step 3, 18b, then 18c, then the rehearsal deploy (PLAN steps 3-5); no halt before END.

**Owner action 5 (reopened by R214): the mail sender name.** `/ops/status` reports `mail.from_name` = `trusTutor Team`; ADR-006 spells the brand `TrusTutor`. 1. (Recommended) In the admin mail settings set the sender name to `TrusTutor Team`; if that field is blank, set `MAIL_FROM_NAME` in Forge's environment for rehearsal; reason: it keeps your wording and fixes only the capital, and the surface that renders it is the status endpoint, which CC re-reads at step 5. 2. Leave it. Reply `update` when done.

Carried unchanged (full text: `git show 467f057:docs/STATUS.md` §7): **Owner action 2** (the pre-join screen and the two new Daily properties on rehearsal), **3** (the Daily retry test, R184, after 2), **4** (the SendPulse sender display name and unsubscribe link, R174(c)), **6** (UI-developer review of the booking screen), **7** (UI-developer review of the 7g branding), **8** (UI-developer review of the onboarding checklist and copy), **9** (the Subjects form at 1280 and 375 px), **10** (the audit scope of `/ops/status`; option 1 Recommended: keep as built), **12** (see the display-name queue once), **11** (closed by R216 on the planner's side). Owner-blocking, carried: D-04 with counsel; authorising `trustutor-production`; Postmark wiring (R120).

To answer an action, reply `update — <n>: <answer>` in a new session.

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
