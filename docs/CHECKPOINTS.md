# CHECKPOINTS — v1.2 build plan

_Each checkpoint = one or more plan cycles, one branch, one PR, fresh-subagent review, full test suite green, STATUS.md rewritten. Acceptance criteria are written as tests where possible. The planner cuts PLAN.md revisions from this file; Claude Code executes only what the current plan authorises._
_Backend dev reviews CP3, CP4, CP5. UI dev reviews every CP's front-end. CP0–CP6 is the launchable MVP._
_v1.2 (owner ruling 2026-09-17, PRD §12): configurability items added to CP1 (site settings, document types, bank details, admin users), CP2 (pages, content blocks, feature toggles), CP5 (payment-gateway registry), CP6 (video-provider registry with capability flags); CP8's legal-page task becomes content entry. CP0 is unchanged and was already in progress when v1.2 was approved._

---

## CP0 — Foundation
**Goal:** empty but runnable app with roles, CI, and the skeleton every later CP hangs on.

Tasks
- Laravel 13 (ADR-003) on Laravel Herd Pro (native Windows, PHP 8.4, per D-07 / ADR-001/002): PostgreSQL 18, Redis, Horizon (installed, not run locally — `queue:work` in dev), Reverb, Inertia 3 + Vue 3, Tailwind 4, Filament 5, Pest.
- `users` with `role` enum; registration + login + email verification for `account_owner` and `tutor` (separate entry points `/register` and `/tutor/register`). Admin created by seeder only.
- `role` column + Policies. No permission package.
- `Money` value object (integer fils) with tests.
- `settings` table + `Settings` facade with cache.
- Seeders: curricula, subjects (≈25 common ones across the 4 curricula), price bands, settings, admin user.
- Layout shell with `dir` on `<html>` and a lint rule (Stylelint/ESLint or a simple grep in CI) rejecting `ml-`, `mr-`, `pl-`, `pr-`, `left-`, `right-`, `text-left`, `text-right` utilities. Logical properties only.
- GitHub Actions: Pint, `composer test`, `npm run build`, the RTL grep, on every PR. Branch protection on `main` (available on the Free plan because the repo is public — D-08), CODEOWNERS = Rizwan. The empty public repo already exists: `https://github.com/rizwanoor80/eLearning-Platform.git` (created by Rizwan, 2026-09-16).
- docs/STATUS.md, docs/CYCLE-LOG.md, docs/DECISIONS.md initialised; `CLAUDE.local.md` created from the example and git-ignored.

Acceptance
- [ ] A parent can register, verify email, log in, and see an empty dashboard.
- [ ] A tutor can register and lands on the onboarding wizard placeholder.
- [ ] Admin can log into Filament.
- [ ] `Money` unit tests: add, subtract, percentage, format ("AED 120.00"), no float leakage.
- [ ] CI fails on a component that uses `ml-4`; passes with `ms-4`.

---

## CP1 — Tutor onboarding and approval — done (cycle 02 sub-cycles 1a–1c; hardened in cycle 03: the R36 status lifecycle, `ReinstateTutor`, re-vetting, `submitted_at`)
**Goal:** a tutor can complete onboarding; admin can approve; only approved tutors with valid permits are "bookable".

