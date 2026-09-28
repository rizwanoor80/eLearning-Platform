# STATUS — cycle 09 r1 (programme "CP8-pre") — Step 1 done, step 2 in progress — written 2026-09-28 21:32 (machine clock) — Context: not measured by the tool — **not a halt (PLAN.md step 1: "halt: no"); continuing straight into step 2**

Tests at this write: unchanged since cycle 08's close (no code touched yet this cycle) — `composer test` on `main` at `93241a3` last confirmed **1869/1869 passed, 9547 assertions**, `ledger:verify` clean, `npm run build` clean. This run's own commit (`5697f27`) is docs-only, `[skip ci]`, so no fresh suite run was required or taken for it.

## §1 Git state
`main` = `origin/main` at `5697f27` (fast-forwarded from `93241a3`, this run's own docs-only `[skip ci]` push — `93241a3..5697f27`). Working tree clean. No cycle-09 code branch open yet; `cp/9a-hardening` is the next branch to cut, from `5697f27`.

`rehearsal` unchanged this run, still at `d3c8a0a` (cycle 08's deploy) — nothing in cycle 09 has been deployed yet; step 6 (deploy + END) is the only step authorised to touch it.

## §2 Step map (cycle 09, programme "CP8-pre")
1. Docs-only commit: PLAN.md cycle 09 r1, `docs/PROJECT_BRIEF.md`, R153 CHECKPOINTS changes — **done** (`5697f27`, pushed). See §3.
2. `cp/9a-hardening` (R149, items a–h) — **in progress.** Orientation complete for item (a): `MessageController::store()` (app/Http/Controllers/Messaging/MessageController.php:85-94) catches only `ConversationClosedException`; `SendMessage::__invoke()` (app/Actions/Messaging/SendMessage.php:44) calls `$this->masker->mask($body)` with no surrounding try/catch, and `MessageMasker::mask()`/`orFail()`/`hasCandidate()` (app/Support/Messaging/MessageMasker.php:357-379) throw a plain `RuntimeException` on fail-closed — confirming STATUS §6 item L exactly. `ReviewController::store()` (app/Http/Controllers/Reviews/ReviewController.php:49-64) read as the reference pattern to mirror (catch → `Inertia::flash('toast', ['type'=>'error', ...])` → `to_route(...)`). No file edited yet, no branch cut yet.
3. `cp/9b-disputes` (R150) — not started.
4. `cp/9c-admin-ops` (R151) — not started.
5. `cp/9d-audit-dashboard` (R152) — not started.
6. Deploy and END (R111) — not started.

## §3 What changed this run
- **Step 1 docs-only commit (`5697f27`, pushed to `main`):** committed PLAN.md cycle 09 r1 (R148, owner GO "go ahead with recommendations", 2026-09-28) and the planner-refreshed `docs/PROJECT_BRIEF.md` unedited (R153).
- **CHECKPOINTS.md evidence check (R153):** a fresh-subagent Explore task (no prior context) checked all 5 CP0 and all 3 CP4 open acceptance boxes against test files/source on `main` HEAD `93241a3`, read-only. Ticked 7 of 8, each with an inline `file:line` citation: CP0 tutor-onboarding, admin-Filament-login, Money unit tests, RTL CI grep; CP4 all three (7-day notice/strike, parent-end §4, two-parent collision). Left CP0's parent register/verify/login/**empty-dashboard** box unticked — no test chains the full journey, and none asserts the dashboard is empty for a freshly-registered parent specifically (`DashboardTest.php:19` only asserts HTTP 200). Full detail in CYCLE-LOG's VERIFICATION entry and inline in CHECKPOINTS.md. Accepted the CP7 notification-centre box (STATUS §6 item T) per R153 — no further action.
- **Step 2 orientation (no edit yet):** read `MessageController.php`, `ReviewController.php`, `MessageMasker.php`, `SendMessage.php` to confirm R149(a)'s exact gap and reference pattern before writing the fix (see §2 above).

## §4 Decisions and by whom
- CC (this run): ticked 7 of 8 CHECKPOINTS boxes rather than all 8 — the "empty dashboard" claim is a specific, falsifiable assertion (dashboard content is empty for a fresh parent) that no existing test makes, even though the adjacent register/verify/login steps are each separately proven; left unticked per R153's own instruction rather than stretched to match adjacent partial evidence.
- CC (this run): treated the Money-unit-tests box and the tutor-onboarding box as satisfied on substance despite two literal wording mismatches (the exact string "AED 120.00" isn't in `MoneyTest.php`; "placeholder" describes a since-superseded CP0-era stub, not today's full wizard) — the underlying behaviour each box is testing for is genuinely covered, and both mismatches are disclosed inline rather than silently ticked or silently left unticked.
- CC (this run): read `MessageMasker.php`/`SendMessage.php` in full before drafting the R149(a) fix rather than assuming STATUS §6 item L's line numbers were still accurate — they were close but not exact (item L cited `SendMessage.php:42`; the actual call site is line 44 in the current file), confirming the practice of re-reading before citing rather than trusting a prior report verbatim.

## §5 Why stopping
**Not stopping.** PLAN.md step 1 is "halt: no" and step 1 is done; proceeding directly into step 2 (`cp/9a-hardening`) per the owner-loop's "execute from the first unfinished step" and rule 13 ("never stop only to clear... otherwise run every authorised step without asking"). This write exists because rule 5 requires STATUS.md at every push, not because of a halt.

## §6 Mismatches
Full register carried forward unabbreviated from cycle 08's close (items A–V, 0/0a–0h, 1–10 — see prior revision `93241a3:docs/STATUS.md` for the complete text, unchanged this run except item W added below). Nothing in cycle 09 so far has touched or reopened any earlier item.

W. **New this run — CP0's "empty dashboard" acceptance box stays unticked (disclosed, not blocking, R153's own "no halt" clause).** `RegistrationTest.php:27`, `EmailVerificationTest.php:33` and `AuthenticationTest.php:23` each separately prove register/verify/login; `DashboardTest.php:19` proves an authenticated user reaches the dashboard (HTTP 200) but does not assert its content is empty, and no test chains all four steps as one journey for a freshly-registered parent specifically. Not routed to any sub-cycle in R149–R152 (out of this cycle's scope per R148); left here for the planner to decide whether a CP8-pre or later cycle should add the missing assertion, or whether the box's wording should be loosened.

## §7 Next step / Owner actions
Continuing autonomously into step 2 (`cp/9a-hardening`) — no owner action needed to proceed. The five non-blocking items below are unchanged carries from cycle 08's close (R148: "Owner actions 3, 4, 8, 9 and 12 stay with the owner"); answer at your own pace, before or after this cycle finishes.

Owner action 3: **Daily account (R125)** — create the Daily account and one domain for rehearsal, then in the admin "Video providers" screen paste the API key and the webhook secret and activate `daily`; I never hold either. Option 1 (Recommended): you do that on rehearsal only, because the fake provider must be off there before real lessons. Reply `update` when done.

Owner action 4: **Daily webhook proof** — after action 3, run one test lesson on rehearsal and confirm the webhook arrives and that `event_ts` is stable across a retry (it decides the join check, §6 0c(a)). Option 1 (Recommended): you send one delivery and one retry from Daily's dashboard and tell me, so I read the result from the log only. Reply `update — 4: done`.

Owner action 8: **UI developer's review of PR #22 (booking screen) on rehearsal** — non-blocking. Option 1 (Recommended): hand the UI developer https://rehearsal.trustutor.com/tutors and let them book as a demo parent, because the screen is live there now. Reply `update` with their notes.

Owner action 9: **UI developer's review of 7g (PR #29, branding) on rehearsal** — non-blocking; the designer should also see the derived dark scheme (§6 0g(h)). Option 1 (Recommended): send them `docs/reports/7g.md` with the site link, because it lists every disclosure. Reply `update` with their notes.

Owner action 12: **UI developer's walkthrough of messaging, reviews and the Report button on rehearsal (CP7)** — non-blocking. Three preconditions so it doesn't hit a false negative: messaging opens only after a paid booking between that account and tutor (invariant 8) — use a demo pair with a completed lesson, not a fresh match request; a review can only be left once that lesson reaches a `completed*` status with both `tutor_joined_at`/`learner_joined_at` non-null (CP7 box 3's eligibility rule); the Report button requires the viewer to be a party to whatever they're reporting (the lesson, the conversation, or the tutor profile) — a bystander gets a 404 by design, not a blank form. Option 1 (Recommended): hand the UI developer a demo parent+tutor pair with at least one completed lesson on rehearsal (book and run one through the fake gateway first if none exists yet) plus https://rehearsal.trustutor.com/dashboard, and ask them to walk messaging → leaving a review → the Report button on a lesson, a conversation, and a tutor profile — all three surfaces are confirmed live and flag-on there (cycle 08's `config:show settings.defaults` check). Reply `update` with their notes.

No `/clear` offered — this is not a halt (rule 13); work continues into step 2 without waiting for a reply.

## §8 Programme board (CP8-pre, cycle 09)
| Step | Sub-cycle | Ruling(s) | State |
|---|---|---|---|
| 1 | docs (PLAN + CHECKPOINTS evidence) | R153 | **done**, `5697f27` |
| 2 | 9a hardening | R149 | in progress — orientation done for (a), no edit yet |
| 3 | 9b disputes | R150 | not started |
| 4 | 9c admin ops | R151 | not started |
| 5 | 9d audit + dashboard | R152 | not started |
| 6 | deploy + END | R111 | not started |

**Resume count 0 of 8.**
