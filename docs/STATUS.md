# STATUS — cycle 22 r1 — programme steady state V, 22a done; written 2026-10-20 (END)
Tests: **2892 passed / 21630 assertions** (baseline 2864 / 21441; +28 new) on the 22a head `f3212c1`; push CI green on `a652726`, both PostgreSQL jobs · `ledger:verify` OK · `npm run build` green · RTL check green · Advisor: consulted 1 time (Opus 5.5) · Review: 22a round 1, 0 High / 0 Medium / 3 Low (all fixed) · Context size: not measured by the tool (cloud session, no `/context`); compactions this session: 1. R222 window check: `CLAUDE_CODE_DISABLE_1M_CONTEXT`=1, `CLAUDE_CODE_AUTO_COMPACT_WINDOW`=200000, `CLAUDE_CODE_REMOTE`=true.

## §1 Git state
- `main` = docs-only commits over code `a652726` (PR #67 squash). `rehearsal` = `a652726`. No open PR. No production deploy. Branch `cp/22a-suspension-settlement-finish` is merged and kept (no branch deleted).

## §2 Step map
1. START, R222 check, board — [done]. 2. 22a (R242–R244, ADR-029) — [done: merged `a652726`, ADR-029 and record on `main`, rehearsal deployed, R213 green]. 3. END — [done]. Resume cap 12, 0 used.

## §3 What changed this cycle
- **R242:** `SendLessonCancelledMail` and `RecordLessonCancelledNotification` send nothing for the `confirmed → cancelled_by_parent` that `SettleSuspendedLesson` writes. Detection is the new `SettleSuspendedLesson::wasSettled()` (the `lesson.settled_suspension` audit row, written inside the same transaction as the transitions, so the afterCommit listeners see it). The free `reserved` `account_suspended` release is unchanged (it never had a mail or bell for the paid-cancellation branch and still has none; asserted by test).
- **R243:** new `app/Actions/Lessons/OpenSuspensionDispute.php` (admin only, active; `lesson.settled_suspension` row; `completed_reported`; within 7 days of `ends_at`; one dispute per lesson via the lock and the existing unique index; audited `lesson.dispute_opened_by_admin`; the description is never audited) and one Filament action "Open dispute" on `LessonsTable`, visible only when `problemFor()` is null. `ResolveDispute` settles it unchanged. Money proof (28-test file `tests/Feature/Lessons/OpenSuspensionDisputeTest.php`): both `completed_reported` rows at 0/0, 100/0, 0/100, 50/50 and 100/100, status settled, zero-sum, escrow 0, exactly 4 `ReleaseReversal` legs, an odd price at 50/50, `ledger:verify` 0.
- **R244:** `lessons:settle-ended` prints `Completed X, refunded Y, settled after a suspension N.`; pinned by test, second run prints 0/0/0.
- **R241:** ADR-029 in `docs/DECISIONS.md`.
- Not touched: `LessonStateMachine`, `LedgerService`, `app/Services/Payments/*`, the ledger migration, `OpenDispute`, `ResolveDispute`, `CancelSuspendedAccountLessons`; no migration; `docs/cloud/ENVIRONMENT.md` and `.claude/settings.json` unchanged.
- Verified on rehearsal at `a652726`: `/ops/status` 200 with `git_sha` `a652726…`, environment rehearsal, `debug` false, 0 pending migrations, Horizon running, 0 queued, 0 failed jobs, scheduler ok, ledger ok (0 unbalanced, 0 stranded); `/`, `/login`, `/tutors`, `/terms` 200.

## §4 Decisions and by whom
- Owner: R241 (notes 1–3 accepted), R242, R243, R244. Planner: R240 (name check).
- CC, under R224(2)/R147: merged PR #67 once (conditions quoted in the log); deployed rehearsal under R228. Advisor (Opus 5.5, quoted in the log): audit row over a marker for detection; the listener timing is safe; ReleaseReversal legs expected.
- Review (fresh subagent, Opus 5.5): 22 numbered verdicts, 0 High, 0 Medium, 3 Low fixed in round 1 of 2.
- Advisor summary: consulted 1 time; minimum met.
- R214: `mail.from_name` = `trusTutor Team` (read at the 22a deploy). Owner action 5 stays open.

## §5 Why stopping
END of the programme: queue empty, nothing parked, no halt condition hit. Nothing further is authorised. Handoff for a new session: done = 22a merged and deployed; next = whatever the next PLAN says (none yet); ruled out = building any dispute path for the `Refunded` tutor row (frozen state machine) and any mail to the parent about an admin-opened dispute.

## §6 Mismatches
- **Plan wording vs repo (R242):** the plan says free `reserved` `account_suspended` releases "keep their notifications". Today they send none (the cancellation listeners' paid-cancellation branch needs `from = Confirmed`, and the mail goes only for a paid cancellation). They stay exactly as they were, and a test pins that they stay silent. No behaviour was changed there.
- **R244 wording:** the tutor-suspension refund (`no_show_tutor → refunded`) is counted under "refunded", not under "settled after a suspension"; only `CompletedReported` outcomes (the two paid-tutor rows) use the new counter. `CompletedReported` comes only from `SettleSuspendedLesson`, so the label is exact.
- **Admin-opened disputes notify the tutor and admins only:** the existing dispute-opened mail and bell go to the tutor and admins, never the parent (invariant 7 and the existing listeners). The suspended parent, who cannot log in, is not told. No new message was added.
- **Advisor notes for CP5 and later:** `ResolveDispute` never updates `payments.status` / `refunded_amount`; the already-released lesson settles by reversing the release (4 `ReleaseReversal` legs) and redistributing, and 100/100 produces a goodwill delta; `escrow_released_at` is not cleared by the reversal, so CP5's payout eligibility must read the ledger, not that column; a lesson in `disputed` drops out of the lists that show only `completed_reported`.
- **Audit row is the only signal** for the settlement (unchanged from cycle 21): a late row, a provider outage across all retries, a crash between the conditional UPDATE and the audit insert, no room, and the 3-strikes `SuspendTutor` path fall back to the old behaviour. Fails toward the parent.
- Cycle 19's §6 items (19c gaps 1–8, 19d notes, known over-masks) carry unchanged.

## §7 Next step / Owner actions
Parked PRs: none. Default-taken decisions: none. Nothing is waiting on the owner for 22a.
Owner action 16: when the next PLAN is ready, then `/clear` this session and reply `update` (the environment has not changed).
Owner action 5 (`mail.from_name`, currently `trusTutor Team`) stays open and non-blocking; Owner actions 2, 3, 4, 6–12 carry unchanged (full text: `git show 467f057:docs/STATUS.md` §7). Owner-blocking, carried: D-04 with counsel; authorising `trustutor-production`; Postmark wiring (R120).

## §8 Programme board
| Sub-cycle | Branch | PR | Review | State |
|---|---|---|---|---|
| 22a | `cp/22a-suspension-settlement-finish` | #67 | 0 High / 0 Medium / 3 Low (fixed) | merged `a652726`, rehearsal deployed, R213 green |
Resume cap 12, 0 used.


## Deferred
- **21a follow-ups still open (closed in 22a: the summary line, the admin-opened dispute, the post-end "cancelled" mail and bell):** a dispute path for the `Refunded` tutor row (needs a state-machine edge, frozen; the parent was refunded in full and tutors do not open disputes, PRD §2.10); the audit-row timing windows in §6.
- **22a review notes (REVIEW 2026-10-20, all Low or notes, not built):** the reserved-release silence test builds the edge directly instead of calling `CancelSuspendedAccountLessons`; `visible()` on the Open dispute action runs `problemFor` per visible `completed_reported` row inside 7 days (a bounded N+1); a whitespace-only dispute description passes (same as `OpenDispute`); the 23505 branch of `OpenSuspensionDispute` has no test (the row lock makes it unreachable).
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
