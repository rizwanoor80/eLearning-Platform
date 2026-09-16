# DECISIONS — project-elearning (ADRs)

_Accepted architecture and policy decisions that outlive any single plan. Written by Claude Code only when a plan authorises it; proposals go to STATUS.md §4 first. Cycle-level rulings live in PLAN.md, not here._

| # | Date | Decision | Reason | Status |
|---|---|---|---|---|
| ADR-001 | 2026-09-16 | Local dev environment = Laravel Herd, native Windows, no Docker | Docker Desktop crash-loops on a stale inference socket (docker/desktop-feedback #460) and recovery needs manual steps CC cannot perform; native is also faster | Accepted (owner ruling) |
