# STATUS — cycle 07 r1 (programme "CP6") — written 2026-09-26 17:36 (machine clock) — Context: not measured by the tool — **7b verified on head `2bd999a`, pushing and opening the PR; review next**

Tests: **1434/1434 passed, 7313 assertions** (solo run on the head `2bd999a`; main was 1391). Advisor: consulted 7 times this cycle so far (3 for 7a; 4 for 7b: design, dedicated webhook/credentials, mid-build, pre-PR; all answered; R63 'configured, not measured'). Review: 7b pending. Resume count 0 of 8.

## §1 Git state
`main` = docs commits on top of `3548a04` (PR #23); `rehearsal` = `3548a04`. Working branch `cp/7b-video-registry`: three commits ahead of `origin/main` (`4019e55`, `af7306b`, `2bd999a`), being pushed now. No open PRs before this push.

## §2 Step map (cycle 07 r1)
1. Docs-only commit (R119 v1.3, R126 PRD rows, ADR-018) — **done with one deviation**: v1.3 and ADR-018 written and read back; the PRD rows are not written (§6 item 0, Owner action 1).
2. `cp/7a-demo-tutors` (R121) — **done**: PR #23 merged (3548a04, R123), rehearsal deployed, seeder run once, 7 tutors, /tutors, a profile and guest /book (302 to /login) checked.
3. `cp/7b-video-registry` (R122, R125) — **verified on head `2bd999a`**; push, PR, fresh-subagent review, self-merge under R123 (scope reading in CYCLE-LOG DECISION)
4. `cp/7c-room-lifecycle` — [not started]
5. `cp/7d-lesson-page` — [not started]
6. `cp/7e-reports` (R124) — [not started]
7. `cp/7f-trial-cta-timeline` — [not started]
8. `cp/7g-branding` (R49) — [not started]
9. Deploy and END — [not started]

## §3 What changed this run
**7b — video-provider registry (built, verified, not yet pushed)**
- `video_providers` registry: migration-inserted `daily` (inactive, no credentials) and `fake` (active except in production) rows, one-active partial unique index, `encrypted:array` credentials hidden from serialisation. `VideoProviderManager` (`active()`, `forCode()`, `forRow()`), `DailyVideoProvider` (HTTP-faked in tests) and `FakeVideoProvider`. Activation guard refuses `fake` in production, a code with no driver, and missing credentials.
- Filament `Video providers` resource: write-only credential inputs, blank keeps the stored value, audit rows name credential keys only, Activate/Deactivate actions.
- `POST webhooks/video/{code}`: signature verified against the row named in the URL, replay-safe (`video_webhook_events` unique on provider and event id, replay → 200 `duplicate`, dispatched once; store and dispatch share one transaction, so a failed dispatch rolls the row back and the provider's retry is not lost), throttled, body-capped, CSRF-exempt for `webhooks/*` only, no `fake` endpoint in production.
- Docs: ADR-017, DATA_MODEL v1.6, CHECKPOINTS CP6 note (R126). Daily wire details are from documentation and are flagged "confirm on the first real key" in ADR-017.
- Verification (head `2bd999a`): Pint, PHPStan 0 errors, RTL check, `ledger:verify` OK, `npm run build` exit 0, 1434/1434 (7313 assertions, 683 s). An earlier run was invalid through my own mistake (two suites on one database); see CYCLE-LOG DEVIATION. The 683 s run is a BLOCKER disclosure (§6).

**Cycle 07 r1, so far**
- PLAN.md cycle 07 r1 committed as `43c9a4f` before any other work (CYCLE-LOG START).
- Step 1: `docs/HOW-WE-WORK.md` v1.3 exactly as R119 (a)–(e) lists, plus the version stamp on line 3 (DECISION); ADR-018 (R120) appended to `docs/DECISIONS.md`. Read back and diffed; the diff lines are in the commit. PRD §11 rows D-02 and D-09: refused, see §6 item 0.


**7a — DemoTutorSeeder (PR #23)**
- `database/seeders/DemoTutorSeeder.php`: seven fictional tutors ("<First> Demo", `demo.<first>@example.test`) across GCSE (3), CBSE (2), IB MYP (1), IB DP (1); hard-coded data, no factories or Faker (Faker is require-dev); per-tutor transaction; a tutor whose email exists is skipped whole; reference rows, derived tier and price band are checked for all seven tutors before the first insert; each rate re-checked with `TutorRateBands::problemWithRate`; weekly availability rules in the tutor's timezone; permit today + 15 months; unusable random password; no bank data. Not in `DatabaseSeeder`.
- `tests/Feature/DemoTutorSeederTest.php`: 16 tests (two runs same counts; existing row untouched; unrelated tutors untouched; nothing written on missing subject, moved band or edited year-group tier; eight weeks of slots; guest search lists them; guest `/book` → login; parent gets `slot_available` true; weekday pinned; no factory/Faker in source).
- Suite 1375 → 1391. Fix round 1 of 2 applied after the review's Low notes.

**7a — merged and live on rehearsal**
- PR #23 self-merged under R123 (CI green on head a4c51d3, no Medium/High, diff = seeder + its test) as `3548a04`; main CI green.
- `rehearsal` fast-forwarded to `3548a04` under R111; Forge release confirmed by server `git log`; migrations all Ran; Horizon running.
- `DemoTutorSeeder` run once on rehearsal (exit 0, 1.8 s): users 1→8, tutor_profiles 0→7, tutor_subjects 0→14, availability_rules 0→21. `/tutors` lists 7; `/tutors/1` 200; guest `/tutors/1/book` 302 to `/login`.

- **6a merged (PR #22, `1ede299`)** — R114: `GET tutors/{tutor}/book` (`BookLessonController::create`) and `POST lessons` (`store`, calls `BookLesson` unchanged), `StoreLessonBookingRequest`, `LessonPolicy::bookFor`, `lessons/Book.vue`, slot links in `tutors/Show.vue` (guests and parents only), 42 tests. Fix loop 1 added a `quote_token` (keyed HMAC of learner, tutor, type, price, currency; recomputed in `store()`) so the client chooses neither type nor price and a stale two-tab page is refused before `BookLesson`. Also: CP3 acceptance box added in CHECKPOINTS.md (R118); §6 item 2a's single-booking gap closed.
- The stalled STATUS helper (`shell_exec('date')` waits on Windows) was stopped by TaskStop; its half-written `docs/STATUS.md` was discarded before the branch switch. Timestamps are now passed through the `TS` env var.


(Cycle 05 record, carried:)
- **4f (`cp/4f-fake-gateway`, PR #21, `c34979a`)** — R107, ADR-016: `AppProvidersPaymentGatewayServiceProvider` binds `FakePaymentGateway` to `PaymentGateway` on `local`, `testing` and `rehearsal` only (allow-list; production, staging, a typo or an empty `APP_ENV` stay unbound and fail closed). One predicate, `fakeGatewayAllowed()`, also drives `SaveTestCard::available()` (tightened from "not production", so the fake add-card page also 404s on staging — a DECISION, owner may overrule), the shared Inertia prop `paymentTestMode` and the `TestModeBanner.vue` "Test mode — no real card is charged" banner on the add-card, weekly-slot and learner pages, plus the Filament create-slot modal. Docblock-only edits under `app/Services/Payments/*`. 18 new tests (`GatewayBindingTest`), red shown by dropping `rehearsal`. Docs on `main`: ADR-016 (supersedes ADR-015's "unbound outside tests" clause), CHECKPOINTS 4e line and CP5 registry line.
- **4e fix loop 1 (`f4cc7f9`, R104/R105)**: `recurring:charge` cancels a due `reserved` weekly lesson whose tutor is not `bookable()` (suspended, permit lapsed, account deleted) as `reserved → cancelled_by_tutor`, new reason `LessonCancelReason::TutorUnavailable` (`tutor_unavailable`), actor null, never charged, no ledger entry, no strike, slot left active (new `ChargeOutcome::TutorUnavailable`, counted in the command summary). Parent and tutor get a new queued `WeeklyLessonTutorUnavailableMail` (parent copy neutral — no suspension or permit reason — says the slot can be kept or ended, and "Nothing was charged" only when no Pending or Captured payment exists; tutor copy says no strike). A lesson with a Pending attempt already in flight is not cancelled (advisor-driven, see §6 item 2). Low 1: the missed-window email no longer says nothing was paid. Low 6: `ResumeRecurringSlot` re-checks the policy on the locked row (stale-model race test). Low 7: a real schedule assertion (`0 * * * *`, `onOneServer`, `withoutOverlapping`). 9 new tests; a test that fails without the change proves R104 (6 of 7 fail with the action change stashed).
- **4e (`cp/4e-auto-charge`, PR #20)** — R100, R101, R102:
  - Gateway: `PaymentGateway::saveCard` and `chargeSavedCard`, `SavedCard` DTO, `FakePaymentGateway` (outcome by card token; the declining test card throws `PaymentCaptureException('card_declined')`). `app/Services/Payments/*` touched under R100 (frozen only after CP5). `LedgerService` and `LessonStateMachine` are called, not edited (R94).
  - `payments` is one row per charge attempt: migration `2026_09_27_100000_restructure_payments_for_attempts` (`attempt_no`, `unique(lesson_id, attempt_no)`, partial unique `payments_one_live_per_lesson` WHERE `status <> 'failed'`, FK `payment_method_id` restrict); `Lesson::payment()` is the latest attempt; idempotency key `lesson:{id}:attempt:{n}` (ADR-013). Rehearsal has 0 rows in `payments`, `lessons`, `ledger_entries` and `recurring_slots`, so the migration converts nothing.
  - `recurring:charge` (hourly, `onOneServer`, `withoutOverlapping`): attempt 1 at T−48h, retries T−36h and T−24h (at least an hour apart, only before the start); success = payment captured + `reserved → confirmed` with the HOLD through `LedgerService::hold()` in the transition closure + counter reset; third failure = `cancelled_payment_failed`, counter +1, slot paused with `payment_failed` at 2 consecutive; a reserved lesson whose start has passed is cancelled `charge_window_missed` and does not count against the slot (the 4c carry; this sweep runs with no gateway bound).
  - Parent resume of a `payment_failed` pause after replacing the declined card (`RecurringSlotPolicy::resume`, `ResumeRecurringSlot`, `WeeklySlotController::resume`, `learners/Show.vue`); `SaveTestCard` goes through the fake driver and clears `last_failed_at`.
  - R102 emails: charged (parent), charge failed with the retry time in the parent's timezone (parent), cancelled for payment (parent and tutor), paused for payment (parent, tutor and now the admin — closes the 4d review note). `SendLessonConfirmedMail` skips the parent copy on `reserved → confirmed`; the first version suppressed the tutor's too and the suite caught it (1304 of 1305), fixed in code; the one existing test rewritten is `LessonLifecycleMailTest` "reserved lesson is confirmed" (tutor only, parent asserted not mailed).
  - `BookLessonTest`: three anonymous gateway doubles gained stubs.
  - Docs on `main` (`[skip ci]`): DATA_MODEL v1.5 `payments` note, DECISIONS ADR-013..015, CHECKPOINTS 4e bullet and two acceptance annotations, CYCLE-LOG (advisor, DECISIONs, VERIFICATION, REVIEW, BLOCKER, HANDOFF, a timestamp-correction NOTE).
- 4d (PR #19, `c0e2fc3`): portal — weekly-slot screens, fake add-card, slot emails. 4c (PR #18, `dd34988`): `recurring:generate`. 4b (PR #17, `496a76d`): slot actions and the Filament resource. 4a (PR #16, `6375469`): foundation migrations, enums, models, `SlotCalculator`. Detail: CYCLE-LOG.


## §4 Decisions and by whom
- CC (7b): `supports_attendance_webhooks` does not gate the webhook endpoint; it decides what the lesson page shows (DECISION, owner may overrule).
- CC (7b): the three wiring files outside the video folders (`bootstrap/app.php`, `AppServiceProvider.php`, `config/video.php`) are read as 7b's own area under R123; the reviewer is asked to test that reading, and the PR is held if it disagrees.
- CC (cycle 07, step 1): the HOW-WE-WORK version stamp reads 1.3 (R119 lists a changelog line but not the stamp); logged as a DECISION.
- Owner rulings for this cycle: R119–R126 (PLAN r1).
- CC (4e): retry arithmetic; HOLD written inside the confirm transition; missed-start sweep with `charge_window_missed`; email rules; parent resume needs a usable card with `last_failed_at` null; `payments` uniqueness and key — all logged as DECISIONs 2026-09-25 and covered by ADR-013..015; the four money decisions were consulted in one mid-build consult, the others were not put to the advisor separately (disclosed).
- Owner (2026-09-26): R104 option 1 and fix loop 1 (`update — 1: option 1; 2: option 1`, PLAN r2 R104–R106).
- CC (4e fix loop): the R104 branch does not fire while a Pending attempt exists (a narrow departure from R104's "never charged", to avoid stranding a payment the gateway may have taken); no fix loop 2 and no self-merge for the re-review's Low (rule 6). Both logged as DECISIONs 2026-09-26.
- CC (4e review): **withdrew** the 4b–4d reading of HOW-WE-WORK rule 6 for anything touching money or product behaviour — the Medium is not fixed in a loop without an owner ruling (DECISION 18:41). The owner may reinstate it for Lows.
- CC (4d, 4c, 4b): as in the earlier STATUS — fake add-card gated on `APP_ENV != production`; permit hold and 24h rule; floor at today; skip mail keyed on `notified_at`; `SlotCalculator::local()` public; notice-end leaves the slot `active`/`paused` until 4c's daily run; the `lessons` recurring key excludes `slot_paused` rows so resume can refill. All advisor-consulted where the plan required it.
- Owner/planner rulings carried: R1–R85 except R82 (withdrawn), R86–R103.


## §5 Why stopping
Not stopping: 7b is verified on the head. Next is push, PR and the fresh-subagent review. Nothing here needs the owner (R109). This is the step-boundary write.

## §6 Mismatches
1. **Full suite is now over the 10-minute stall line (BLOCKER disclosure, not a halt).** `php artisan test` took 683 s on the 7b head (608 s earlier the same sub-cycle). It completed green. It grows about 10 s per sub-cycle, so raising the stall line for this one command, or splitting the suite, needs a planner/owner ruling before it becomes a permanent breach. Nothing is blocked today.
0. **PRD §11 rows (R126) not written — refused by the harness's auto-mode classifier.** Both rows are ready (Owner action 1). ADR-018 already records the same decisions, and nothing in the build reads the PRD table. Note for the planner: the PRD had no D-09 row at all (§11 stops at D-08), so R126's "row D-09" is a new row.
1. **R101 vs invariant 5 — closed in fix loop 1 (R104).** A due weekly lesson whose tutor is not `bookable()` is now cancelled uncharged as `tutor_unavailable`. **`composer test` could not run as one unit on this machine:** under PowerShell the `rtl:check` script's `bash` resolves to WSL (which has no bash), under Git Bash `composer` is not on PATH. Each constituent step was run instead (`config:clear`, Pint, PHPStan, `bash scripts/rtl-check.sh`, `php artisan test`, `php artisan ledger:verify --no-interaction`) — CYCLE-LOG VERIFICATION 01:10.
2. **Open Low from the re-review — carried to CP5 by the owner (R108).** (a) **In-flight exception:** if a run dies after `begin()` committed a Pending row but before the gateway call, and the tutor is suspended before the next run, the next run charges the lesson (Confirmed, HOLD, "charged" mail) — or on a decline counts a failure toward pausing the slot. The alternative (cancel over the Pending row) can strand a payment the gateway took with no ledger entry. The proper fix is a gateway status lookup by idempotency key, which the fake gateway cannot answer; a CP5 carry. (b) Note: a `Captured` payment with a failed hold (`NeedsReview`) leaves the lesson `reserved`, so a later run could cancel it `tutor_unavailable` over money taken; `ledger:verify` flags it and the email drops "Nothing was charged"; optionally exclude Captured at the guard. (c) Notes: the 'account deleted' test proves the scope's `deleted_at` clause only (the real `AnonymizeUser` path cannot reach R104); the tutor mail would go to a trashed user's address (skip when `$tutor->trashed()`); the "keep or end" wording is asserted only as "still active".
2a. **Fake gateway bound on rehearsal only (invariant 16 stopgap, R107 / ADR-016) — replaces the old "no gateway outside tests" mismatch.** After the step-7 deploy, rehearsal's hourly `recurring:charge` will fake-charge due weekly lessons and cancel a reserved lesson whose start has passed as `charge_window_missed`; rehearsal has 0 `recurring_slots` and 0 `lessons`, so nothing is expected to happen until someone sets one up there. Production and staging stay unbound. CP5's registry replaces the binding (CHECKPOINTS CP5). **The single-booking gap is CLOSED by 6a (PR #22, squash `1ede299`, cycle 06 r1):** a parent now books a trial or regular lesson from a tutor profile; on rehearsal it takes effect at the 6c deploy. Never pass `artisan --env=…` on a server (ADR-016).
2b. **Push-to-deploy on rehearsal is unproven (R111).** The `rehearsal` push at 02:12 deployed nothing in 15 minutes; the release that landed at 05:58 UTC matches the owner's Deploy click, and the shell cannot show which branch Forge used. CLAUDE.local.md lines 8 and 27 are therefore unchanged and rehearsal deploys stay owner-pressed. Rehearsal has 0 `lessons`, `payments`, `payment_methods` and `recurring_slots` (`db:show --counts`, 10:02), so the hourly fake-gateway `recurring:charge` has nothing to act on until someone creates a weekly slot there.
2f. **Push-to-deploy on rehearsal looks proven now (R117), but CLAUDE.local.md is NOT amended.** `git push origin 1ede299:refs/heads/rehearsal` at 11:16:35 (machine clock); the shell's first `git log -1` at 11:16:41 still showed `c34979a` and the next at 11:17:13 showed `1ede299`; the `current` symlink is dated Sep 26 07:16 UTC (= 11:16 machine clock, UTC+4). That is a deploy landing on CC's push alone, so R117/R111 permit amending CLAUDE.local.md lines 8 and 27. I made that edit, the harness's permission classifier refused the next command as self-modification, and I reverted the edit with the same two strings to leave the file exactly as the owner wrote it. The file is unchanged. See Owner action 4. Why the 02:12 push did not deploy is unexplained (the owner may have switched the Forge branch since).
2g. **R117 check not runnable: a tutor profile returns 200.** Rehearsal has 0 `tutor_profiles` (`db:show --counts`; 1 user, the admin) and `/tutors` lists none, so `/tutors/1`, `/tutors/2`, `/tutors/5` and `/tutors/999` all return 404 by design (`TutorProfile::bookable()`). The new booking route is present (`route:list --path=book`: `tutors/{tutor}/book`, `tutors.book`) and a guest on `/tutors/1/book` gets 302 to `/login`. The 200 on a profile and the whole booking screen therefore have not been exercised on rehearsal; only the code and routes are there. The demo tutors are not seeded (seeded once on first deploy; the seeder is never run again on this server, CLAUDE.local.md). **CLOSED 2026-09-26 16:29: seeder run once, 7 tutors, profile 200, guest /book 302 to /login (log VERIFICATION).**
2c. **`vue-tsc --noEmit` (`npm run types:check`) is not run by CI or `composer test`** (`composer types:check` is PHPStan); it already reports one error on `main` in `weekly-slots/Create.vue:146` (`form.errors.slot`). 6a's `Book.vue` avoids the same error with a typed cast. Low, not a 6a defect.
2d. **6a disclosures.** (a) `BookLessonController::quote()`/`decide()` repeats `BookLesson`'s trial rule (first non-cancelled lesson for the pair, same `freeingSlotValues` query) for display only; the tests prove the two agree, and `BookLesson` still decides. (b) `store()` refuses plainly ("Booking is not available yet.") when no `PaymentGateway` is bound, which is production today. (c) Two refusals are deliberately generic ("That lesson cannot be booked.") so a missing and a non-bookable tutor look the same. (d) **Open Low, carried to CP5:** the quote-token check is check-then-act; two genuinely concurrent submits could pass it before either books. A lock is wrong here (it would span the gateway capture and hide the pending Payment row, R77); full closure is an expected-quote check inside `BookLesson`, which R116 puts out of scope. Deferred line below.
2e. **Review 2 Lows (6a, carried, non-blocking):** see the REVIEW entry in CYCLE-LOG for the numbered list; none touches money, and no Medium or High is open.
3. **NeedsReview exposure (disclosed):** if a parent skips a lesson between the gateway taking the money and the confirmation, the payment stays `captured` with no hold, an exception is reported and `ledger:verify` flags it after five minutes; there is no refund path until CP5. The reviewer's related wording point: the skip email says the skip was free although the parent was charged.
4. **Idempotency rests on the real gateway (review 1, 9):** two overlapping runs would both call the gateway with the same key; and a card replaced while an attempt is pending reuses that attempt with the same key and a different card, which a real gateway will likely reject each hour until the start. CP5 must decide this (key per card, or void the pending attempt on card replace).
5. **R102 paused-recipient note — closed in 4e** (the admin now gets the paused email for `payment_failed`); review note: that mail reads reason and counter at send time, so a quick resume can suppress it or show stale numbers.
6. **Test-count history for 4e:** first full run 1304 of 1305 (the confirmed-mail regression), then 1305/1305 on `c98c390`, now 1306/1306 on `d5f6209` (+ the §4 test). A one-time timestamp error in my 4e CYCLE-LOG entries was corrected by a NOTE (they read 18:10/18:40 when the clock was about 17:55–18:05).
7. **Carried Lows from the 4c review:** (a) a missed daily run can still lose an occurrence inside the permit-hold window; widening the 24h no-hold window to `recurring_charge_lead_hours` would survive one missed run (`GenerateSlotLessons.php` `walk()`); (b) resume sets `generated_until` to today; (c) the skip mail is queued inside the transaction; (d) tests: `generated_until` after the 29th run, a second run on the 30th, non-default grace and charge lead, west-of-UTC continuity, per-slot mail-failure isolation; (e) anonymising an account does not end its active slots; (f) approved tutors have no permit-renewal path in the product (CP1 finding 11).
8. **Carried from the 4d review (accepted):** clearing `end_effective_on` on an immediate end empties the admin infolist "Tutor notice ends"; a parent ending the slot before a queued tutor-notice mail is sent gets two mails; switching the time choice resets a hand-typed start date.
9. **Earlier mismatches, resolved:** R98's literal lessons index vs resume (built with `AND cancel_reason IS DISTINCT FROM 'slot_paused'`); the `lessons.payment_method_id` FK moved to 4a with `payment_methods`; `payments.payment_method_id` FK now in 4e; the 4a and 4b carried Lows all dispositioned by 4d/4e.
10. **Review artefact, not a finding:** the reviewer saw no ADR-013..015 on the branch; they are on `main` (`git show origin/main:docs/DECISIONS.md | grep -c "ADR-01[345]"` = 3).


## §7 Next step / Owner actions
Owner action 1: **PRD §11 rows D-02 and D-09** — docs/PRD.md is read-only for CC and the harness refused my R126 edit. Option 1 (Recommended): you replace the D-02 row's status cell with "**Decided (owner, 2026-09-26, R120): multiple gateways via the registry (§12); Stripe first, provisional on D-04**" and add a row after D-08: `| D-09 | Transactional mail provider | Postmark (ADR-007) | **Decided (owner, 2026-09-26, R120): Postmark**; wiring is a later step, rehearsal stays on the log mailer |`. Non-blocking; reply `update` when done, or say nothing and it stays in §6.
(Work continues with 7a; this is not a halt.)

## §8 Programme board — cycle 07 r1
| Sub-cycle | State | Branch | PR | Review verdict | Merge |
|---|---|---|---|---|---|
| step 1 docs | done (PRD rows outstanding) | `main` | — | — | docs-only |
| 7a demo tutors | done, on rehearsal | `cp/7a-demo-tutors` | #23 | no Medium or High; 4 Low (3 fixed) | self-merged 3548a04 (R123) |
| 7b video registry | verified, PR next | `cp/7b-video-registry` | — | pending | — |
| 7c room lifecycle | not started | — | — | — | — |
| 7d lesson page | not started | — | — | — | — |
| 7e reports | not started | — | — | — | — |
| 7f trial CTA + timeline | not started | — | — | — | — |
| 7g branding | not started | — | — | — | — |

Resume count: **0 of 8**.

## CP4 carried list (from cycle 04)
1. ~~`composer.lock` drift~~ — fixed in `b4e36d9` (R103a).
2. PR #15 finding 14 — stays on the CP8 list unless 4b touches `AnonymizeUser` (R103b).
3. Owner action A (branch protection) — optional, open. Owner action E — revisit CP8.
4. At the end of the programme (R103d): D-02 (payment gateway), D-04 and D-09 are listed once for the owner.

## Deferred (out of v1 scope — do not build)
- Lesson packs / subscriptions / credits
- Group classes
- Courses / self-paced content
- Learning plans, homework, assessments
- B2B organisations / tenancy
- Multi-currency
- WhatsApp notifications
- Meilisearch
- Gateway `refund()` call in `CancelLesson` — CP5 with D-02; until then the `refund` ledger account is the record of money owed back.
- Expected-quote check inside `BookLesson` (closes the concurrent-submit race behind 6a's `quote_token`) — CP5, with the gateway work; `BookLesson` is outside R116.

## Carried to CP8 hardening checklist (R71)
- Search pagination: tutor search pages in memory after the slot check; revisit at "a few hundred approved tutors" (same trigger as the Meilisearch item).
- Refunding a captured-but-expired lesson (the payments-row-before-transition race) needs the real payment gateway — `FakePaymentGateway` cannot refund. Revisit when CP5 wires a real driver; the 4e NeedsReview exposure (§6 item 3) is the same family.
- The R53 onboarding live-as-you-type trial-price preview (Owner action E) — revisit if the UX loss is judged to matter.
- Three latent round-2 PASS-WITH-NOTE observations — after-commit-callback misreporting on a confirmed-and-paid lesson; `VerifyLedger` crashing (not failing cleanly) against a database missing `payments`; non-`PaymentCaptureException` gateway errors leaving a payment `pending` with no reconciliation path.
- A multi-process booking race test (R74) — box 3's concurrency proof is bounded to in-process tests only.
- Learner-side overlap constraint (R76 — not in v1).
- A reconciliation path for a `SuspendTutorForStrikes` failure after the triggering cancel/skip already committed — built as swallow-and-log in both `CancelLesson`/`SkipLesson`; the reconciliation command itself is still not built.
- PR #14 re-review's 5 PASS WITH NOTE items (stale docblock reference, minor code duplication between `CancelLesson`/`SkipLesson`'s guard pattern, an asymmetric test-coverage gap) — non-blocking, revisit at CP8.
- PR #15 review finding 14: `AnonymizeUser`'s admin-only check is Action-internal, not behind a route-level Policy/Gate — add an outer authorization layer when an admin UI/Filament resource is wired up around it.
- ~~`payments.lesson_id` UNIQUE vs. the 3-retry recurring-charge design (R78)~~ — resolved in 4e (ADR-013): one row per attempt, `unique (lesson_id, attempt_no)` plus a partial unique index for one live row.
- `btree_gist` on `trustutor-production` — check when R41's recipe builds it at CP8 (R85).