Tasks
- `document_types` table + seeder (permit scan, ID/passport, qualifications, police clearance) + Filament CRUD (name, description, required, active, sort). `tutor_documents.type` → `document_type_id`.
- Onboarding wizard (multi-step, resumable): personal → permit number/expiry → one upload step per active document type (private disk, signed URLs) → bank details (bank name, account name, IBAN, optional SWIFT — `encrypted` casts, masked display) → subjects (curriculum × subject × level range, derives `level_tier`) → rate (band-validated against highest tier; shows the derived trial price) → bio/headline/video → availability (weekly template + exceptions) → tutor agreement acceptance (records `agreement_version` from the published `tutor_agreement` page; until CP2 ships pages, a seeded placeholder page version 1 stands in).
- `TutorProfile` statuses + `bookable()` scope. Approval blocked while any required document type lacks an `accepted` document.
- Filament: approval queue with document viewer, accept/reject per document, "request changes" note, approve/reject/suspend actions. Audit log entries.
- Site settings: `settings.group` column; Filament settings editor with tabs platform / site / mail / features (keys per DATA_MODEL.md); logo and favicon uploads; `head_scripts` admin-only. Layout, page titles and email sender/footer read from settings.
- Admin users: Filament resource to create and disable `admin` users (no self-registration route), audited; the seeded admin remains.
- Scheduled job: permit expiry warnings (30d, 7d) and auto-hide on expiry.
- Emails: submitted for review, changes requested, approved, rejected, permit expiring/expired — sender name/address and footer from settings.

Acceptance
- [x] Rate outside band is rejected on save with a clear message showing the band.
- [x] Tutor with `approved` status and expired permit is NOT returned by `bookable()`.
- [x] Document URLs are signed and expire; direct path access returns 403.
- [x] Admin approve action sets `approved_by/approved_at` and writes an audit log row.
- [x] Onboarding cannot complete without `agreement_accepted_at`, and `agreement_version` equals the page version published at acceptance.
- [x] Adding a required document type in Filament adds a wizard step and blocks approval until it is accepted; setting it inactive removes both — no code change.
- [x] `bank_iban` raw column value is not the plaintext; the tutor sees only the last four; the admin payout view sees the full value. _(Tutor masking and encryption done; the admin payout view with the full value is deferred to CP5 — R25. CP1 is done except that clause.)_
- [x] Changing `site_name` in settings changes the layout title and the next email's sender name without a deploy.
- [x] A non-admin cannot reach the admin-users resource; creating an admin writes an audit row.

---

## CP2 — Learners, search, tutor profile, match request — done (cycle 02 sub-cycles 2a–2c; cycle 03 added year groups as a controlled list — R33, ADR-004 — the permit cap on slots — R34 — and budget labels as content blocks — R35)
**Goal:** a parent can add learners, find a tutor, and request a match.

Tasks
- Learner CRUD under the account owner (adult students get a self-learner created at registration).
- `SlotCalculator` service: given tutor rules + exceptions + existing lessons + active recurring slots + settings, returns bookable hourly slots for the next N days in a requested timezone. Unit-tested heavily.
- Search page: filters (curriculum, subject, year group, price range, day/time, min rating), sort (rating, price). Postgres query with indexes. Only `bookable()` tutors with ≥1 slot in the next 14 days.
- Tutor public profile page with rate, trial price, next available slots.
- Match request form → Filament queue → admin picks 1–3 tutors → email with suggestions. Hidden everywhere when `features.match_requests` is off.
- `pages` + `page_versions`: Filament editor with markdown body, preview, "Publish" (new version row, bumps `pages.version`); public routes `/terms`, `/privacy`, `/tutor-agreement`, `/safeguarding`, `/about`, `/contact` rendered from the current version; footer links from the same list. Seeded with placeholder text marked "DRAFT — replace before launch".
- `content_blocks`: Filament editor; homepage renders hero, how-it-works and FAQ from blocks; seeded placeholders.
- Feature toggles enforced: `reviews` and `messaging` gate their routes and UI (the features themselves arrive in CP7; the gates exist now so CP7 lands behind them).

Acceptance
- [x] Search never returns a non-bookable tutor (test with suspended / expired-permit / no-availability fixtures).
- [x] `SlotCalculator` excludes blocked exceptions, includes extra exceptions, excludes booked lessons, excludes an active recurring slot's weekday/time even 10 weeks out, respects `booking_min_lead_hours` and `booking_max_days`.
- [x] Match request status flows open → suggested; email contains only bookable tutors; with `features.match_requests=false` the form route returns 404 and the entry points are absent.
- [x] Publishing a page writes a `page_versions` row and increments `pages.version`; the public route shows the new body immediately; the previous version remains readable in admin.
- [x] A tutor who accepted agreement version 1 keeps `agreement_version = 1` after version 2 is published.
- [x] Editing `home_hero_title` in admin changes the homepage without a deploy.

