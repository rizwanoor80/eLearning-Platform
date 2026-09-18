# STATUS — cycle 02 r3 — written 2026-09-18 20:43
Tests: 205 (unmerged) · Advisor: consulted 3 times this sub-cycle (1c design 15:05, 1c pre-PR 17:24, 1c halt 20:41) · Review: 1a: 9 PASS, merged; 1b: three rounds, merged; **1c: 14 PASS/PASS WITH NOTE, 1 FAIL Medium, 1 FAIL Low — halted, not merged**

## §1 Git state
Cycles 01, sub-cycles 1a and 1b merged (`646ce06`, `c08bad8`, `10e6198`). [PR #4](https://github.com/rizwanoor80/eLearning-Platform/pull/4) (`cp/1c-admin-approval`, 13 commits) is **open and unmerged** — CI green (`https://github.com/rizwanoor80/eLearning-Platform/actions/runs/35365533394/job/105666950716`), but the fresh-subagent review found a Medium, so R21's merge rule is not met. `main`'s branch protection: **not set** (owner action, carried, R23). `.claude/settings.local.json` (R14) still not created — optional, carried.

## §2 Step map (cycle 02 r3)
0. Merge PR #1 and close cycle 01 — [done] `646ce06`.
1. Sub-cycle 1a `cp/1a-onboarding-core` — [done] `c08bad8`.
2. Sub-cycle 1b `cp/1b-onboarding-complete` — [done] `10e6198`.
3. Sub-cycle 1c `cp/1c-admin-approval` — **[halted — awaiting owner (round 1 of 2)]** PR #4 open, CI green, review found 1 Medium + 1 Low in code. See §7 Owner action 1.
4. Sub-cycle 2a `cp/2a-learners-slots` — [not started] — blocked behind 1c (sub-cycles are dependent, R21).
5. Sub-cycle 2b `cp/2b-search-profile` — [not started]
6. Sub-cycle 2c `cp/2c-match-pages-content` — [not started] (closes CP2)
7. Programme end — [not started] — this cycle's planned halt (24-hour owner review).

## §3 What changed this run
- Sub-cycle 1b merged (`10e6198`), post-merge record written. Sub-cycle 1c designed, built (13 commits, 205 tests / 686 assertions, `npm run build` + `vue-tsc` clean), PR #4 opened, CI green.
- **Fresh-subagent review of PR #4** (20:40): 14 PASS / PASS WITH NOTE; **1 FAIL Medium** — a rejected document can never be replaced. `currentStep()` counts every uploaded document as done whatever its status (`TutorOnboardingController.php:411`), so after an admin rejects a required document and requests changes, the tutor lands on `complete`, the document upload returns 409 (`:136`), and `complete` resubmits with the document still rejected. Approval then stays blocked, so the admin can only re-accept the rejected file or reject the tutor. Confirmed by the reviewer's probe; no test covers it. **1 FAIL Low (in code)** — the six tutor events lack `SerializesModels`.
- **Halt ADVISOR (20:41)**: the Medium is real and 1c's own — 1c's `changes_requested` re-entry exposed it. Severity stands (it defeats CP1's "admin can approve" for the most likely flagged item). No fix attempted on the branch. Detail in CYCLE-LOG.md (20:40–20:43).

## §4 Decisions
- Carried (1c): `changes_requested` re-entry reuses the wizard (15:06 — **its claim "edit any step exactly as during draft" is false for the document step, see §6**); `users.status` + panel access (15:07); transition table in the Actions (17:20); `head_scripts` raw by design (17:21); auto-hide is `bookable()`, job idempotent via `audit_logs` (17:22); mail sender read at send time, branding on the public disk, new admins email-verified (17:23).
- **Not yet decided — Owner action 1**: whether to authorise fix-loop round 1 for the rejected-document gap (bundled with the Low), and in what shape.

## §5 Why stopping
**Stopping: R21 merge rule not met.** The review of PR #4 returned one Medium (rejected documents cannot be replaced) and one Low in code. Per R21, "any Medium+ finding or rule miss → PR stays open, STATUS §7 names it 'awaiting owner', programme halts"; HOW-WE-WORK §6 sends a Low in code to the owner as well, so it is bundled below rather than fixed in-cycle. This is an early stop inside sub-cycle 1c, not the planned end-of-programme halt.

## §6 Mismatches
- **`main` branch protection still not set** (owner action, carried). Per R23 this does not block the programme.
- **CP1 box 7's admin-payout clause remains deferred to CP5** (R25, disclosed since 1a) — "CP1 done" stays qualified.
- **The 1c design consult and DECISION 15:06 were wrong about `changes_requested` re-entry.** They said the tutor can edit "any step exactly as during draft" without tracing the document step. 1a's own test comment even said re-upload "arrives with admin review in 1c". This is the third time the same class of miss — re-entry without re-deriving dependent state — has surfaced (1b's R26/R27, now this). Disclosed as our miss, not only the reviewer's catch.
- **Also disclosed from the review, for the owner's awareness** (not blocking): the six CP1 emails use the settings sender but Fortify's verification/reset mails still use `config('mail.from')`, so box 8's "next email" holds for the six only; `SettingsSeeder` overwrites every key, so re-running it would clobber admin edits including `site_name`; the approval queue's "Submitted" column sorts on `created_at` (profile creation), not submission time; a disabled admin keeps the `access-admin-area` gate and a ≤15-minute signed document URL (bundled below); the last-active-admin check is not transactional.
- R25 differences carried from the PR: `audit_logs.actor_user_id` nullable, `settings.value` nullable via a new migration, `users.status`/`suspended_reason` added; diff-scope exceptions listed in CYCLE-LOG 17:27.

## §7 Next step and owner actions
**Owner action 1 (blocking — the programme cannot resume without this): authorise fix-loop round 1 for sub-cycle 1c, and pick a shape.**
- **(Recommended)** In `currentStep()`, count only `pending`/`accepted` documents as uploaded (exclude `rejected`). A `changes_requested` tutor with a rejected document then returns to the `document` step for that type, where the existing `storeDocument` transaction already soft-deletes the old row and creates a new `pending` one (1a's partial unique index was built for exactly this). `complete()` stays blocked until it is replaced and `ApproveTutor` stays blocked until the new document is accepted; the draft flow is unchanged. Tests: rejected required document + `changes_requested` → step `document` for that type; upload → new pending row, old soft-deleted, step advances; `complete()` → `pending_review`; approve blocked until accepted, then allowed; a `pending` document still counts as uploaded. Admin workflow: reject the specific document, then request changes — nothing auto-rejects. **Bundled in the same round:** add `SerializesModels` to the six tutor events; add `status === Active` to the `access-admin-area` gate and to `TutorDocumentPolicy`'s admin branch, so "disable removes access" is true beyond the panel.
- Defer document re-upload to a later cycle and document that an admin must accept the file or reject the tutor. Not recommended — it makes "request changes" useless for documents.

Reply `update` (optionally naming the option) when decided. **This would be round 1 of 2**: a further Medium on re-review is round 2 (the last), and a Medium after that halts the whole programme for the 24-hour review.

Automatic continuations used under R21's cap: **3/8**, unchanged while halted. Carried, optional: Owner action A (R7 branch protection, exact GitHub UI steps in cycle 01's CYCLE-LOG 18:35 BLOCKER); Owner action B (`.claude/settings.local.json`, ADR-002).

## §8 Programme board (R21)
| Sub-cycle | State | Branch | PR | Review verdict | Merge hash |
|---|---|---|---|---|---|
| 1a onboarding-core | **merged** | `cp/1a-onboarding-core` | [#2](https://github.com/rizwanoor80/eLearning-Platform/pull/2) | 9 PASS, 2 Low notes, no Medium/High | `c08bad8` |
| 1b onboarding-complete | **merged** | `cp/1b-onboarding-complete` | [#3](https://github.com/rizwanoor80/eLearning-Platform/pull/3) | 3 rounds; final 13 PASS, no Med/High | `10e6198` |
| 1c admin-approval | **awaiting owner (round 1 of 2)** | `cp/1c-admin-approval` | [#4](https://github.com/rizwanoor80/eLearning-Platform/pull/4) | 14 PASS/notes, 1 FAIL Medium, 1 FAIL Low | — |
| 2a learners-slots | not started (blocked behind 1c) | `cp/2a-learners-slots` | — | — | — |
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
