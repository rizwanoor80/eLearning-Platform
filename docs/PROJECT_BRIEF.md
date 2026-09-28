# PROJECT BRIEF — TrusTutor (1-on-1 tutoring platform)

_Strategic background. Read this before PRD.md. Rarely changes. Last refreshed 2026-09-28, at the close of cycle 08 (programme "CP7"). The repo and folder keep the working name (`eLearning-Platform`, `C:\project elearning`); the product is TrusTutor._

## What we are building
A hybrid 1-on-1 online tutoring platform for school-curriculum students in the UAE.
Parents (or adult students) book vetted tutors for 60-minute live online lessons.
After every lesson the tutor files a short progress report that is emailed to the parent
and shown in a parent portal.

A discounted trial lesson is the front door; a recurring weekly slot (same tutor, same time, each lesson auto-charged 48h ahead) is the relationship. That is the whole product for v1.

## Why hybrid
- Pure marketplace (Wyzant/Preply style): low ops, but quality is uneven and families
  leave the platform after lesson one. Parents buying GCSE/IB/CBSE help want accountability.
- Fully managed (Varsity/GoStudent style): consistent quality, but needs advisors and
  academic staff we don't have.
- Hybrid: we vet and cap the tutor pool (the UAE tutor-permit requirement forces vetting
  anyway), set price bands per curriculum/level, let parents browse OR request a match,
  and back it with a first-lesson guarantee.

## Launch niche
School curriculum: GCSE/IGCSE, A-Level, IB (MYP/DP), CBSE. Single currency (AED),
single default timezone (Asia/Dubai). Supply first: 30–50 hand-vetted tutors before any
student acquisition spend.

## Brand, domain and environments
- Brand **TrusTutor** (D-05, 2026-09-19, ADR-006), domain `trustutor.com`, DNS on Cloudflare. Two capital Ts; `site_name` = `TrusTutor`. Servers, sites, databases and networks carry the `trustutor-` prefix so nothing is confused with the owner's other projects.
- Local: Laravel Herd Pro on native Windows — no Docker, Sail or WSL (D-07, ADR-001; Docker Desktop crash-loops on this machine). PHP 8.4, PostgreSQL 18, Redis, Herd mail catcher. The owner starts and stops Herd and its services.
- Rehearsal: Laravel Forge managing a Hetzner Cloud CX23 in Falkenstein — `trustutor-rehearsal`, site `rehearsal.trustutor.com`, Ubuntu 26.04, PHP 8.4, PostgreSQL 18, Redis, Horizon as a Forge process (ADR-005). Deploys from the `rehearsal` branch by push-to-deploy; Claude Code deploys it itself under a standing ruling (R111) and reaches the server only through the ssh shapes allow-listed in the git-ignored `CLAUDE.local.md`. It holds no Forge, Hetzner, Cloudflare or gateway credential.
- Production: `trustutor-production`, built from the same recipe at CP8. Every production deploy is the owner's click.
- Repository: public `rizwanoor80/eLearning-Platform` on GitHub Free (D-08). The code is world-readable, so no secret, `.env`, `CLAUDE.local.md`, real name, email or phone ever enters it.

## Decisions (PRD §11 and the owner's later rulings)
| # | Decision | Status |
|---|---|---|
| D-01 | Front end | Inertia 3 + Vue 3 |
| D-02 | Payment gateway | Multiple gateways through the admin registry (PRD §12); Stripe is the first real driver, provisional on D-04 (R120, ADR-018) |
| D-03 | Video | Daily.co through the video-provider registry (ADR-017); a `fake` provider serves local, tests and rehearsal |
| D-04 | Legal entity that holds the licence and collects funds | **Open**, with counsel. Blocks CP5: the gateway account, the VAT line and the tutor agreement all depend on it |
| D-05 | Brand and domain | TrusTutor, `trustutor.com` (PRD §11 still shows this row as open) |
| D-06 | Trial discount | 50% |
| D-07 | Local development | Herd Pro, native Windows |
| D-08 | GitHub | Public repository, Free plan |
| D-09 | Transactional mail | Postmark (R120). Wiring — sending domain, SPF/DKIM in Cloudflare, key in the admin — is its own later step; rehearsal logs mail and sends nothing (ADR-007) |

