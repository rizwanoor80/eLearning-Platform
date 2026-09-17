# PRD — 1-on-1 Tutoring Platform v1

_Version 1.2 · Sept 2026 · Owner: Rizwan_
_Scope rule: if a feature is not in this document, it is not in v1._
_v1.1 adds: trial lesson, recurring weekly slot with auto-charge, two-dial dispute resolution, tutor balance buckets, frozen cancellation policy, report-to-admin button, RTL-ready layout._
_v1.2 adds (owner ruling 2026-09-17): the configurability principle (§12) — integrations behind admin-entered keys (payment gateways, video providers), legal and marketing content edited in the admin, site settings and branding, configurable onboarding document types, tutor bank details, admin-user management, feature toggles._

---

## 1. Users and roles

| Role | Who | Can |
|---|---|---|
| **Parent** | Account owner for one or more minor learners. Pays. | Add learners, search/book tutors, request a match, book trials, set up weekly slots, pay, message tutors, view reports, rate lessons, open disputes, report a tutor |
| **Adult student** | Learner who is their own account owner (18+) | Same as parent, for themselves |
| **Tutor** | Vetted, admin-approved teacher | Manage profile/subjects/availability, run lessons, file progress and trial reports, message booked families, view earnings/payouts, report a family |
| **Admin** | Rizwan + manager (further admins created in Filament by an existing admin, audited) | Approve tutors, handle match requests, resolve disputes and abuse reports, run payouts, edit settings, pages, content, integrations |

Internally, "Parent" and "Adult student" are the same role (`account_owner`); the difference is whether the learner record is a minor under that account or the owner themselves.

**Minor safety rule:** a minor never has a login. All communication, booking, and reports go to the account owner.

---

## 2. Core journeys

### 2.1 Tutor onboarding
1. Tutor registers (email + password, email verification).
2. Onboarding wizard: personal details → UAE private-tutor permit number + expiry → document uploads, one step per **required document type** configured in the admin (defaults: Emirates ID / passport, qualifications, police clearance / good-conduct certificate; admin can add, drop or make optional without code) → bank details for payouts (bank name, account name, IBAN, optional SWIFT — stored encrypted, shown masked) → subjects (curriculum × subject × year-group range) → hourly rate (must fall within the platform band for the highest level they teach) → bio, headline, optional intro video link → weekly availability → accept the tutor agreement (the current published version of the admin-edited page; the version number accepted is recorded on the profile).
3. Status `pending_review`. Admin reviews documents in the approval queue, can request changes (note to tutor), approve, or reject.
4. On approval: profile becomes searchable. On permit expiry: profile auto-hidden until a new permit is uploaded and re-approved.

### 2.2 Parent finds a tutor (browse path)
1. Register → add learner (name, year group, curriculum, school optional, notes).
2. Search: filter by curriculum, subject, year group, price range, day/time availability, minimum rating. Sort by rating or price. Only approved tutors with a valid permit and at least one open slot in the next 14 days appear.
3. Profile page: headline, bio, subjects, rate, trial price, rating and reviews, next available slots.
4. Pick a slot → confirm learner + subject → pay → lesson confirmed. Email to both parties.

### 2.3 Parent requests a match (match path)
1. Form: learner, curriculum, subject, year group, goals (free text), preferred days/times, budget band.
2. Admin sees it in the match queue, picks 1–3 suitable tutors, sends suggestions.
3. Parent gets an email with the suggested profiles and books a trial via the normal booking flow.

### 2.4 Trial lesson
1. The first lesson between a given learner and a given tutor is a **trial**: same 60-minute format, priced at the tutor's rate minus `trial_discount_pct` (default 50%). Commission applies to the discounted price, so tutor and platform share the discount.
2. One trial per learner–tutor pair, ever. A second booking with the same tutor is a regular lesson.
3. The trial report (§2.7) carries three extra fields: suitability, recommended frequency, focus areas. It is emailed with a **"Set up a weekly slot"** button.
4. Trial guarantee: a dispute on a trial lesson defaults to full refund to the parent unless the tutor has evidence of student no-show. Tutor payment on that trial is decided separately by admin (§2.10).

