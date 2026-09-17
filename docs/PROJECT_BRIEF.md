# PROJECT BRIEF — 1-on-1 Tutoring Platform (working name: `project-elearning`)

_Strategic background. Read this before PRD.md. Rarely changes._

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

## Configurability principle (owner ruling 2026-09-17, PRD §12)
Data is configured in the admin; behaviour stays in code. Keys, texts, lists, numbers and
toggles — payment gateways, video providers, legal pages, homepage copy, branding, document
types, settings — are entered in Filament without a deploy. Rules — the state machine, the
ledger, who sees what — are code, tested, changed only through a checkpoint. A registry
entry gives a provider its slot; each provider's driver is still built and tested as code.

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

## Reference material
A separate 42-module "Global Tutoring & Learning Platform" spec pack (v0.1, Sept 2026) exists as long-term reference. From it, v1 adopted: trial lesson with tutor recommendation, recurring weekly slot, independent refund/tutor-pay decisions, tutor balance buckets, policy frozen on the lesson, report/flag button, RTL-ready layout. Everything else in that pack is deferred. It is not a build source — see CLAUDE.md.

## Team and workflow
- Rizwan: product owner, final approval gate for every irreversible action
- Backend dev: reviews CP3, CP4, CP5 (booking, recurring slots, ledger/payments)
- UI dev: owns the Inertia front-end, reviews every CP's UI
- Planning seat (Cowork project on this folder) writes docs/PLAN.md; Claude Code (Sonnet 5 High, Fable 5.1 as advisor) executes it and reports through docs/STATUS.md and docs/CYCLE-LOG.md
- The full method, loop, files and rules are in docs/HOW-WE-WORK.md — binding on both seats from day one
- Nightly orchestrator runs only as an authorised programme (HOW-WE-WORK §12), never by default
