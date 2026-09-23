# STATUS — cycle 04 r6 (CP3) — written 2026-09-23 22:10 — Context: 138.9k/200k (69%, auto-compacts at 84%; measured via `get_usage` — v1.2/R67)
Tests: **1029/1029 passed, 4668 assertions** (full `composer test`, `COMPOSER_PROCESS_TIMEOUT=1200 composer.bat test`, on `cp/3c-booking` working tree, uncommitted — see §6 for why composer's default wrapper needed the override) · `ledger:verify`: "Ledger OK: every lesson sums to zero." (same run) · Advisor: 5 this session (step-3 design; gateway/commission/overlap; capture-sequencing/concurrency; pre-`BookLesson` full-suite + missing-pieces + drafting gotchas; post-`BookLessonTest` test-hardening + logging-overdue flag; cycle 04 total 15) · Review: 3a done (0 Medium/High); 3b done — 14 findings, 1 FAIL(Low, `phpunit.xml` scope), resolved by owner ruling R70, merged · 3c: `BookLesson`, `ExpireUnpaidLessons` sweep, `Payment` model, and their full test coverage built and green; not yet committed, not yet PR'd.

## §1 Git state
`main` = `2a9bd86`, unchanged since the last rewrite (four commits ahead of the previous head, all pushed, `origin/main` matches). Branch `cp/3c-booking` (created from `main` at `2a9bd86`) now has **substantial uncommitted work** — this STATUS rewrite is the first action after closing a real deviation (see §6): a full sub-cycle of code and tests landed with no CYCLE-LOG entries, no STATUS rewrite, and no commit until now, flagged by the fifth advisor consult's own item 0. `git status --porcelain` on `cp/3c-booking`: modified `app/Models/Lesson.php`, `app/Services/Lessons/LessonStateMachine.php` (docblock-only), `app/Support/Money.php`, `database/factories/LessonFactory.php`, `docs/CYCLE-LOG.md`, `routes/console.php`, `tests/Feature/Lessons/LessonSchemaTest.php`, `tests/Unit/MoneyTest.php`; untracked `app/Actions/Lessons/` (`BookLesson.php`), `app/Console/Commands/ExpireUnpaidLessons.php`, `app/Enums/PaymentStatus.php`, `app/Exceptions/BookingException.php`, `app/Exceptions/PaymentCaptureException.php`, `app/Models/Payment.php`, `app/Services/Payments/` (`PaymentGateway.php`, `FakePaymentGateway.php`, `PaymentCaptureResult.php`), `database/factories/PaymentFactory.php`, two migrations (`create_payments_table`, `add_tutor_overlap_exclusion_to_lessons`), `tests/Feature/Lessons/BookLessonTest.php`, `tests/Feature/Lessons/ExpireUnpaidLessonsTest.php`. **This STATUS write is immediately followed by a local commit of all of it** on `cp/3c-booking` (not pushed yet — no PR open, nothing to push to). PR #12 unchanged, merged. trustutor-rehearsal unchanged, behind `main` by design (R41).

## §2 Step map (cycle 04 r6 — CP3, an autonomous programme, R50)
1. `cp/3a-lows` — **merged** `b75d6e9` (PR #11).
2. `cp/3b-state-machine` — **merged** `5471bcb` (PR #12).
3. `cp/3c-booking` — **in progress.** `BookLesson` action (R53/R56), `Payment` model + factory, `FakePaymentGateway` behind the `PaymentGateway` interface (unbound outside tests, registry deferred to CP5 per invariant 16), the `ExpireUnpaidLessons` 15-minute unpaid sweep + its schedule entry, and 19 new tests (14 `BookLessonTest` + 5 `ExpireUnpaidLessonsTest`) plus 2 new `LessonSchemaTest` constraint tests — all built and green, not yet committed. Still open: the box-3 real concurrency suite, the `Onboarding.vue` trial-price preview unification, the DATA_MODEL v1.4 deviation note (blocks PR), then PR #13 + review + halt for backend-dev GO (R55).
4. `cp/3d-cancellation` — not started.
5. `cp/3e-dashboards-emails` — not started.
6. Programme end and rehearsal deploy — not started — halt with the Deploy action.

## §3 What changed this run
- Built `App\Models\Payment` + `PaymentFactory` (casts `amount`→`Money`, `status`→`PaymentStatus`, `raw_response`→array; a `failed()` factory state), `App\Enums\PaymentStatus`, `App\Exceptions\{BookingException,PaymentCaptureException}`, `App\Services\Payments\{PaymentGateway,FakePaymentGateway,PaymentCaptureResult}`, and the `payments` migration (`lesson_id` unique — at most one payment row per lesson in v1) plus the `lessons_tutor_no_overlap` GiST exclusion migration (`tsrange(starts_at, ends_at, '[)')`, `WHERE status NOT IN (LessonStatus::freeingSlotValues())`).
- Built `app/Actions/Lessons/BookLesson.php`: validates learner ownership, re-fetches the tutor via `TutorProfile::bookable()` (invariant 5), checks the price band, checks the tutor teaches the subject, matches the requested `starts_at` against `SlotCalculator::forTutor()` by UTC timestamp, decides trial-vs-regular from `whereNotIn(status, freeingSlotValues())` (not `type`), freezes price/commission/policy on the row, opens the lesson `pending_payment` via `LessonStateMachine::open()`, translates any DB constraint violation into a `BookingException` with a message specific to which of the three constraints fired, captures payment, writes a `payments` row (captured or failed) before touching lesson status again, and transitions to `confirmed` + HOLD or `expired` — guarding both transitions against a sweep that already moved the lesson with `catch (LessonTransitionException)`.
- Built `app/Console/Commands/ExpireUnpaidLessons.php` (`lessons:expire-unpaid`): sweeps `pending_payment` lessons older than 15 minutes (`UNPAID_TIMEOUT_MINUTES` class constant, not a settings key) **with no captured payment** — the `whereDoesntHave('payment', captured)` clause exists specifically so the sweep cannot race a just-captured, not-yet-confirmed lesson into an unrecoverable captured-with-no-HOLD state. Registered `everyMinute()->onOneServer()->withoutOverlapping()` in `routes/console.php`. Added `Lesson::payment(): HasOne`.
- Wrote `tests/Feature/Lessons/BookLessonTest.php` (14 tests) and `tests/Feature/Lessons/ExpireUnpaidLessonsTest.php` (5 tests), and extended the pre-existing `tests/Feature/Lessons/LessonSchemaTest.php` with 2 tests giving `lessons_tutor_no_overlap` its own deterministic savepoint proof (replacing an earlier informal `tinker` check) plus a back-to-back/half-open-bounds positive case.
- Consulted the advisor twice more this sub-cycle (fourth and fifth overall this session — see CYCLE-LOG for both in full) and acted on every item from both: the fourth shaped `BookLesson`'s drafting before it was written (UTC-matching by timestamp not string, fresh-tutor re-fetch, trial query not filtering on `type`, constraint translation outside the transaction, sweep-race guards on both transition arms); the fifth, called after the first 12 `BookLessonTest` tests were already green, flagged that logging had fallen overdue (item 0) and three concrete test/code gaps — a tautological freeze test (fixed: now proves before/after/before across a second booking with all-new setting values), a missing vacated-slot rebooking proof (fixed: two new tests), and a wrong constraint-violation message for `lessons_one_trial_per_pair` (fixed: its own message, no longer sharing `lessons_tutor_slot_unique`'s).
- Full `composer test`: 1029/1029 passed, 4668 assertions, `ledger:verify` green (up from 1008/1008 at the last STATUS write — 21 new tests, no regression, no shrinkage).

## §4 Decisions and by whom
- Owner/planner rulings carried, unchanged from last write: R70, R71, R72, R73.
- CC decisions carried from the design phase (second/third consults, all logged previously): gateway resolution unbound/test-only (invariant 16, CP5-deferred), commission-split formula (`intdiv` + subtraction, remainder to platform), tutor overlap via additive `EXCLUDE USING gist`, `tsrange` not `tstzrange`, `payments` row committed before `LessonStateMachine::transition()` runs (not inside its `$work` closure), no learner-side overlap constraint in v1.
- **New CC decision** (per advisor item 6, fourth consult): a gateway-declined capture writes its own `payments` row (`status: failed`, `failure_reason` from the exception) before the lesson is transitioned to `expired` — gives support/disputes a record of *why* a lesson expired, and the sweep's predicate already has to query `payments` regardless (CYCLE-LOG `2026-09-23 20:10` DECISION).
- **New CC note** (in-bounds check, not a decision): `LessonStateMachine.php` (not frozen — frozen after CP3 merges, hasn't) received a docblock-only edit (`@throws QueryException` on `open()`); confirmed via `git diff` that no behavioural line changed (CYCLE-LOG `2026-09-23 20:12` NOTE).
- Five advisor consults this session total, all carrying the R63 phrase; cycle 04 total now 15.

## §5 Why stopping
**Not stopping — continuing immediately after this write with the mandated local commit**, then resuming the advisor's own stated build order for the rest of 3c: the box-3 real concurrency suite, the `Onboarding.vue` trial-price preview unification, the DATA_MODEL v1.4 deviation note, then PR #13. This STATUS rewrite exists because the fifth advisor consult's item 0 flagged a real logging deviation (a full sub-cycle of code landed with zero CYCLE-LOG entries and no STATUS rewrite) — now corrected: six CYCLE-LOG entries were added this write covering everything since the fourth consult (the carried-forward failed-capture DECISION, the LessonStateMachine NOTE, the fifth ADVISOR consult itself, the test-hardening NOTE, the sweep-build NOTE, the overlap-constraint-test NOTE, and a fresh VERIFICATION), in causal order, before this STATUS write.

## §6 Mismatches
- **New, non-blocking, environmental:** `composer test`'s `"test"` script does not call `Composer\Config::disableProcessTimeout` (unlike `"dev"`/`"ci:check"`), so it is subject to composer's default 300-second process timeout. This machine's full-suite duration is noisy under Windows/Herd (observed 185s–541s across runs with identical pass/fail results each time) and tripped the timeout twice this sub-cycle as the suite grew past ~1020 tests. Not a hang or regression — confirmed by running `php artisan test` directly twice in a row with fully consistent green results. Worked around with `COMPOSER_PROCESS_TIMEOUT=1200 composer.bat test`, the same fix already precedented earlier this session (CYCLE-LOG `2026-09-23 19:00`) for this exact issue. No code or CI change needed; noting here in case CI's own timeout needs the same override later.
- **Corrected, this write:** a full sub-cycle's worth of code (the entire §3 list above) was built and verified green with no interim CYCLE-LOG entries and no STATUS rewrite — a real deviation from HOW-WE-WORK's "log while working" / "rewrite STATUS.md at every step boundary" rules, self-identified mid-sub-cycle and explicitly flagged by the fifth advisor consult (item 0). Closed by this write: six CYCLE-LOG entries added in causal order, this STATUS rewrite, and an immediately-following local commit.
- Carried, unchanged: invariant 16 / plan-repo conflict (gateway unbound outside tests, registry deferred to CP5 — designed gap); PLAN step 3's overlap text read as the new exclusion constraint, not the pre-existing index alone (additive migration, nothing merged in 3b edited); the new migration's `CREATE EXTENSION IF NOT EXISTS btree_gist` confirmed on local Herd Postgres 18 only, not yet on CI or rehearsal (CI will prove or refute it the first time the migration runs there); rehearsal behind `main` by design (R41); branch protection on `main` not set (R23, Owner action A, non-blocking).
- **Still open, non-blocking, carried:** Owner action C (confirm no learner-side overlap DB constraint needed in v1) — CC is proceeding on the recommended default (leave as-is) per the "When unsure" rule.

## §7 Next step / Owner actions
No owner action blocks continuing. Two recommended-default items remain open for confirmation, non-blocking (CC proceeds on the recommended default per the "When unsure" rule and will not wait on either):

- **Owner action C (recommended, non-blocking, carried):** confirm no learner-side overlap DB constraint is needed in v1 — only the tutor side is enforced. **Recommended: leave as-is (Recommended)** — the simplest option that keeps invariants intact; reply `update` when reviewed.
- **Owner action D (recommended, non-blocking, new):** none required at this boundary — flagged only so the record is explicit: CC is continuing straight through this STATUS write into the local commit and the remaining 3c build items (box-3 concurrency suite, `Onboarding.vue` unification, DATA_MODEL note) without a halt, since none of R55's backend-dev GO conditions (PR opened + reviewed) are met yet. **Recommended: no action needed (Recommended)** — reply `update` when reviewed, or say if a halt is wanted sooner.

Carried, optional, non-blocking: Owner action A (R7 branch protection on `main`); Owner action B (`.claude/settings.local.json` install, ADR-002).

The standing clear action is **not** raised here — v1.2/R67 clears only at cycle END, and CP3 (cycle 04) is still in progress.

## §8 Programme board — CP3 (R50)
| Sub-cycle | State | Branch | PR | Review verdict | Merge |
|---|---|---|---|---|---|
| 3a Lows pass | **merged** | `cp/3a-lows` | [#11](https://github.com/rizwanoor80/eLearning-Platform/pull/11) | 0 Medium/High | `b75d6e9` (R50) |
| 3b state machine + ledger | **merged** | `cp/3b-state-machine` | [#12](https://github.com/rizwanoor80/eLearning-Platform/pull/12) | 14 findings, 1 FAIL(Low, scope) — resolved by R70 | `5471bcb` (R50, item 12 closed by owner ruling) |
| 3c booking | `BookLesson` + sweep + `Payment` + 19 new/2 extended tests built and green, uncommitted until this write's follow-up commit; box-3 concurrency suite, `Onboarding.vue` unification, DATA_MODEL note still open — halts for backend-dev GO (R55) before merge | `cp/3c-booking` | — | — | — |
| 3d cancellation | not started | `cp/3d-cancellation` | — | — | — |
| 3e dashboards, emails, deletion | not started | `cp/3e-dashboards-emails` | — | — | — |
| Programme end + rehearsal deploy | not started — halt | — | — | — | — |

Resume count: **0 of 8** (unchanged — no stop condition hit this session).

## Deferred (out of v1 scope — do not build)
- Lesson packs / subscriptions / credits
- Group classes
- Courses / self-paced content
- Learning plans, homework, assessments
- B2B organisations / tenancy
- Multi-currency
- WhatsApp notifications
- Meilisearch

## Carried to CP8 hardening checklist (R71)
- Search pagination: tutor search pages in memory after the slot check; revisit at "a few hundred approved tutors" (same trigger as the Meilisearch item).
- Refunding a captured-but-expired lesson (the payments-row-before-transition race, §3 above) needs the real payment gateway — `FakePaymentGateway` cannot refund. Revisit when CP5 wires a real driver.
