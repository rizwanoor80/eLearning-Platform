# STATUS — cycle 02 r4 — written 2026-09-19 11:08
Tests: 219 (unmerged) · Advisor: consulted 4 times this sub-cycle (design 15:05, pre-PR 17:24, halt 20:41, halt 11:06) · Review: 1a: 9 PASS, merged; 1b: three rounds, merged; **1c: 1st review 14 PASS/notes + 1 Medium + 1 Low (fixed via R28/R29); 2nd review: 3 fixes verified genuine, 2 NEW FAIL Medium, 4 Low, 1 disputed — halted, not merged**

## §1 Git state
Cycles 01, sub-cycles 1a and 1b merged (`646ce06`, `c08bad8`, `10e6198`). [PR #4](https://github.com/rizwanoor80/eLearning-Platform/pull/4) (`cp/1c-admin-approval`, head `b5bc351`) is **open and unmerged** — CI green (`https://github.com/rizwanoor80/eLearning-Platform/actions/runs/35371438212/job/105686095160`), suite 219/743, but the second fresh-subagent review found two new Mediums, so R21's merge rule is not met. `main`'s branch protection: **not set** (owner action, carried, R23). `.claude/settings.local.json` (R14) still not created — optional, carried.

## §2 Step map (cycle 02 r4)
0. Merge PR #1 and close cycle 01 — [done] `646ce06`.
1. Sub-cycle 1a `cp/1a-onboarding-core` — [done] `c08bad8`.
2. Sub-cycle 1b `cp/1b-onboarding-complete` — [done] `10e6198`.
3. Sub-cycle 1c `cp/1c-admin-approval` — **[halted — awaiting owner (round 2 of 2, last)]** PR #4 open, CI green, second review found 2 Medium. See §7 Owner action 1.
4. Sub-cycle 2a `cp/2a-learners-slots` — [not started] — blocked behind 1c (sub-cycles are dependent, R21).
5. Sub-cycle 2b `cp/2b-search-profile` — [not started]
6. Sub-cycle 2c `cp/2c-match-pages-content` — [not started] (closes CP2)
7. Programme end — [not started] — this cycle's planned halt (24-hour owner review).

## §3 What changed this run
- **R28/R29 implemented and pushed** (`b5bc351`; 205→219 tests, 686→743 assertions): rejected documents can be replaced, `SerializesModels` on the six events, disabled admins lose the gate and document policy, `SettingsSeeder` never overwrites. CI green.
- **Second fresh-subagent review of PR #4** (11:05): it ran the suite itself (219/743 pass), **verified all three claimed fixes genuine, and confirmed boxes 4/6/8/9 earned** — then found two NEW Mediums in the same recurring class. **#1**: document accept/reject is offered and allowed in any profile status, so an admin rejecting a required identity/police document on an already-`approved` tutor leaves them `bookable()`; the tutor cannot re-upload (`currentStep()` → `submitted`) and the only recourse, Suspend, is terminal. Adding a required document type to an approved tutor likewise leaves them bookable. **#2**: a `changes_requested` tutor whose document was not rejected lands on `complete`, and `Onboarding.vue` renders only "Submit for review" — there is no UI path to the personal/permit/bank/subjects/profile/availability forms, though the `changes_requested` email says "sign in and update your profile". Plus four Lows and one disputed finding.
- **Halt ADVISOR (11:06)**: both Mediums are real and 1c's own; #2's scope call is the owner's, not mine to downgrade. #9 (agreement version not re-derived) is **disputed** — CHECKPOINTS line 76 and PRD §9 both specify that an accepted version is kept. Detail in CYCLE-LOG.md (11:05–11:08).

## §4 Decisions
- Carried (1c): `changes_requested` re-entry reuses the wizard (15:06 — **wrong twice over, see §6**); `users.status` + panel access (15:07); transition table in the Actions (17:20); `head_scripts` raw by design (17:21); auto-hide is `bookable()`, job idempotent via `audit_logs` (17:22); mail sender read at send time, branding on the public disk, new admins email-verified (17:23); R28/R29 as implemented (10:10).
- **Not yet decided — Owner action 1**: whether to authorise fix-loop round 2 (the last) for the two Mediums, and in what shape.

## §5 Why stopping
**Stopping: R21 merge rule not met, second time on 1c.** The re-review of PR #4 returned two Medium findings. Per R21, "any Medium+ finding or rule miss → PR stays open, STATUS §7 names it 'awaiting owner', programme halts". Round 1 (R28) was authorised by the owner; a round 2 needs the owner's word too, and it is the last — a Medium on its re-review halts the whole programme for the 24-hour review.

## §6 Mismatches
- **`main` branch protection still not set** (owner action, carried). Per R23 this does not block the programme.
- **CP1 box 7's admin-payout clause remains deferred to CP5** (R25, disclosed since 1a) — "CP1 done" stays qualified.
- **DECISION 15:06 is now wrong twice over.** It said a `changes_requested` tutor can edit "any step exactly as during draft": false for the document step (fixed by R28) and false in the UI, which never offered the other steps. I traced neither the UI nor the admin-side status of document review. Fourth instance of the class R30 was written for; owned as our miss, not only the reviewer's catch. The STATUS-before-push slip (HOW-WE-WORK §5) also recurred a third time.
- **Real spec gap found**: PRD §8 (line 168) says permit expiring/expired notices go to "Tutor + admin"; CHECKPOINTS line 43 omitted the admin and all six listeners mail only the tutor. The expiring email also tells a tutor to "renew it and update your permit details" though no renewal path exists (PRD §2.1 line 31 "new permit uploaded and re-approved" is not built).
- **Finding #9 disputed**: agreement version is deliberately not re-derived (CHECKPOINTS line 76; PRD §9).
- Not built, disclosed (R25): post-approval re-vetting (a rejected document or a newly-required type does not un-approve a tutor), and a reinstate-from-suspended action — neither is in CHECKPOINTS (box 6 says "blocks approval", not "revokes").
- Carried Lows (not blocking; PLAN r4's programme-end list covers most): `review_note` not cleared; Fortify mails use `config('mail.from')`; queue "Submitted" sorts on `created_at`; document-type `code`/`sort` lack unique/range validation; admin Disable has no confirmation; replaced files not deleted from disk; job lacks `onOneServer`; Vue locked-state text is the same for approved/rejected/suspended and `SuspendTutor`'s note is not shown to the tutor (bundled below).

## §7 Next step and owner actions
**Owner action 1 (blocking — the programme cannot resume without this): authorise fix-loop round 2 (the last) for sub-cycle 1c, and pick a shape.**
- **(Recommended)** Deliberately minimal:
  - **(a)** `ReviewTutorDocument` refuses unless the profile is `draft`, `pending_review` or `changes_requested` (`TutorStatusTransitionException`, caught in the relation manager like the page actions; row buttons hidden to match) — document review is part of the approval decision and closes with it. Tests: accept/reject on an `approved` profile throws and changes nothing; the existing document-review tests (which use draft profiles) still pass. Disclosed, not built: post-approval re-vetting and reinstate-from-suspended.
  - **(b)** `Onboarding.vue`: a `changes_requested` tutor at `complete` gets a step picker (personal / permit / bank / subjects / rate / profile / availability) that shows the already-present forms; every submit returns to `tutor.onboarding`, which re-derives and lands back on `complete`. No backend change. Also use the unused `status` prop for distinct approved / rejected / suspended text, and pass `reviewNote` for `suspended` (the `SuspendTutor` docblock promises it). Verified under R20 by `npm run build` + `vue-tsc` and an Inertia test for the props. The note UI says: a pending/accepted document is replaced by the admin rejecting it first, then requesting changes.
  - **(c)** Permit expiring/expired notices also go to the admin side, as PRD §8 line 168 says: to the `support_address` setting, falling back to all active admins when unset.
  - Not bundled (already ruled on or new scope): `review_note` clearing, disk cleanup of replaced files, a reinstate action, `onOneServer`.
- Rule that document review is not restricted by status (leave as is) and accept an approved tutor can keep a rejected document while staying bookable. Not recommended: a rejected identity or police-clearance document leaving a tutor bookable is a real consequence.
- Defer (b) and treat "request changes" as a document-rejection-only path for v1. Not recommended: the email tells the tutor to update their profile.

Reply `update` (optionally naming the option) when decided. **This is round 2 of 2, the last**: a Medium on its re-review halts the whole programme for the 24-hour owner review (R21).

Automatic continuations used under R21's cap: **4/8**, unchanged while halted. Carried, optional: Owner action A (R7 branch protection, exact GitHub UI steps in cycle 01's CYCLE-LOG 18:35 BLOCKER); Owner action B (`.claude/settings.local.json`, ADR-002).

## §8 Programme board (R21)
| Sub-cycle | State | Branch | PR | Review verdict | Merge hash |
|---|---|---|---|---|---|
| 1a onboarding-core | **merged** | `cp/1a-onboarding-core` | [#2](https://github.com/rizwanoor80/eLearning-Platform/pull/2) | 9 PASS, 2 Low notes, no Medium/High | `c08bad8` |
| 1b onboarding-complete | **merged** | `cp/1b-onboarding-complete` | [#3](https://github.com/rizwanoor80/eLearning-Platform/pull/3) | 3 rounds; final 13 PASS, no Med/High | `10e6198` |
| 1c admin-approval | **awaiting owner (round 2 of 2, last)** | `cp/1c-admin-approval` | [#4](https://github.com/rizwanoor80/eLearning-Platform/pull/4) | 1st: 14 PASS/notes, 1 Med + 1 Low (fixed R28). 2nd: 3 fixes verified, 2 FAIL Medium, 4 Low, 1 disputed | — |
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