## Configurability principle (owner ruling 2026-09-17, PRD §12)
Data is configured in the admin; behaviour stays in code. Keys, texts, lists, numbers and
toggles — payment gateways, video providers, legal pages, homepage copy, branding, document
types, settings — are entered in Filament without a deploy. Rules — the state machine, the
ledger, who sees what — are code, tested, changed only through a checkpoint. A registry
entry gives a provider its slot; each provider's driver is still built and tested as code.

## Key architecture decisions (full text in DECISIONS.md)
- Laravel 13, Inertia 3, Filament 5, pinned as installed (ADR-003).
- The escrow ledger came forward from CP5 into CP3: append-only `ledger_entries` written only through `LedgerService`, every lesson sums to zero, `ledger:verify` runs in CI and on rehearsal (ADR-008).
- Nobody is ever hard-deleted. Deleting an account anonymises the person; lessons, payments and ledger rows stay (ADR-009).
- Year groups are an admin-managed list per curriculum, not free text (ADR-004).
- Weekly-slot auto-charge: attempts at 48, 36 and 24 hours before the lesson; the third failure cancels that lesson; two consecutive failed lessons pause the slot; the parent resumes it after replacing the card (ADR-013 to ADR-015).
- The fake payment gateway exists only on local, testing and rehearsal; with no real gateway, production charges nothing (ADR-016). The fake video provider gets the same allow-list at CP8 (R131).
- Messages are plain text, never edited or deleted. Contact details are masked until the pair's first completed lesson, and only the masked text is ever stored (ADR-019). Unread badges poll every 60 seconds; live badges over Reverb wait for CP8 (ADR-020).
- Reviews: one per lesson, only for a lesson both parties joined and that completed; ratings recomputed, never incremented; an admin can unpublish with a note (R136).
- Safeguarding: a Report button on the tutor profile, the conversation and the lesson; the reported party is never told. Suspending a tutor cancels their reserved lessons free and refunds their confirmed ones, with no strike; suspending an account cancels its reserved lessons free and leaves confirmed ones for the admin to decide; a lesson already in progress runs to its normal end (R137, R138). The card refund for these waits for CP5.
- No-show marking stays manual. A lesson left in progress because only one party joined is an accepted risk, revisited only if it happens on real lessons (R129).

## Explicit non-goals for v1 (do not build, do not scaffold "for later")
- Courses, self-paced content, recorded lesson libraries
- Group classes, cohorts, webinars
- Lesson packs, subscriptions, credits (the weekly slot is a reservation, not a pack)
- Learning plans, homework, assessments, mastery tracking
- B2B organisations, tenancy, org portals
- Tutor-set pricing outside platform bands
- Multi-currency, multi-language UI
- Native mobile apps (responsive web only)
- AI features of any kind
- Marketplace-style open registration (every tutor is admin-approved)

If any of these come up mid-build, log them under "Deferred" in docs/STATUS.md and move on.

## Success metrics (first 90 days after soft launch)
- 40+ approved tutors with valid permits across the 4 curricula
- 100 paid lessons
- ≥70% of lessons have a progress report filed within 24h
- ≥40% of trial families set up a weekly slot within 14 days
- <10% of auto-charges fail
- <5% lessons disputed

## Roadmap after v1 proves out (in rough order)
1. WhatsApp notifications (email-only in v1)
2. Lesson packs (5/10) with a small discount
3. Homework + simple learning plan on top of the report timeline
4. Search on Meilisearch once the tutor pool passes ~300
5. Group classes (2–6) for exam-season revision
6. Courses / self-paced content
7. B2B (schools, tuition centres) — only if a paying customer asks

