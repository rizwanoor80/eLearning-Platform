# STATUS — cycle 15 r1 — step 1 done, step 2 starting, written 2026-10-13 (programme "cloud-ready"; resume cap 0 of 8)
Tests: last measured **2266 passed / 11628 assertions** on `main` at `19d4411` (CI run 38268229109); nothing since is code, and nothing this cycle has been run yet · `ledger:verify` OK on rehearsal (2026-10-12) · Rehearsal at `19d4411` · Advisor: 0 consultations this cycle so far (minimum 2 for 15a, 1 for 15b, 2 for 15c, plus own consultation on the status endpoint's authentication) · Review verdict: none yet this cycle · Context size: not measured by the tool (one compaction since the session started).

## §1 Git state
`main` and `origin/main` at the PLAN commit `4238911` ("PLAN.md cycle 15 r1 …", `[skip ci]`) plus the docs commit carrying this file and the START entry. `rehearsal` at `19d4411`. Branches `cp/14a-approval-rule`, `cp/14b-onboarding-clarity`, `cp/14c-lesson-polish` are merged and not deleted. No open PR. Working tree clean after the docs commit (the planner's `docs/cloud/`, `.claude/settings.json`, `scripts/cloud-session-start.sh` and the `.gitignore` amendment are now committed; `.claude/settings.local.json` and `CLAUDE.local.md` stay ignored).

## §2 Step map
1. Docs-only commit of PLAN r1 and the planner-written cloud files — **done**, `4238911`; the hook is inert locally (`CLAUDE_CODE_REMOTE` unset → exit 0, CYCLE-LOG START).
2. `cp/15a-status-endpoint` (R193) — **next**: advisor consultation on the design and on token authentication before the first edit.
3. `cp/15b-cloud-repo` (R194) — not started.
4. `cp/15c-display-names` (R195) — not started.
5. Rehearsal deploy (R111), R91 + R169 checks, first `/ops/status` read if the owner has placed a token, END — not started.

## §3 What changed
Only docs so far: `docs/PLAN.md` (cycle 15 r1), `.gitignore` (tracks only `.claude/settings.json`), `.claude/settings.json` (a `SessionStart` hook running `bash scripts/cloud-session-start.sh`), `scripts/cloud-session-start.sh` (exits at once unless `CLAUDE_CODE_REMOTE=true`), `docs/cloud/ENVIRONMENT.md`. All five are planner-written (R194) and were committed unedited after being read in full and scanned for secrets; the only credential-shaped strings are the test-only `DB_PASSWORD=postgres` and `ADMIN_SEED_PASSWORD=cloud-test-only`, which the file documents as the `.env.example` values.

## §4 Decisions and by whom
- Planner, accepted by the owner ("go ahead with recommendation", 2026-10-13): PLAN cycle 15 r1, R193–R198.
- CC: committed the planner-written cloud files unedited under R194/step 1; no other decision yet.

## §5 Why stopping
Not stopping: step 1 is a boundary, not a halt (plan: halt no). This file is rewritten at the boundary as the loop requires; step 2 proceeds.

## §6 Mismatches
- **`docs/cloud/ENVIRONMENT.md` §2 prints an older copy of the hook script** (no `.env` copy, no `key:generate` lines); the committed `scripts/cloud-session-start.sh` is the newer one and is what runs. Not edited (planner-written). Planner: refresh §2 of the file when convenient. Also §2 calls `.claude/settings.json` "owner-only"; PLAN R194 has CC commit it, so the sentence is stale.
- **Carried from cycle 14 r2 (unchanged, none touched this cycle):**
  - R189(a) is met inside Daily's frame, not in our own markup; the pre-join screen, the two new Daily properties (`enable_prejoin_ui` on room and token) and the permission prompts are unproven until Owner action 1 below.
  - R189(b) wording versus `DisputeOpenedAdminNotification` (sends `learner_display_name` to admins); and whether rooms should be capped at two people.
  - The advisor tool does not name its model: consultations are counted on the session's configured `advisorModel` (`claude-fable-5-1`) basis with a disclosure, which rule 11 may not accept.
  - `MessageMasker` gaps (a combining digits U+A8E0–A8E9; b non-ASCII look-alike domain endings; c the country-code heuristic loses characters in names such as "Sa'id", "Mary-Jo" and reads "Dr. Dev Patel" as a domain; d "Ali. co" not read as a domain); the PR #47 Low notes; the 409 handler is global; tutor bio and headline are not masked. Stay carried until a masker cycle (PLAN: out of scope).
  - Sender name: rehearsal still sends as "TrusTutor (Support)" from the `from_name` setting, which CC may not write (Owner action 3).
  - Local dev database has five older migrations pending; the test database migrates itself.

## §7 Next step / Owner actions
No action is needed from the owner to continue this cycle: CC proceeds to step 2. The actions below are carried from cycle 14 r2 and stay open; they do not block steps 2–4. Step 5 lists the R196 pilot actions at END.

**Owner action 1 (Recommended): prove the pre-join screen and both new Daily properties on rehearsal.** (1) As the demo parent, book a lesson with a demo tutor that starts **more than 15 minutes from now** (its room is created 15 minutes before the start). (2) Open the lesson page about 10 minutes before the start: the title reads "<subject> with <tutor first name> · for <learner>". Click "Join the lesson here": expect Daily's own check inside the frame (camera and microphone preview, device pickers, Daily's Join) and the Join button gone. Join, confirm you are in the call, leave. (3) As the tutor, the title reads "<subject> with <learner>". (4) If joining answers "The video room is unavailable right now", or the lesson never gets a room, reply with exactly that. Reply `update` when done.

**Owner action 2: the Daily retry test (R184), after Owner action 1 is clean.** Keep the real Daily webhook secret open in Daily's dashboard **before you start** (once overwritten it cannot be read back). (1) Admin → Video providers → Daily → Webhook secret: type `bm90LXRoZS1zZWNyZXQ=` → Save. (2) Book another demo-tutor lesson for the next top of the hour inside the tutor's availability, join as the parent, leave. (3) Reply `update`; CC reads `video:webhooks --since=1h` and should see deliveries rejected (401) and no stored events. (4) Paste the real secret back → Save. (5) Reply `update` again after an hour. "No redelivery" is an answer, not a failure.

**Owner action 3: the sender name.**
 1. (Recommended) In Admin → Settings → Mail set "From name" to `TrusTutor`. Reason: rehearsal sends as "TrusTutor (Support)" today and CC may not write settings.
 2. Leave "TrusTutor (Support)" and tell the planner it is intended.
Reply `update` when done.

**Owner action 4: the SendPulse unsubscribe link (R174(c)).**
 1. (Recommended) In SendPulse turn off list-unsubscribe and unsubscribe-link insertion for transactional SMTP. Reason: the link is injected by SendPulse, not by us, and these mails are transactional.
 2. Leave it on and tell the planner.
Reply `update` when done.

**Owner action 5: the Subjects form.** Open the tutor onboarding Subjects step on rehearsal at 1280 px and 375 px wide and confirm the subject row wraps without overflow. Reply `update` when done.

**Owner action 6: UI-developer review** of the onboarding checklist and copy (tutor onboarding, tutor dashboard, admin review page, parent "Get started", `docs/copy/onboarding.md`); carried items 8 and 9 stay at the owner's pace. Reply `update` when done.

Owner-blocking, carried: D-04 with counsel (overseas tutor payouts and tax, recording consent); authorising `trustutor-production`; Postmark wiring (R120).

## §8 Programme board
Programme "cloud-ready" (cycle 15 r1).
| # | Item | State |
|---|------|-------|
| 1 | PLAN r1 commit + planner-written cloud files | done `4238911` |
| 2 | 15a status endpoint (R193) | next |
| 3 | 15b cloud repo (R194) | — |
| 4 | 15c admin-approved display names (R195) | — |
| 5 | Rehearsal deploy, R91, R169, first `/ops/status` read, END | — |
Resume cap 0 of 8.

## Deferred
- **`max_participants: 2` on Daily rooms** — a Daily property that cannot be run from here.
- **Inertia history encryption** — app-wide; page props (including the lesson title) stay in the browser's history state on a shared device.
- **English title literals in `LessonRoomView`** — "with" and "for" not yet translated; wait for the i18n pass.
- **`MessageMasker` gaps (§6)** — touches messaging and invariant 8; planner to schedule.
- **`YearGroupMigrationTest` line 27 is order-dependent** — add an `ORDER BY` or compare with `toEqual`; a test-only fix.
- **A screen in our own markup** (R189(a) as worded) — only if the planner accepts the cost recorded in cycle 14 r2 §6.
