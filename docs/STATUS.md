# STATUS — cycle 23 r2 — programme "money in, money out", 23a done (merged, deployed, R213 green), 23b next; written 2026-10-20 (23a record)
Tests: **2924 / 21783** on `4641de9` (baseline 2892 / 21630; +32 tests) · `ledger:verify` OK · pint, PHPStan 0 errors, vue-tsc, RTL grep and `npm run build` green · push CI on the PR head green on PostgreSQL 16 and 18 · Advisor: consulted 1 time (23a, Opus 5.5; one Fable 5.1 attempt failed on a rate limit and does not count) · Review: 23a round 1 (Opus 5.5), 0 High, fix round 1 of 2 applied · Context size: not measured by the tool (cloud session, no `/context`)

## §1 Git state
- `main` = `bdb6481` (PR #68 squash-merged: 23a gateway registry) plus docs-only records. `rehearsal` = `bdb6481` (deployed, R213 green: pending 0). No open PR. No production deploy. No branch deleted (`cp/23a-gateway-registry` stays on origin).

## §2 Step map
1. START, R222 check, board, ADR-030 — [done]. 2. 23a gateway registry — [done: merged `bdb6481`, record, rehearsal deployed, R213 green]. 3. 23b Stripe driver [next]. 4. 23c Stripe checkout. 5. 23d gateway refunds. 6. 23e receipts. 7. 23f payout methods. 8. 23g tutor earnings. 9. 23h payout batches + `hold/23h-ledger-payout`, then HALT for GO (R253). 10. On GO: merge, record, deploy rehearsal, R213, END. Resume cap 16, 0 used.

## §3 What changed this cycle
- ADR-030 (R245, R254) added to `docs/DECISIONS.md`; START entry (plan echo, R222, R240 name check) in `docs/CYCLE-LOG.md`.
- **23a (PR #68, merged `bdb6481`):** the `payment_gateways` registry. Table (`fake` and `stripe` rows, encrypted JSON credentials, mode, `supports_saved_cards`, one-active partial unique index) and model `PaymentGatewayAccount` (credentials hidden and `encrypted:array`; `saving` guard for the live flag, the fake allow-list, complete credentials, driver existence and saved cards). `PaymentGatewayManager` resolves the driver from the active row on every call, nothing cached; `PaymentGateway` is `bind`-ed to it. `ActivatePaymentGateway` / `DeactivatePaymentGateway` actions (row-locked, audited with key names only). Filament resource Payment gateways (write-only `secret_*` inputs, masked). No active row: booking shows `lessons/BookingUnavailable`, the store route returns a form error, `recurring:charge` charges nothing and still cancels past-start lessons; no `payments` row is created in any of these. The shared `paymentTestMode` prop drives the test-mode banner. Live mode is refused in code unless `PAYMENT_LIVE_MODE_ALLOWED` is true (ships off).
- Review fix round 1: a typed secret is cleared from Livewire state when a save is refused; activation takes a lock on the registry; `NoActivePaymentGateway` is caught on the booking store path and in the charge command; a docblock corrected.

## §4 Decisions and by whom
- Owner/planner: R245–R257 (the plan).
- Advisor (Opus 5.5): the `fake` row's `is_active` is seeded from `fakeGatewayAllowed()`; the live check is its own saving rule on every row; the saved-card rule also fires when the flag is cleared on an active row; the manager caches nothing and the interface is `bind`-ed, not a singleton.
- CC (DECISION): the live switch is the environment flag `PAYMENT_LIVE_MODE_ALLOWED` (`config('payments.live_mode_allowed')`), not a database setting. The plan says "owner-only setting", but the app has one admin role, so a database setting could be flipped by any admin; an environment flag can be changed only by whoever controls the server. Ships off. See §6.

## §5 Why stopping
Not stopping: the programme runs on under R224 to its one planned halt (R253, after 23h's ledger proof).

## §6 Mismatches
- **R240 name check:** every name the plan cites exists on `main` at `53b63cf`; the things it builds do not yet exist (expected).
- **Bound-check call sites (23a):** the "no active gateway -> admin notice and no `payments` row" rule is today `app()->bound(PaymentGateway::class)` in `BookLessonController` (twice) and `ChargeRecurringLessons`. 23a keeps that predicate true or moves the three sites together.

- **23a vs R255 (disclosure):** R255's scope sentence ("within `app/Services/Payments/*` only the new Stripe driver, the two interface methods and the fake's implementations may change") is written for the 23b cycle. 23a adds two new files there (`PaymentGatewayManager.php`, `NoActivePaymentGateway.php`) because R246 and `docs/CHECKPOINTS.md:135` call for a manager that resolves the driver from the active row. No existing file in that folder changed; the service provider outside it was rewritten. Raised by the reviewer as a process note, not a defect.
- **23a vs R246 "owner-only setting":** the DECISION in §4 (environment flag).
- **Plan text gap:** the plan's proof "activating a different row changes the driver" cannot be shown in 23a because Stripe has no driver yet; it moves to 23b.

## §7 Next step / Owner actions
Nothing is waiting on the owner. Carried: Owner action 5 (`mail.from_name`, `trusTutor Team`), 2, 3, 4, 6–12 (full text: `git show 467f057:docs/STATUS.md` §7), Owner action 16 closed by this `update`. After 23a is deployed to rehearsal the owner may enter Stripe **test** keys in Admin -> Payment gateways; Claude Code never reads or enters a key. Owner-blocking, carried: D-04 with counsel; authorising `trustutor-production`; Postmark wiring (R120).

## §8 Programme board
| Sub-cycle | Branch | PR | Review | State |
|---|---|---|---|---|
| 23a gateway registry (R246) | `cp/23a-gateway-registry` | #68 | Opus 5.5 round 1: 0 High; fix round 1 applied | done; rehearsal `bdb6481`, R213 green |
| 23b Stripe driver (R247, R255) | `cp/23b-stripe-driver` | - | - | starting |
| 23c Stripe checkout (R248) | `cp/23c-stripe-checkout` | - | - | queued |
| 23d gateway refunds (R256) | `cp/23d-gateway-refunds` | - | - | queued |
| 23e receipts (R257) | `cp/23e-receipts` | - | - | queued |
| 23f payout methods (R249) | `cp/23f-payout-methods` | - | - | queued |
| 23g tutor earnings (R250) | `cp/23g-tutor-earnings` | - | - | queued |
| 23h payout batches (R251, R253) | `cp/23h-payout-batches` + `hold/23h-ledger-payout` | - | - | queued; halts for GO |
Resume cap 16, 0 used.

## Deferred
- **23a review notes (REVIEW 2026-10-20, not built):** a weekly slot can be created or resumed while the active gateway lacks saved-card support (the rule is enforced on the gateway-row side only; it matters once a driver that cannot save cards exists — 23b/23c to revisit); `recurring:charge` resolves the gateway once per run, not per lesson; the proof that activating a different row changes the driver moves to 23b.
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