## Where the build stands (2026-09-28, end of cycle 08)
- CP0 to CP3 done: foundation, tutor onboarding and approval, learners, search and match request, booking, trial and cancellation. CP0's acceptance boxes were never ticked in CHECKPOINTS.md.
- CP4, recurring weekly slots, done on the fake gateway. Three of its boxes are still unticked although their notes record them built and tested.
- CP5, real payments, saved cards, refunds, receipts and payouts: not started, waiting on D-04.
- CP6, lesson room, attendance, progress and trial reports: done. The Daily account is not yet connected.
- CP7, messaging, reviews, Report button, safeguarding queue and notification centre: done in cycle 08.
- CP8, disputes, admin tools, production hardening and soft launch: not started. Its hardening list collects every item earlier cycles disclosed rather than fixed.
- Test suite 1869/1869 passing; rehearsal runs `d3c8a0a`.

## Critical path to soft launch
1. D-04 settled with counsel, then the gateway account opened in the entity's name, then CP5 with Stripe first.
2. Daily account and domain for rehearsal, keys entered in the admin, webhook delivery and retry proven before any real lesson.
3. Postmark wired early enough for the sending domain to warm up before launch mail.
4. Legal pages — terms, privacy, tutor agreement, safeguarding, about, contact — written by counsel and published in the pages editor, with no "DRAFT" left.
5. CP8 built and `trustutor-production` created; soft launch with 5 real tutors and 3 friendly families running one full trial → weekly slot → auto-charge → report → payout loop.

Most of CP8 (disputes on the fake gateway, admin tools, hardening) can be built before items 1 to 3 are done.

## Reference material
A separate 42-module "Global Tutoring & Learning Platform" spec pack (v0.1, Sept 2026) exists as long-term reference. From it, v1 adopted: trial lesson with tutor recommendation, recurring weekly slot, independent refund/tutor-pay decisions, tutor balance buckets, policy frozen on the lesson, report/flag button, RTL-ready layout. Everything else in that pack is deferred. It is not a build source — see CLAUDE.md.

## Team and workflow
- Rizwan: product owner and final approval gate for every irreversible action; the only one who deploys production.
- Backend dev: reviews CP3, CP4 and CP5 (booking, recurring slots, ledger and payments).
- UI dev: owns the Inertia front end and reviews every checkpoint's UI on rehearsal. Walkthroughs of the booking screen, the branding and CP7 are outstanding.
- Planning seat: the Cowork project "eLearning" connected to the repo folder. Writes `docs/PLAN.md`, and this file on "refresh the brief". One planning conversation per cycle.
- Claude Code: Sonnet 5 at High effort. Executes the current plan and reports through `docs/STATUS.md` and `docs/CYCLE-LOG.md`.
- Advisor: set in Claude Code with `/advisor`. **Currently Fable (owner, 2026-09-28).** The owner switches it between Fable and Opus from time to time, so this line can lag; Claude Code names the advisor from its own `/advisor` setting where it can read it, and otherwise from this line.
- `docs/HOW-WE-WORK.md` v1.2 is binding on both seats (ADR-011), with the owner's later rulings:
  - Claude Code never stops only to clear. A `/clear` is offered only at a halt that already needs the owner (ADR-012). Programmes run unattended across auto-compactions and stop only at real gates: a High finding, a broken money invariant, a production decision, or the plan's END.
  - Claude Code deploys rehearsal itself; production deploys stay the owner's click.
  - Merges: Claude Code merges its own PR under the plan's merge rule once a fresh-subagent review has no Medium or High open and CI is green on the head. If Claude Code's own permission check refuses a merge — it did once, on PR #33, the safeguarding and suspension-refund change — there is no retry and no other route: the owner merges by hand on GitHub and replies `update` (R147). Rejected: a `gh pr merge` allow-rule in `.claude/settings.local.json`, which does not clear that check (R145, tried and refused 2026-09-28); making every merge the owner's click (R146, withdrawn the same day).
  - Advisor entries: the tool does not report which model answered, so each ADVISOR entry carries R63's fixed phrase naming the configured advisor from this file, and an entry carrying it counts toward the plan's minimum (ADR-010 addendum). In cycle 08 Claude Code logged its entries as not counting; under R63 they should have counted.
- Nightly orchestrator runs only as an authorised programme (HOW-WE-WORK §12), never by default.