### 2.5 Recurring weekly slot
1. From a trial report, a tutor profile, or the learner page, the parent chooses **"Weekly slot"**: weekday + time (from the tutor's availability), subject, start date, optional end date. Requires a saved card.
2. The platform generates lessons for that slot on a rolling horizon (`recurring_horizon_weeks`, default 4). Generated lessons start in state `reserved` (slot held, not yet charged).
3. `recurring_charge_lead_hours` (default 48) before each lesson, the saved card is charged automatically → lesson becomes `confirmed`. Email receipt to parent.
4. Charge failure: retry at 36h and 24h. If still failing, the lesson is cancelled (`cancelled_payment_failed`), the parent is emailed, and after two consecutive failures the weekly slot is `paused` until the parent updates the card.
5. Parent can **skip** any `reserved` lesson free of charge (before it is charged) or cancel a `confirmed` one under the normal rules (§4). Parent can **end** the slot any time; future `reserved` lessons are cancelled free, `confirmed` ones follow §4.
6. Tutor can end a weekly slot with `recurring_tutor_end_notice_days` (default 7) notice: `reserved` lessons beyond the notice period are cancelled without strike; anything inside the notice period follows §4 including strikes.
7. An active weekly slot blocks that weekday/time in the tutor's calendar indefinitely (beyond the materialised horizon), so no one else can book it.
8. No packs, no credits, no subscriptions: each lesson is an individual charge on the saved card. Skipping a week costs nothing.

### 2.6 Lesson
1. Lesson room is created automatically 15 min before start by the **active video provider** (configured in the admin, §12; Daily.co is the only driver built in v1). "Join" buttons become active 10 min before start for both parties.
2. Both join via the platform: embedded in our lesson page when the provider supports embedding, otherwise as a join link to the provider's app. Join/leave events are recorded as attendance from the provider's webhooks when it offers them; a provider without attendance webhooks falls back to each party pressing "I've joined" on the lesson page (weaker evidence — disputes on such lessons default to admin judgment).
3. Room closes at scheduled end + 10 min grace.
4. Lesson status becomes `completed` when the scheduled end passes and both parties joined; otherwise it follows the no-show rules in §4.

### 2.7 Progress report (and trial report)
1. Tutor is prompted at lesson end and by email. Report due within 24h.
2. Standard fields (all required, short): topics covered · what went well · what to work on next · homework set · engagement 1–5.
3. Trial lessons additionally require: suitability (good fit / partial fit / not a fit) · recommended frequency (1, 2, or 3 lessons per week) · focus areas for the first month.
4. On submit: emailed to the parent immediately; appears in the parent portal under the learner's timeline; tutor's escrow for that lesson is released.
5. If no report after 72h: escrow auto-releases, tutor gets a "late report" flag (3 flags in 90 days → admin review).

### 2.8 Rating
After a lesson is `completed`, the parent can rate it once (1–5 + optional comment). Published immediately on the tutor profile. Tutor cannot reply in v1.

### 2.9 Messaging
- A conversation exists between an account owner and a tutor only once a lesson has been booked (paid or reserved) between them.
- Before the first completed lesson, emails, phone numbers, and URLs in messages are masked.
- Admin can read any conversation (disclosed in terms; minor safety).
- Every profile, conversation, and lesson page has a **Report** button (reason + text) that opens an abuse report in the admin safeguarding queue. Admin can suspend a tutor or account immediately without deleting history.

### 2.10 Dispute
Account owner can open a dispute within 48h of a lesson's scheduled end (reason + text). Escrow release for that lesson pauses (tutor balance shows it as **on hold**). Admin resolves with **two independent dials**: parent refund (0–100% of price) and tutor payment (0–100% of tutor amount). The platform absorbs any difference — a goodwill refund does not automatically unpay the tutor, and a tutor no-show does not require the parent to have complained. Defaults: trial lesson → 100% refund; tutor no-show → 100% refund, 0% tutor pay; student no-show → 0% refund, 100% tutor pay.

---

## 3. Pricing and money

- **Currency:** AED only. All amounts stored as integer fils.
- **Lesson length:** 60 minutes only.
- **Price bands** (admin-configurable, per curriculum × level tier). Illustrative defaults:

| Level tier | Examples | Band (AED/hr) |
|---|---|---|
| Lower secondary | Year 7–9, MYP 1–3, CBSE 6–8 | 80–150 |
| Exam years 1 | GCSE/IGCSE Y10–11, MYP 4–5, CBSE 9–10 | 100–200 |
| Exam years 2 | A-Level, IB DP, CBSE 11–12 | 130–260 |

- Tutor sets **one** hourly rate. It must fall within the band of the highest tier they teach. Band check happens on save and again at booking time.
- **Displayed price = what the parent pays.** Tutor receives price minus commission. Trial price = rate × (1 − `trial_discount_pct`).
- **Commission:** flat percentage (default 25%), frozen on the lesson at booking time. Changing the setting never touches existing lessons.
- **Frozen policy:** `commission_pct`, `cancel_window_hours`, and grace minutes are copied onto every lesson when it is created. Settings changes apply to new lessons only.
- **Payment:** single bookings are paid at booking. Weekly-slot lessons are charged automatically 48h before start from the saved card. Either way, capture creates an escrow hold in the ledger.
- **Escrow release:** on progress-report submission (or 72h auto-release). Release creates ledger entries: escrow → tutor balance (tutor amount) and escrow → platform (commission).
- **Tutor balance buckets** (shown separately, never as one "wallet"): **Pending** (held in escrow, lesson not yet reported) · **On hold** (disputed) · **Available** (released, not yet in a payout batch) · **Processing** (in a batch not yet paid) · **Paid to date**.
- **Payouts:** weekly (Monday) for tutors whose available balance ≥ AED 200. Admin generates a batch → CSV → bank transfer → marks paid with the bank reference. A batch is not "paid" without a reference. No automated payout rails in v1.
- **Payment gateway:** behind a `PaymentGateway` interface with `charge`, `saveCard` (tokenise), `chargeSavedCard`, `refund`, `verifyWebhook`. Gateways are **registry entries managed in the admin** (§12): each has its keys (encrypted, entered by the owner, never in the repo), a test/live mode and an active flag; the app resolves the active entry at runtime, and a gateway marked as not supporting saved cards cannot be made active while weekly slots are enabled. Adding a gateway to the registry does not build its driver — each provider still needs its own driver and tests. First driver: Stripe (UAE), pending the backend dev's saved-card and entity-eligibility check (D-02); Tap / Telr / PayPal drivers on request (PayPal for one-off payments only — it does not suit saved-card auto-charge). Platform collects; tutors are paid from platform funds. (Legal note: confirm with counsel whether holding funds this way needs any licence — not a lawyer.)
- **Receipts** carry the legal entity name, TRN and address from site settings; VAT % from settings.

---

## 4. Booking rules

| Event | Rule |
|---|---|
| Single booking window | ≥ 12h before start, ≤ 30 days ahead |
| Slot | Must sit inside tutor availability, on the hour, not overlapping another lesson or an active weekly slot |
| Skip a `reserved` (unpaid) lesson | Free, either party, no strike unless tutor does it < 24h before |
| Parent cancels `confirmed` ≥ 24h before | Full refund to original payment method |
| Parent cancels `confirmed` < 24h before | No refund; tutor is paid in full |
| Tutor cancels `confirmed` (any time) | Full refund to parent; if < 24h before, tutor gets a strike |
| Student no-show | Tutor may mark no-show after 15 min; lesson charged, tutor paid |
| Tutor no-show | Parent may mark no-show after 10 min; full refund, tutor strike |
| Reschedule | Treated as cancel + new booking under the same rules |
| Strikes | 3 strikes in 90 days → tutor suspended pending admin review |
| Provider outage | If the video provider is down for the lesson window, admin can mark `provider_failure`: full refund, no strike, no tutor pay, no rating |

All transitions are enforced by a single `LessonStateMachine` service. No other code path may change `lessons.status`.

---

## 5. Parent portal

Pages: **Dashboard** (upcoming lessons, join buttons, unread messages, weekly slots) · **Learners** (each learner: weekly slots, lesson history, report timeline, a simple "recent focus areas" list pulled from the last 5 reports) · **Receipts** · **Payment method** (one saved card) · **Messages**.
Adult students see the same portal with themselves as the only learner.

---

## 6. Tutor portal

Pages: **Dashboard** (today's lessons, join buttons, reports due) · **Calendar / availability** (weekly template + date exceptions; active weekly slots shown as fixed blocks) · **Weekly slots** (end with notice) · **Profile** · **Earnings** (five buckets, payout history, next payout date) · **Messages**.

---

## 7. Admin (Filament)

Tutor approval queue · Match requests · Lessons (search, force-cancel, force-complete, mark provider failure — all with note + audit) · Weekly slots (pause/end with note) · Disputes (two-dial resolution) · Abuse reports (safeguarding queue, suspend actions) · Payout batches · Settings (commission %, bands, trial discount, cancel window, grace minutes, report due/auto-release hours, recurring lead/horizon/retries, payout threshold/day, VAT %) · **Site settings** (site name, logo, favicon, contact details, social links, footer text, head scripts for analytics, legal entity name / TRN / address, currency code and symbol, default timezone, mail sender name / address / reply-to / support address / email footer) · **Feature toggles** (match-request path, reviews, messaging) · **Pages** (terms, privacy, tutor agreement, safeguarding, about, contact — versioned, published from the admin) · **Content blocks** (homepage hero, how it works, FAQ) · **Document types** for tutor onboarding · **Payment gateways** and **Video providers** registries (keys encrypted and masked, test/live, active flag) · **Admin users** (create, disable; audited) · Audit log · Read-only access to conversations.

---

## 8. Notifications (email only in v1)

| Trigger | To |
|---|---|
| Registration / verification | Parent, tutor |
| Tutor approved / changes requested / rejected | Tutor |
| Booking confirmed / cancelled / rescheduled / skipped | Both |
| Weekly slot created / paused / ended | Both |
| Auto-charge succeeded (receipt) / failed (update card) | Parent |
| Lesson reminder (24h and 1h before) | Both |
| Report due (at lesson end, +20h) | Tutor |
| Progress report / trial report (with "Set up a weekly slot") | Parent |
| New message | Recipient |
| Dispute opened / resolved | Both + admin |
| Abuse report received | Admin |
| Payout paid | Tutor |
| Match suggestions ready | Parent |
| Permit expiring (30d, 7d), permit expired | Tutor + admin |

---

## 9. Compliance and safety (not legal advice — verify)

- Tutors must hold the UAE private-tutor permit (MoHRE/MoE). Permit number and expiry captured, verified by admin, enforced by the system.
- Minors have no login; parents own all communication. Contact details masked until first completed lesson. Admin can audit conversations. Report button everywhere a tutor and family interact.
- Police clearance / good-conduct certificate required for approval (a required document type in the admin; the requirement is data, the enforcement — no approval while a required document is missing or rejected — is code).
- Saved cards are tokenised at the gateway; the platform stores only brand, last four, and expiry.
- UAE PDPL: documents stored in private storage with expiring signed URLs; data-export and account-deletion request handled by admin in v1 (manual).
- VAT: platform commission is the taxable supply. Receipts show VAT line where applicable (setting: VAT %).
- Terms of service, privacy policy, tutor agreement (incl. trial discount and cancellation terms), safeguarding policy, about and contact pages exist as **admin-edited, versioned pages from CP2**, rendered on the public site; the words are entered by Rizwan/counsel before soft launch. Each tutor's agreement acceptance records the page version accepted; publishing a new agreement version does not silently re-bind existing tutors (re-acceptance is a v2 feature; v1 shows admin who accepted which version).
- Integration credentials (gateway and video-provider keys) are stored encrypted, displayed masked in the admin, never written to logs, and never committed to the repository.

---

## 10. Non-functional

- Laravel 13 / PHP 8.4 / PostgreSQL 18 / Redis. Inertia 3 + Vue 3 (D-01) + Tailwind 4. Filament 5 for admin. Pest for tests. (Framework major pinned at what the official installer produced on 2026-09-17 — ADR-003; the kit's "Laravel 12" is superseded.)
- All timestamps stored UTC; displayed in the user's timezone (default Asia/Dubai). Weekly slots store their own timezone and generate occurrences from local time, so a tutor abroad keeps a stable local time.
- **RTL-ready from CP0:** `dir` attribute on `<html>`, Tailwind logical properties only (`ms-`/`me-`/`ps-`/`pe-`/`start-`/`end-`), no `ml-`/`mr-`/`left-`/`right-`. English only at launch; Arabic is a later translation job, not a layout job.
- Every lesson state transition and every ledger entry is covered by a feature test. Auto-charge and report submission are idempotent.
- Video: `VideoRoomProvider` interface with two capability flags per provider — `supports_embed` (room inside our lesson page vs. an external join link) and `supports_attendance_webhooks` (join/leave from the provider vs. manual "I've joined"); providers are registry entries in the admin (§12). First and only v1 driver: Daily.co (embed + webhooks). Zoom (embed + webhooks), Google Meet and Microsoft Teams (link mode) are later drivers, not v1. Whiteboard: optional embedded tldraw (CP6 stretch).
- Search: PostgreSQL queries with proper indexes. No search engine in v1.
- Queues: Horizon. Realtime (message badges, join-button state): Reverb.
- Hosting: single VPS or Forge-managed server is enough for v1. Daily backups of DB and document storage; restore tested before launch.

---

## 11. Open decisions (fill before CP0 closes)

| # | Decision | Default if undecided | Status (owner rulings, 2026-09-16) |
|---|---|---|---|
| D-01 | Inertia + Vue vs React | Vue | **Confirmed: Vue** |
| D-02 | Payment gateway driver (must support saved-card charging) | Stripe (UAE) if available; else Tap | Open — the gateway registry (§12) lands in CP5 with Stripe as the first driver; keys are entered in the admin by the owner, never in code; backend dev verifies saved-card charging and that the D-04 entity is eligible |
| D-03 | Video provider | Daily.co | **Confirmed: Daily.co** |
| D-04 | Legal entity that holds the licence and collects funds | TBD with counsel | Open — settle by the CP5 plan (gateway account, VAT line, tutor agreement all depend on it) |
| D-05 | Brand name and domain | `project-elearning` placeholder | Open — the brand name becomes a site setting in CP1 (no code change when decided); the domain and sender address still need settling by CP6 so email warm-up precedes the CP8 launch mails |
| D-06 | Trial discount % | 50% | **Confirmed: 50%** |
| D-07 | Local dev environment (Horizon needs pcntl/posix, so not native Windows PHP) | WSL2 + Docker Desktop + Laravel Sail (Postgres 16, Redis, Horizon in one compose file, matches Linux production) | Decided (owner, 2026-09-17): Herd Pro native, PHP 8.4, PostgreSQL 18, Redis 7, Herd mail catcher — ADR-001/002 |
| D-08 | GitHub account and plan (branch protection on a private repo needs Pro or Team) | Private repo under Rizwan's account on GitHub Pro; Rizwan creates the empty repo, Claude Code pushes | **Decided differently: public repo `rizwanoor80/eLearning-Platform` on the Free plan** — branch protection works on public repos; the code is world-readable, so the no-secrets rule is absolute |

---

## 12. Configurability principle (v1.2, owner ruling 2026-09-17)

**Data is configured in the admin; behaviour stays in code.** Anything that is a key, a text, a list, a number or a toggle is entered and changed in Filament without a deploy. Anything that is a rule — how money moves, how a lesson changes state, who may see what — is code, tested, and changed only through a checkpoint.

| Configurable in the admin (data) | Fixed in code (behaviour) |
|---|---|
| **Integrations registry:** payment gateways and video providers as entries with encrypted keys, test/live mode, active flag and capability flags; notification providers (WhatsApp/SMS) join the same registry when they arrive (roadmap 1). The registry gives the slot; each provider's driver is still code. | The `PaymentGateway` and `VideoRoomProvider` interfaces, every driver, webhook verification, idempotency. |
| **Content:** legal and static pages (versioned), homepage content blocks, site settings and branding (name, logo, favicon, contact, social, footer, head scripts), mail sender identity and footer. | Page layouts, the email templates themselves (they read brand name, sender and footer from settings; editable templates are a v2 feature). |
| **Lists:** curricula, subjects, price bands, required onboarding document types. | The enums that drive outcomes: lesson states, dispute reasons and their default settlements, abuse-report reasons, roles, report form fields. |
| **Numbers:** every value in the `settings` table (commission, trial discount, windows, grace, retries, payout rules, VAT, currency code/symbol, default timezone, legal entity details). | The 60-minute lesson (the slot calculator and hourly pricing are built on it; `duration_minutes` exists so it can open up later), integer-fils money, UTC storage. |
| **Toggles:** match-request path, reviews, messaging — so pieces can be switched off at soft launch. | The state machine, the ledger, the fifteen invariants in CLAUDE.md. |

Rules that follow from the principle: credentials are encrypted at rest, masked in the admin, never logged, never committed. Policy values are still frozen onto each lesson at creation (§3) — configurability never changes an existing lesson. A registry entry that is inactive or incomplete is not an error at boot: the app runs, and the affected flow shows a clear admin notice instead.
