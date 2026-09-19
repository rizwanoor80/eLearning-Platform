# STATUS — cycle 04 r1 (CP3) — written 2026-09-21 07:20
Tests: 619 tests / 2906 assertions on `main` `b75d6e9` (`COMPOSER_PROCESS_TIMEOUT=1200 composer.bat test`, Pint, PHPStan 0, RTL, `npm run build` green) · Advisor: cycle 04 consulted 3 times so far (3a design 05:20, mid-build 05:50, pre-PR 06:00); cycle 03 consulted 11 times (closed) · Review: 3a done (0 Medium/High, 9 Low notes/findings, 3 fixed in fix loop 1)

## §1 Git state
`main` = `b75d6e9` (PR #11, sub-cycle 3a, merged under R50) plus docs; no open branch or PR. trustutor-rehearsal runs `50d53ee` and is behind `main` — expected: push-to-deploy is OFF (R41), nothing is deployed. Branch protection on `main`: **not set** (owner action, carried, R23). `.claude/settings.local.json` (R14): not created — optional, carried.

## §2 Step map (cycle 04 r1 — CP3, an autonomous programme, R50)
1. `cp/3a-lows` — [done except the pagination item] PR #11 merged `b75d6e9`: the R42 Lows pass — ten Lows fixed with a test each; `whereDate` reviewed and kept (EXPLAIN); search pagination not built (Owner action 1).
2. `cp/3b-state-machine` — [in progress] `lessons`, `tutor_strikes`, `ledger_entries`, `LessonStateMachine` (R51, R52).
3. `cp/3c-booking` — [not started] `BookLesson`, `FakePaymentGateway`, money freezing (R53, R56). **Halts for the backend-dev GO before merge (R55).**
4. `cp/3d-cancellation` — [not started] `CancelLesson`, `SkipLesson`, strikes.
5. `cp/3e-dashboards-emails` — [not started] dashboards, CP3 emails, reminders, R54 deletion behaviour.
6. Programme end and rehearsal deploy — [not started] docs per R57; halt with the Deploy action.

## §3 What changed this run
- Cycle 03 closed (STATUS 04:00). PLAN cycle 04 r1 committed (`883c963`); rulings R50–R57 logged as ADVICE.
- **3a built** on `cp/3a-lows`. Step 2 Lows: a soft-deleted learner now counts as using a year group (the admin Delete 500 is gone) and the `year_group.deleted` audit row is written after the delete; the year-group form's tier options and rule come from the stored curriculum (a crafted `curriculum_id` no longer changes them) and `sort` is bounded at 32767; an adult student's curriculum change with no year group now clears the old curriculum's year group; the `Onboarding.vue` unmatched-legacy note travels with its row. Step 3 Lows: `ReviewTutorDocument` decides on the document as it is now (a repeat decision is a no-op) and only an active, required type takes an approved tutor off the bookable list; the required-document fan-out reports and counts a failing tutor and keeps going; a datetime string for the same permit date no longer resets an accepted scan; the reset-password notification is queued encrypted; `DisableAdminUser` compares ids as integers. `whereDate` in `bookable()`: EXPLAIN on 60,000 temp-table rows shows the same `Bitmap Index Scan` as a plain `>` — left as is.
- Tests: +12 (619 / 2900), a red proof (3 of 12 pass against the old code), no existing test changed.

## §4 Decisions and by whom
- Owner rulings (via the planner) for this programme: R50 CP3 authorised, merge rule and stop conditions; R51 state machine declared in full; R52 ledger pulled into CP3; R53 money rules; R54 deletion policy; R55 backend-dev GO for 3c; R56 booking limits from settings; R57 docs authorised. Carried: R32–R49.
- CC decisions: 3a pre-declared file list (CYCLE-LOG 05:24); search pagination not built (05:22); adult-learner fix as clear-not-refuse (06:04); `whereDate` kept (06:06).
- Merge decision for 3a (mine, under R50): R50's rule does not list plan-completeness, so 3a self-merges when the review has no open Medium/High and CI is green, and is shown as "done except the pagination item".

## §5 Why stopping
Not stopped: the programme is running (sub-cycle 3a is at the review step). Halts are planned at 3c (backend-dev GO) and at the programme end (rehearsal deploy).

## §6 Mismatches
- **Search pagination not built (Owner action 1):** the "has an open slot in the next 14 days" filter runs in PHP (`TutorSearch` → `SlotCalculator::forTutors`), so `total`/`lastPage` need every candidate's slots and SQL paging would give wrong pages and totals. The plan text ("moved out of memory into the query") cannot be met as written.
- **Adult-learner Low fixed differently from its wording:** cleared, not refused (existing R33 test; CYCLE-LOG 06:04). Files not pre-declared: `app/Actions/Learner/UpdateLearner.php`, the directory `tests/Feature/Lows/`.
- **Test gap:** the stale-page "Delete refused writes no audit row" branch is unreachable from Livewire; only the `after()` hook is proven. The `Onboarding.vue` change is proven by the build and `vue-tsc` only (R20, no browser).
- **Own mistakes (CYCLE-LOG 06:02):** design-consult log entries committed to the branch first (reverted there, mirrored on `main`); the branch was pushed before its VERIFICATION was logged; earlier this run a zero-byte `.git/index.lock` was removed after checking it was stale.
- **Branch protection on `main` not set** (owner action, carried; R23).
- **CP1 box 7 admin-payout clause deferred to CP5** (R25) — "CP1 done" stays qualified. CP0's acceptance boxes in CHECKPOINTS are still unticked (R44 authorised CP1/CP2 only).
- **Rehearsal server:** behind `main` by design; settings has 41 rows (the plan's "20" was stale); the server release shows `D storage/app/.gitignore` and `D storage/app/private/.gitignore` (zero-downtime storage symlink artefact, not touched); `Broadcasting reverb` is configured, not run.
- **Composer timeout:** plain `composer test` can hit composer's 300 s default; use `COMPOSER_PROCESS_TIMEOUT=1200`.
- **Carried for CP3's later sub-cycles:** trial price frozen from `trialPrice()` and the onboarding preview unified (R53); `BookLesson` re-checks `bookable()` and the permit (R56); learner delete-guards and user anonymisation (R54); overlapping-offer transactional check; last-writer-wins page edits and raw-markdown agreement text (2c Lows); `footerPages` query per response; the "pending_review tutor missing a newly required document" gap.

## §7 Next step and owner actions
The programme continues by itself: review and merge of 3a, then 3b. Nothing is required from you to keep going. One decision is open and does not block anything; each action ends: reply `update` when done.

- **Owner action 1 (optional, any time before launch): decide what to do about search pagination.** Option 1 (Recommended): keep the in-memory paging as it is — v1 launches with a few tutors, so it costs nothing today — and revisit it when approved tutors exceed a few hundred; I will add the number to the CP8 hardening checklist. Option 2: accept approximate totals and short pages by paging in SQL before the slot check (a search behaviour change; needs a plan). Option 3: cap the candidate set at a fixed number (silently hides tutors past the cap). Reply `update` when done.

Carried, optional: Owner action A (R7 branch protection on `main`, exact GitHub UI steps in cycle 01's CYCLE-LOG 18:35 BLOCKER); Owner action B (`.claude/settings.local.json`, ADR-002).

## §8 Programme board — CP3 (R50)
| Sub-cycle | State | Branch | PR | Review verdict | Merge |
|---|---|---|---|---|---|
| 3a Lows pass | **merged** (done except the pagination item) | `cp/3a-lows` | [#11](https://github.com/rizwanoor80/eLearning-Platform/pull/11) | 1 round; 14 verdicts, 0 Medium/High, Lows: 3 fixed in fix loop 1, rest carried | `b75d6e9` (R50) |
| 3b state machine + ledger | in progress | `cp/3b-state-machine` | — | — | — |
| 3c booking | not started — halts for the backend-dev GO (R55) | `cp/3c-booking` | — | — | — |
| 3d cancellation | not started | `cp/3d-cancellation` | — | — | — |
| 3e dashboards, emails, deletion | not started | `cp/3e-dashboards-emails` | — | — | — |
| Programme end + rehearsal deploy | not started — halt | — | — | — | — |

Resume count: 0 of 8 (3a ran in the opening session). Cycle 03 ("CP2.5") is complete: PRs #8–#10 merged (`a6281eb`, `530b5e5`, `6214ad4`), END in CYCLE-LOG. Cycle 02 (CP1–CP2) is complete: PRs #2–#7.

## Deferred (out of v1 scope — do not build)
- Lesson packs / subscriptions / credits
- Group classes
- Courses / self-paced content
- Learning plans, homework, assessments
- B2B organisations / tenancy
- Multi-currency
- WhatsApp notifications
- Meilisearch
