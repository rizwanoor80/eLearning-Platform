# STATUS — cycle 02 r5 — written 2026-09-19 13:15
Tests: 236 (merged main, up from 219) · Advisor: 1c consulted 4 times (design 15:05, pre-PR 17:24, halts 20:41 and 11:06); 2a design consult next · Review: 1c merged after the third fresh review — no Medium/High (3 Low)

## §1 Git state
`main` = `407a687` (PR #4 merged with a merge commit) plus the post-merge record. Sub-cycles 1a, 1b, 1c merged (`c08bad8`, `10e6198`, `407a687`). Branch protection on `main`: **not set** (owner action, carried, R23). `.claude/settings.local.json` (R14) not created — optional, carried.

## §2 Step map (cycle 02 r5)
0. Merge PR #1 — [done] `646ce06`.
1. Sub-cycle 1a `cp/1a-onboarding-core` — [done] `c08bad8`.
2. Sub-cycle 1b `cp/1b-onboarding-complete` — [done] `10e6198`.
3. Sub-cycle 1c `cp/1c-admin-approval` — [done] `407a687`. **CP1 complete except box 7's admin-payout clause (CP5, R25).**
4. Sub-cycle 2a `cp/2a-learners-slots` — [in progress] — design ADVISOR (with R30's downstream-invalidation list) before the first edit.
5. Sub-cycle 2b `cp/2b-search-profile` — [not started]
6. Sub-cycle 2c `cp/2c-match-pages-content` — [not started] (closes CP2)
7. Programme end — [not started] — planned halt (24-hour owner review).

## §3 What changed this run
- R31 pushed (`4b9cfbd`); CI green (run https://github.com/rizwanoor80/eLearning-Platform/actions/runs/35403316524).
- Fresh-subagent re-review (round 2 of 2): 10 PASS/PASS WITH NOTE, 3 Low, no Medium/High (CYCLE-LOG REVIEW 12:52). PR #4 merged under R21.
- Post-merge on `main`: `composer.bat test` 236 tests / 832 assertions, Pint + PHPStan (0 errors) + RTL passed; `npm run build` ✓; curl `/` `/login` `/admin/login` → 200, `/dashboard` `/tutor/onboarding` `/admin` → 302.

## §4 Decisions
- 1c merged under R21 (CYCLE-LOG DECISION 12:53). Findings 11–13 go to the CP1 follow-up list, not a third fix loop.
- R30 is binding for 2a/2b/2c design consults.

## §5 Why stopping
Not stopping — sub-cycle 1c is closed and the programme continues into 2a per R21. Continuations used: 5/8.

## §6 Mismatches
- **`main` branch protection not set** (owner action, carried; R23 — does not block).
- **CP1 box 7 admin-payout clause deferred to CP5** (R25) — "CP1 done" stays qualified.
- **CP1 follow-up list (not built):** post-approval re-vetting; reinstate-from-suspended; `review_note` clearing; replaced-file disk cleanup; `onOneServer` on the permit job; document-type `code`/`sort` validation; admin Disable confirmation; **from the last review:** `ApproveTutor` does not re-check permit validity or the rate against the current band at approval (a permit that expires during review gives approved-but-not-bookable and a mail saying bookable); price bands are editable in place, against the effective_from versioning idea; a permit edit during `changes_requested` does not reset the accepted permit-scan document; approving with an optional document still pending leaves it unreviewable; the changes_requested step picker has no automated test (R20, verified by build/type-check/props tests).
- **Finding #9** stays disputed and the last reviewer agreed (PRD §9 line 180; CHECKPOINTS).
- Programme-end review list carried from PLAN r5 (Fortify mails' sender, queue "Submitted" sort, non-transactional last-admin check, double queue hop, logo/favicon untested/unrendered, N+1 Docs column, duplicate price-band 500).

## §7 Next step and owner actions
No owner action needed to proceed. Next: 2a design ADVISOR (R30 list with a test per dependency), then build on `cp/2a-learners-slots`. Continuations used under R21's cap: 5/8. Carried, optional: Owner action A (R7 branch protection, steps in cycle 01's CYCLE-LOG 18:35 BLOCKER); Owner action B (`.claude/settings.local.json`).

## §8 Programme board (R21)
| Sub-cycle | State | Branch | PR | Review verdict | Merge hash |
|---|---|---|---|---|---|
| 1a onboarding-core | **merged** | `cp/1a-onboarding-core` | [#2](https://github.com/rizwanoor80/eLearning-Platform/pull/2) | 9 PASS, 2 Low notes, no Medium/High | `c08bad8` |
| 1b onboarding-complete | **merged** | `cp/1b-onboarding-complete` | [#3](https://github.com/rizwanoor80/eLearning-Platform/pull/3) | 3 rounds; final 13 PASS, no Med/High | `10e6198` |
| 1c admin-approval | **merged** | `cp/1c-admin-approval` | [#4](https://github.com/rizwanoor80/eLearning-Platform/pull/4) | 3 rounds; final 10 PASS/PASS WITH NOTE + 3 Low, no Med/High | `407a687` |
| 2a learners-slots | in progress (design) | `cp/2a-learners-slots` | — | — | — |
| 2b search-profile | not started | `cp/2b-search-profile` | — | — | — |
| 2c match-pages-content | not started | `cp/2c-match-pages-content` | — | — | — |

## Deferred (out of v1 scope — do not build)
- Lesson packs / subscriptions / credits
- Group classes
- Courses / self-paced content
- Learning plans, homework, assessments
- B2B organisations / tenancy
- Multi-currency
- WhatsApp notifications
- Meilisearch