---

## CP3 — Booking, trial, cancellation — merged, deployed to `trustutor-rehearsal` (cycle 04 sub-cycle 3b merged `5471bcb`: `LessonStateMachine` full edge matrix per DATA_MODEL with a test per allowed and forbidden transition; append-only `ledger_entries` with HOLD/RELEASE/REFUND and the zero-sum invariant, `ledger:verify` wired into CI; `SETTLE`/`PAYOUT` declared, unused until CP5; sub-cycle 3c merged `df0aa88` (PR #13): `BookLesson` action per R53/R56 — slot validated, type decided, trial price frozen from `TutorProfile::trialPrice()`, band-validated, seven values frozen, `pending_payment` lesson created, overlap and one-trial-per-pair held by DB indexes plus a transactional check; `FakePaymentGateway` capturing instantly behind the CP5 driver interface; capture → `confirmed` + HOLD; 15-minute unpaid-expired sweep; onboarding preview unified; sub-cycle 3d merged `ddb31ac` (PR #14): cancellation, strikes; sub-cycle 3e merged `a369af5` (PR #15): dashboards, lifecycle emails, reminders, R54 deletion — deployed `e5d31f1` to rehearsal 2026-09-24, 7 migrations ran; rehearsal `ledger:verify` clean 2026-09-25 — CP3 closed per R57, on the owner's ruling that the CC-run result closes step 6)
**Goal:** a parent can book a single lesson (trial or regular); the lifecycle up to `confirmed` works; cancellations follow PRD §4. Money is stubbed with a fake gateway. **Backend dev reviews.**

Tasks
- `LessonStateMachine` with every state from DATA_MODEL.md including `reserved`; transitions guarded; each fires an event.
- `BookLesson` action: validates slot via `SlotCalculator`, decides `type` (trial if no prior non-cancelled lesson for this learner–tutor pair), computes price (trial discount applied), band-validates, freezes commission and cancellation policy, creates `pending_payment` lesson, DB-level overlap and one-trial-per-pair protection.
- `FakePaymentGateway` driver that captures instantly (real driver in CP5).
- `CancelLesson` / `SkipLesson` actions with 24h rule, tutor-cancel strike logic, `expired` sweep job for unpaid bookings.
- Parent dashboard: upcoming lessons. Tutor dashboard: today/upcoming.
- Emails: confirmed, cancelled, skipped, reminders (24h, 1h) via scheduled job.
- **Carried in from cycles 02–03:** `BookLesson` re-checks `TutorProfile::bookable()` and the permit at booking time (not only at search); the trial price is frozen on the lesson from `TutorProfile::trialPrice()` — the one place it is computed — and the onboarding trial-price preview (which rounds `100 − pct`) is unified with it; learner delete-guards and the user-delete behaviour are decided; the overlapping-offer transactional check; `whereDate` in `bookable()` is reviewed against the new index; search pagination moves out of memory. The plan for CP3 opens with the R42 Lows pass (year-group `withTrashed()` 500 and `sort` bound first).

