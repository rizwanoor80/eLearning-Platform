# STATUS — cycle 22 r1 — programme steady state V, 22a in progress; written 2026-10-20 09:05
Tests: **2864 passed / 21441 assertions** on `main` code `2b3e0a4` (cycle 21's last green; 22a not yet run) · Advisor: 0 so far this cycle (minimum 1 for 22a) · Review: n/a yet · Context size: not measured by the tool (cloud session, no `/context`); compactions this session: 0. R222 window check: `CLAUDE_CODE_DISABLE_1M_CONTEXT`=1, `CLAUDE_CODE_AUTO_COMPACT_WINDOW`=200000, `CLAUDE_CODE_REMOTE`=true.

## §1 Git state
- `main` = `daaf80a` (PLAN cycle 22 r1, docs-only) over code `2b3e0a4`. `rehearsal` = `2b3e0a4`. No open PR. No production deploy.

## §2 Step map
1. START, R222 check, board — [done]. 2. 22a (R242–R244, ADR-029) — [in progress]. 3. END — [not started].

## §3 What changed this cycle
- 21a (PR #66, `2b3e0a4`): new `app/Actions/Lessons/SettleSuspendedLesson.php`, called first by `SettleEndedLesson` for `in_progress` and `confirmed` lessons past end + `video.close_grace_minutes`. Keyed on a `lesson.room_closed_suspension` audit row naming the lesson's own tutor user or account owner. Parent only + `confirmed`: `Confirmed → CancelledByParent → CompletedReported`, `payTutor`, `cancel_reason` `account_suspended`. Parent only + `in_progress` and not both joined: `InProgress → NoShowStudent → CompletedReported`, `payTutor`. Tutor (alone or with the parent) + `in_progress` and not both joined: `InProgress → NoShowTutor → Refunded`, `refundParent`, no strike. Everything else unchanged. Every settled row writes `lesson.settled_suspension`. Atomic: both transitions and the audit row in one transaction; the join facts are re-read on the locked row (a late join throws `LessonTransitionException` and the next sweep completes the lesson). No frozen file, no migration.
- 19 tests in `tests/Feature/Lessons/SettleSuspendedLessonTest.php` (suite +19 over 2845). Red/green shown: with the `SettleEndedLesson` call removed 9 fail; with the party filter removed the stranger-row test fails.
- Verified on rehearsal: `/ops/status` 200 with git_sha `2b3e0a4`, `debug` false, 0 pending migrations, Horizon running, 0 failed jobs, scheduler ok, ledger ok; `/`, `/login`, `/tutors`, `/terms` 200; unauthenticated `/ops/status` 404.

## §4 Decisions and by whom
- Owner: R238 (suspended party is the no-show party, existing edges only). Planner: R239 (checks the state machine).
- CC, under R224(2)/R147: merged PR #66 once (conditions quoted in the log); deployed rehearsal under R228. Advisor (Opus 5.5, quoted in the log): a new single-purpose action, one transaction, null actor.
- Advisor summary: consulted 1 time; minimum met.
- R214: `mail.from_name` = `trusTutor Team` (read at the 21a deploy). Owner action 5 stays open.

## §5 Why stopping
Not stopping: 22a is in progress (programme mode, R224). Cycle 21's record below (§3, §4, §6) carries until 22a rewrites it.

## §6 Mismatches
- **Planner text vs repo, "system user":** R238 says `cancelled_by_user_id` and the actor are "the system user"; none exists. The code uses the null actor, as `SettleEndedLesson::unattended()` already does.
- **"Five balance buckets":** R238's proof asks for the tutor's five buckets; they do not exist before CP5. The tests assert per-account ledger balances (escrow, tutor, platform, refund) and `ledger:verify`.
- **"Nothing here blocks a dispute" is not true:** `Refunded` has no outgoing edge and `OpenDispute` allows only the account owner from `Completed`/`CompletedReported`. The tutor row (`Refunded`) can never be disputed; the parent rows can be disputed only by the suspended parent; no admin path opens a dispute. Closing it needs a state-machine edge or a new admin action (frozen file / a new plan).
- **Product notes the owner may want to confirm (all match R238 literally):** (1) parent suspended, `in_progress`, only the learner joined (tutor absent): the tutor is paid, and the suspended parent cannot mark the no-show. (2) A tutor who attended but is suspended is refunded as a "tutor no-show". (3) The audit row exists only if a room existed, and rooms open `room_lead_minutes` = 15 before the start: a parent suspended 14 minutes before the start pays; 16 minutes before gets the unattended refund; a reinstated parent is still charged. This overrides R138's "confirmed lessons are an admin decision" for lessons that had a room. (4) The parent-confirmed row fires the existing `SendLessonCancelledMail` and `RecordLessonCancelledNotification` listeners: a "cancelled" mail and bell after the lesson ended, the tutor included; the reason is hidden because `account_suspended` is reserved.
- **Audit row is the only signal:** a late row (a retried `CloseSuspendedAccountRooms` listener writing after end + grace), a provider outage across all retries, a crash between its conditional UPDATE and the audit insert, no room, and the 3-strikes `SuspendTutor` path (writes no row) all fall back to the old behaviour (unattended refund or left in progress). Fails toward the parent.
- **`SettleEndedLessons` summary counters** ignore `CompletedReported` (the command was out of the boundary): suspension payouts do not show in its "Completed X, refunded Y" line. Deferred.
- Cycle 19's §6 items (19c gaps 1–8, 19d notes, known over-masks) carry unchanged.

## §7 Next step / Owner actions
Parked PRs: none. Default-taken decisions: none.
Owner action 15: answered by R238 (2026-10-19), closed. No new Owner action is needed to continue; the product notes in §6 (items 1–4) are for the owner and planner to rule on in the next PLAN. When convenient: `/clear` this session and reply `update`.
Owner action 5 (`mail.from_name`, currently `trusTutor Team`) stays open and non-blocking; Owner actions 2, 3, 4, 6–12 carry unchanged (full text: `git show 467f057:docs/STATUS.md` §7). Owner-blocking, carried: D-04 with counsel; authorising `trustutor-production`; Postmark wiring (R120).

## §8 Programme board
| Sub-cycle | Branch | PR | Review | State |
|---|---|---|---|---|
| 22a | `cp/22a-suspension-settlement-finish` | — | — | in progress |
Resume cap 12, 0 used.


## Deferred
- **21a follow-ups (REVIEW 2026-10-19):** `SettleEndedLessons` summary line omits `CompletedReported` (suspension payouts); a dispute path for the `Refunded` tutor row and an admin-opened dispute (state machine, frozen); a suppression or reworded template for the post-end "cancelled" mail and bell on the parent-confirmed row; the audit-row timing windows in §6.
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
