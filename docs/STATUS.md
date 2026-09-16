# STATUS — cycle 00 r0 — written 2026-09-16 (initial)
Tests: 0 (was 0) · Advisor: consulted 0 times · Review: n/a

## §1 Git state
No repository yet. First cycle will create it.

## §2 Step map
No PLAN.md yet. Backlog is docs/CHECKPOINTS.md; the planner cuts plans from it starting at CP0.

## §3 What changed this run
Nothing — starter kit only.

## §4 Decisions
- Owner (2026-09-15): hybrid model; niche GCSE/IB/CBSE; v1 = 1-on-1 only.
- Owner (2026-09-16): trial lesson and recurring weekly slot with auto-charge are in v1.
- Owner (2026-09-16): working method per docs/HOW-WE-WORK.md; CYCLE-LOG.md replaces ADVISOR_LOG.md.

## §5 Why stopping
Awaiting first "go ahead".

## §6 Mismatches
None.

## §7 Next step and owner actions
Owner action 1: confirm D-01 (Vue (Recommended) — the UI dev's stated stack) — reply `update` when done.
Owner action 2: confirm D-02 gateway; backend dev to verify saved-card charging support (Stripe UAE (Recommended) — one driver covers checkout, tokenisation and refunds) — reply `update` when done.
Owner action 3: confirm D-03 (Daily.co (Recommended) — prebuilt UI, fastest to CP6) — reply `update` when done.
Owner action 4: confirm D-07 dev environment (WSL2 + Docker Desktop + Sail (Recommended) — Horizon cannot run on native Windows PHP and Sail matches production; alternative Herd + `queue:work` in dev, Horizon only on servers) — reply `update` when done.
Owner action 5: create the empty private GitHub repo and confirm the plan (GitHub Pro under your own account (Recommended) — branch protection on a private repo is the technical backstop behind "nobody pushes to main"; alternative Free plan and drop that acceptance line) — reply `update` with the repo URL.
Open with no default: D-04 legal entity, D-05 brand name. D-06 trial discount defaults to 50% unless changed.

## §8 Programme board
none

## Deferred (out of v1 scope — do not build)
- Lesson packs / subscriptions / credits
- Group classes
- Courses / self-paced content
- Learning plans, homework, assessments
- B2B organisations / tenancy
- Multi-currency
- WhatsApp notifications
- Meilisearch