Acceptance
- [x] Every transition in the state diagram has a passing feature test; every invalid transition throws. _(3b, `5471bcb`: `LessonStateMachineTest.php`, every DATA_MODEL edge including the CP4–CP6 ones per R51; independently re-verified by the fresh-subagent review against the actual diff.)_
- [x] First booking for a learner–tutor pair is `trial` at the discounted price; second is `regular` at full price; a cancelled trial does not consume the trial. _(3c, `df0aa88`: `BookLessonTest.php` "books the first lesson for a pair as a discounted trial and the second as full-price regular" and "does not let a cancelled trial consume the pair's one trial"; independently re-verified by the round-2 fresh-subagent review.)_
- [x] Double-booking the same tutor slot fails under concurrent requests (test with DB constraint). _(3c, `df0aa88`: `BookLessonTest.php` "refuses a second learner racing the same tutor slot at the database, not just the app-level check, leaving exactly one confirmed lesson and one HOLD behind (R74 in-process concurrency proof)" — scope bounded to in-process tests per R74, no cross-process runner.)_
- [x] Parent cancel at 25h → `refunded` path; at 23h → tutor-paid path. Tutor cancel at 23h → strike created. _(3d: `CancelLessonTest.php` — parent-refund, parent-release, tutor-strike and exact-deadline-boundary tests, all `LedgerService::sum()===0`.)_
- [x] Changing `cancel_window_hours` in settings does not change the outcome for an already-booked lesson. _(3d: `CancelLessonTest.php` "is unaffected by a settings change after booking" on both the parent-refund and tutor-strike boundaries — invariant #11, the window is read from the lesson row, never `Settings::get()`.)_
- [x] 3 strikes within 90 days → tutor `suspended`, admin emailed. _(3d: `CancelLessonTest.php` "suspends a tutor and emails an admin after 3 strikes within 90 days", with a null-actor audit row and `Mail::assertQueued(AdminTutorSuspendedMail::class, ...)`; a strike older than 90 days does not count; a 3rd strike on an already-suspended tutor is a silent no-op.)_

---

## CP4 — Recurring weekly slots (money on the fake gateway only)
**Goal:** parent can set up, skip, and end a weekly slot; tutor can end with notice; occurrences generate on a rolling horizon as `reserved`; and, on the fake gateway only, each `reserved` occurrence is auto-charged from the saved card with retries, cancellation on the final failure, slot pause and counter reset (pulled forward from CP5, cycle 05 R88). Real gateway capture, the registry, payouts and the rest of CP5 stay in CP5. **Backend dev reviews.**

Tasks
- `recurring_slots` model + `CreateRecurringSlot` action (validates weekday/time against tutor availability and existing slots, requires a learner who has completed a trial with this tutor OR admin override, requires a saved card — stubbed in this CP).
- `recurring:generate` nightly job with idempotent `(slot, starts_at)` keys; collision handling (skip + email).
- `EndRecurringSlot` (parent: immediate; tutor: with `recurring_tutor_end_notice_days`), `SkipLesson` for `reserved` occurrences, admin pause/end in Filament.
- Parent portal: learner page shows weekly slots; tutor calendar shows slots as fixed blocks; "Set up a weekly slot" entry points on tutor profile and learner page (the trial-report CTA is wired in CP6).
- Emails: slot created / ended / occurrence skipped by collision.

Acceptance
- [ ] Running `recurring:generate` twice produces no duplicate lessons.
- [ ] A weekly slot on Tue 17:00 Asia/Karachi generates lessons at the correct UTC times and blocks that slot in `SlotCalculator` for a Dubai parent. _(Blocking half done in 4a: active and paused slots block, ended ones do not, `ends_on`/`end_effective_on` honoured inclusive, `SlotCalculatorTest`; the generation half lands in 4c.)_
- [ ] Tutor end with 7-day notice: `reserved` lessons beyond day 7 cancelled without strike; a `reserved` lesson at day 3 skipped by tutor → strike only if inside 24h.
- [ ] Parent end: all `reserved` lessons cancelled free; `confirmed` lessons (seeded via fake gateway) follow §4.
- [ ] Two parents cannot create active slots on the same tutor weekday/time. _(DB half done in 4a: `recurring_slots_live_unique` covers active and paused, `RecurringSlotSchemaTest`; the clean-message translation lands in 4b.)_

---

## CP5 — Ledger, real payments, saved cards, auto-charge, payouts
**Goal:** real money in, escrow ledger, saved card and 48h auto-charge for weekly slots, release on report (report itself comes in CP6 — trigger wired, tested via stub), dispute settlement math, payouts out. **Backend dev reviews.**

Tasks
- `LedgerService` with `hold / release / refund / settle / payout`, append-only, zero-sum assertion, `goodwill` entries allowed to push `platform` negative per lesson.
- `payment_gateways` registry: Filament resource (code, name, mode test/live, credentials as encrypted json entered field-by-field and shown masked, `supports_saved_cards`, `is_active` with the one-active partial index); `PaymentGateway` resolved from the active row via a manager class; `fake` row for local/CI that refuses to activate in production; a gateway without saved-card support cannot be activated while an active weekly slot exists; switching to `live` mode is a named authorisation.
- Real `PaymentGateway` driver — Stripe first (per D-02): checkout, `saveCard`, `chargeSavedCard` with idempotency key = lesson id, webhook capture, refund. Idempotent webhook handling. Keys entered by the owner in the admin (Owner action), never in the repo. Other drivers (Tap, Telr, PayPal one-off) are separate cycles on request.
- `payment_methods`: add/replace card page in parent portal; expired-card detection.
- `recurring:charge` hourly job with retry schedule from settings, failure emails, slot pause after threshold, counter reset on success.
- `ledger:verify` artisan command + nightly schedule + alert email on failure.
- Tutor earnings page: five buckets (pending, on hold, available, processing, paid to date), payout history.
- Filament: payout batches (generate for period → CSV → mark paid, `bank_reference` required), refunds from lesson view, settings editor, weekly slot pause/resume.
- Receipts page for parents (with VAT line); auto-charge receipt email.

Acceptance
- [ ] `ledger:verify` passes on a seeded DB with 50 mixed-state lessons including two settled disputes with goodwill.
- [ ] Refund after cancel ≥24h produces correct entries and a gateway refund call; webhook replay does not double-refund.
- [ ] `recurring:charge` on a `reserved` lesson at T−48h → `confirmed` + HOLD; simulated failures at 48/36/24 → `cancelled_payment_failed`; second consecutive failure pauses the slot; a success resets the counter.
- [ ] Payout batch only includes tutors above `payout_min`; cannot be marked paid without a bank reference; marking paid writes `payout` entries; buckets move available → processing → paid correctly.
- [ ] `settle()` with 60% refund / 100% tutor pay yields a negative `platform` entry and the lesson still sums to zero.
- [ ] Commission setting change does not alter any existing lesson's `commission_amount`.
- [ ] Activating a different gateway row changes which driver the container resolves; credentials never appear in logs, the Filament form shows them masked, and `fake` cannot be activated when `APP_ENV=production`.
- [ ] With no active gateway, booking shows an admin-notice page and creates no `payments` row.

---

## CP6 — Lesson room, attendance, progress and trial reports
**Goal:** the lesson happens on the platform, the parent gets a report, and a good trial turns into a weekly slot.

Tasks
- `video_providers` registry: Filament resource (code, name, encrypted credentials shown masked, `supports_embed`, `supports_attendance_webhooks`, `is_active` one-active index); `VideoRoomProvider` resolved from the active row; `fake` row for local/CI.
- `VideoRoomProvider` interface + Daily driver (embed + webhooks): create room at T−15 (scheduled job), per-party join tokens, webhook for join/leave → `tutor_joined_at / learner_joined_at`, close at end + 10 min. `lessons.room_provider` records the provider per lesson.
- Lesson page for both parties: embedded room when the active provider `supports_embed`, otherwise a join link; when the provider lacks attendance webhooks, "I've joined" buttons set the joined-at timestamps manually. Join buttons active T−10.
- `in_progress → completed` sweep job; no-show marking actions per PRD §4; admin `provider_failure` action.
- Progress report form (5 fields; +3 trial fields when `type = trial`) → `SubmitProgressReport` action → email to parent → `LedgerService::release`. 72h auto-release job with late flag.
- Trial report email and portal view carry the "Set up a weekly slot" CTA pre-filled with the tutor, subject, and recommended frequency.
- Parent portal: learner timeline of reports; "recent focus areas" from last 5 reports.
- Stretch: embedded tldraw whiteboard on the lesson page.

Acceptance
- [ ] Room is created exactly once per lesson even if the job runs twice.
- [ ] Both join → `completed` at end; only tutor joins → student no-show path after grace; only learner joins → tutor no-show path after grace.
- [ ] Trial report without the three trial fields is rejected; regular report with them is rejected.
- [ ] Submitting a report releases escrow exactly once; second submit is rejected; auto-release after 72h sets the late flag.
- [ ] Parent receives the report email within one queue cycle; the trial email contains a working weekly-slot link.
- [ ] With a link-mode provider fixture (`supports_embed=false`, `supports_attendance_webhooks=false`) the lesson page shows a join link and manual "I've joined" sets the timestamps; with Daily the timestamps come only from the webhook.

---

## CP7 — Messaging, reviews, report button, notifications polish
**Goal:** families and tutors can talk safely; ratings drive search; anyone can flag a problem.

Tasks
- Conversations created on first booking (paid or reserved); messages with Reverb live badges; masking of emails/phones/URLs until `first_lesson_completed_at`.
- Reviews after `completed*`; `rating_avg / rating_count` maintained; visible on profile and search sort.
- Report button on tutor profile, conversation, and lesson page → `abuse_reports` → Filament safeguarding queue with suspend-tutor / suspend-account actions (history preserved) → admin email.
- Notification centre (in-app list) + all remaining emails from PRD §8.
- Admin read-only conversation view.

Acceptance
- [ ] A message containing a phone number before the first completed lesson is stored masked and shown masked to the recipient.
- [ ] Cannot open a conversation without a lesson between the parties.
- [ ] One review per lesson; rating aggregates update; search sorts by rating.
- [ ] Filing a report creates an open `abuse_reports` row, emails admin, and does not notify the reported party.
- [ ] Suspending a tutor from the safeguarding queue removes them from search immediately and cancels their `reserved` lessons without deleting anything.

---

## CP8 — Disputes, admin hardening, soft launch
**Goal:** the platform can be operated by Rizwan and the manager without touching code.

Tasks
- Disputes: open within 48h, pauses release (lesson → `disputed`, tutor bucket "on hold"), Filament resolution with two dials (parent refund %, tutor pay %), defaults per PRD §2.10, → `LedgerService::settle` + gateway refund.
- Filament: lessons index with force-cancel / force-complete / provider-failure (note + audit), tutor detail with strikes/flags/late reports, dashboard widgets (lessons this week, revenue, reports overdue, permits expiring, failed charges, open reports).
- Legal page content: Rizwan/counsel enter the final terms, privacy, tutor agreement, safeguarding, about and contact text in the CP2 pages editor and publish (no code; the "DRAFT" placeholders must be gone before the checklist is signed).
- Production hardening: rate limiting, backups verified restore, error tracking, uptime check, `.env` review, seed data removed, webhook signature verification confirmed on both providers.
- Launch checklist run with 5 real tutors and 3 friendly families, including one full trial → weekly slot → auto-charge → report → payout loop.

Acceptance
- [ ] Dispute on a lesson in `completed` blocks auto-release; two-dial resolution leaves ledger zero-sum; trial dispute defaults to 100% refund.
- [ ] Force-complete by admin writes an audit row and follows the normal release path.
- [ ] Restore from last night's backup on a scratch server succeeds.
- [ ] End-to-end: parent registers → adds child → books trial → trial completes → trial report → weekly slot → auto-charge → lesson → report → payout batch → tutor sees "paid to date". Green as a single feature test.
- [ ] No page body still contains "DRAFT"; the active payment gateway is in `live` mode under a named authorisation; the active video provider has real credentials.
- [ ] Soft-launch checklist signed off by Rizwan.

---

## After v1 (do not start without a new PLAN.md)
Lesson packs · WhatsApp notifications · Meilisearch · group classes · courses · learning plans · homework · multi-currency · B2B organisations.
