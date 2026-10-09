# STATUS — cycle 14 r1 — written 2026-10-09 13:50 (14a merged; starting 14b)
Tests: 2205 passed / 11164 assertions on the 14a head `a63d9eb` (squash-merged as `3aaac2c`); `ledger:verify` OK; `npm run build` exit 0; phpstan 0, pint clean, vue-tsc and RTL green · Advisor: 2 consultations this cycle so far (14a design, 14a before commit); the first could not be quoted and does not count toward the minimum (CYCLE-LOG 14:30), the second names `claude-fable-5-1` and quotes one line · Review: PR #46, 11 numbered verdicts, 1 FAIL (Medium, the dead-end banner) fixed in round 1 of 2 · Context: not measured by the tool this write · Resume cap 0 of 8 · Running, not halted.

## §1 Git state
`main` and `origin/main` at `3aaac2c` plus this docs commit. Branch `cp/14a-approval-rule` merged (not deleted). No open PR. Rehearsal is still at `2f50206`. An untracked `docs/cloud/ENVIRONMENT.md` is in the working tree and is not mine; it is left alone.

## §2 Step map
1. Commit PLAN r1 and close R184 — done (`bc7dc5b`, `f4e1ebf`).
2. `cp/14a-approval-rule` (R185) — done: PR #46 merged `3aaac2c` under R190/R147, CI green on the head.
3. `cp/14b-onboarding-clarity` (R186, R187, R188) — next.
4. `cp/14c-lesson-polish` (R189) — pending.
5. Rehearsal deploy (R111), R91/R169 checks, END — pending.

## §3 What changed
- **14a (R185):** approval no longer needs an availability window; `TutorProfile::bookable()` (still the single scope) now also needs one. An approved tutor with none sees the banner "Add your availability to appear in search" and can add windows from the onboarding page. The admin has an audited **Edit availability** action (`tutor.availability_edited_by_admin`). "Request changes" is a checklist of eight sections plus an optional note (`tutor_profiles.review_sections`, jsonb), highlighted on the tutor's onboarding and named in the email. New: `SaveTutorAvailability`, `TutorReviewSection`, one forward migration. A weekly lesson due for a tutor with no windows is now cancelled uncharged by the existing R104 path (DECISION, CYCLE-LOG 14:32).
- **Docs on `main`:** DATA_MODEL v1.11, ADR-024 amendment, PRD §2.1/§2.2/§6, CHECKPOINTS CP1 note.

## §4 Decisions and by whom
- Planner (PLAN r1): R184 to R191, the programme shape, the merge rule.
- CC, logged as DECISION: permit and required-document checks kept in the approval bar; recurring cancellation on zero windows is intended; the merge, including that the fix commit was not re-reviewed.
- Advisor: keep the permit and document checks; do not claim `problems()` re-asserts the submission minimum; docs ride the post-merge record.

## §5 Why stopping
Not stopping. The plan runs on to 14b without an owner gate.

## §6 Mismatches
- **Plan silent on permit and required documents:** R185 lists the approval minimum without them. They are kept (R171 behaviour); if the planner meant to drop them it is a one-line change under a new ruling.
- **First 14a advisor entry** has no quotable line (the reply is stored redacted), so it does not count toward the minimum.
- **Review Low notes, not fixed:** the admin audit row is written after the save commits (same as `editSubjects`/`editRate`); existing booked lessons are not re-checked against new windows; the dashboard banner ignores an expired permit; `guarded()` does not catch the `InvalidArgumentException` from `RequestTutorChanges` (unreachable from the form).
- **Untracked `docs/cloud/ENVIRONMENT.md`** appeared in the checkout at 2026-10-09 13:27 from outside this session.

## §7 Next step / Owner actions
No owner action yet; 14b starts now. Owner actions accumulate for the END write: the Daily retry-test steps (R181), the sender-name setting if wrong, the SendPulse unsubscribe setting, the UI-developer review of the onboarding checklist, and carried items 8 and 9.

## §8 Programme board
Programme "clarity" (cycle 14 r1).
| # | Item | State |
|---|------|-------|
| 1 | PLAN r1 commit, R184 | done `bc7dc5b`, `f4e1ebf` |
| 2 | 14a R185 | merged `3aaac2c` (PR #46) |
| 3 | 14b R186-R188 | next |
| 4 | 14c R189 | pending |
| 5 | Rehearsal deploy, R91, R169, END | pending |
Resume cap 0 of 8.
