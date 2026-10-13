# STATUS — cycle 15 r1 — step 2 (15a) merged, step 3 (15b) next, written 2026-10-13 16:03 (programme "cloud-ready"; resume cap 0 of 8)
Tests: **2336 passed / 12022 assertions** on `main` at the merge commit `fa74c64` (baseline `main` at `19d4411`: 2266 / 11628; +70 tests, +394 assertions); `ledger:verify` OK; the branch head `146258c` had `npm run build` (15:24), Pint, PHPStan (0 errors) and the RTL grep green, and CI green on that head · Rehearsal at `19d4411` (unchanged; deploys at step 5) · Advisor: 2 consultations this cycle so far, both on 15a (design and token authentication 13:57; pre-PR 14:28); the tool does not name its model (§6) · Review verdict: PR #49 at `74f9f2a` — 64 verdicts (27 PASS, 27 PASS WITH NOTE, 10 FAIL, all Low); scoped re-review of `b10b90e` — 30 verdicts (17 PASS, 12 PASS WITH NOTE, 1 FAIL Low); no High or Medium, no R109 halt; every FAIL fixed; the last fix commit `146258c` (tests and a docblock) has no independent review of its own, because the cap of 2 was spent · Context size: not measured by the tool (three compactions since the session started).

## §1 Git state
`main` and `origin/main` at `fa74c64` (PR #49 squash-merged, CI green on its head `146258c`) plus the docs-only post-merge record on top of it (the commit that carries this file; `git log origin/main..HEAD` is quoted in CYCLE-LOG NOTE after the push). Branch `cp/15a-status-endpoint` stays on `origin` at `146258c` (CLAUDE.md: only a merged `hold/<step>` may be deleted). `rehearsal` at `19d4411`. No open PR.

## §2 Step map
1. Docs-only commit of PLAN r1 and the planner-written cloud files — **done**, `4238911`.
2. `cp/15a-status-endpoint` (R193) — **done**: merged `fa74c64` (PR #49); post-merge record on `main`; smoke test on `main` at the merge commit green (2336 / 12022, `ledger:verify` OK). The R193 line "R91 gains the curl form with `TRUSTUTOR_STATUS_TOKEN`" is carried out at step 5, not in this PR: no CC-owned runbook carries R91 (CYCLE-LOG ADVISOR 14:28).
3. `cp/15b-cloud-repo` (R194) — **next**: CI matrix on PostgreSQL 16 and 18, CLAUDE.md "Cloud sessions", HOW-WE-WORK edit with R177/R178 folded in.
4. `cp/15c-display-names` (R195) — not started.
5. Rehearsal deploy (R111), R91 + R169 checks (including the first `/ops/status` read if the owner has placed a token), END — not started.

## §3 What changed
- Merged to `main` (`fa74c64`): `GET /ops/status` (`routes/ops.php`, outside the `web` group) behind `AuthenticateOpsStatus` and `throttle:ops-status`; `php artisan ops:status` prints the same document from one assembler (`OpsStatusReport`); scheduled `scheduler:ping` (every minute) and `ops:record-ledger-check` (every 5 minutes, so the request never sums the ledger); table `ops_status_tokens` (SHA-256 hash only, one active row by partial unique index); Admin → Settings → Operations to generate, rotate and revoke the token and edit the optional IP allow-list; an index on `video_webhook_events.received_at`. Two fix loops: ten Low FAILs from the first review and one from the re-review, all fixed.
- Post-merge record (docs only): ADR-026, DATA_MODEL v1.12, CHECKPOINTS CP8 "Status endpoint" ticked, CYCLE-LOG, this file.
- Untouched: `LessonStateMachine.php`, `LedgerService.php`, `app/Services/Payments/*`.

## §4 Decisions and by whom
- Planner, accepted by the owner ("go ahead with recommendation", 2026-10-13): PLAN cycle 15 r1, R193–R198.
- CC: the design in ADR-026 (CYCLE-LOG ADVISOR 13:57); `mail.from_address` stays in the status document (DECISION 14:28); the audit scope (DEVIATION 15:12, Owner action 7); the self-merge under R197 and R147 (DECISION 15:49); DATA_MODEL numbered v1.12 for 15a (§6).

## §5 Why stopping
Not stopping: this file is rewritten at the step boundary as the loop requires. Step 3 (`cp/15b-cloud-repo`) starts next.

## §6 Mismatches
- **Audit scope of `/ops/status` versus R193 (DEVIATION 2026-10-13 15:12).** R193 says the endpoint "logs each call to the audit log with the caller IP". As built, a successful read is audited (`ops_status_token.read`, caller address) and a rejected call goes to `laravel.log` (reason code and address) only, because `audit_logs` needs a subject and most rejected calls have none. Ruled on by the owner in Owner action 7 below; nothing waits for it.
- **DATA_MODEL numbering.** The plan names v1.13 for 15a's table, but the file stood at v1.11 (v1.12 was never used), so 15a is v1.12 and 15c will be v1.13.
- **HOW-WE-WORK version for step 3.** R194 and R198 say "HOW-WE-WORK v1.3", but the file already is "Version 1.3 · September 2026" (R119, 2026-09-26). The step-3 edit is therefore written as v1.4 unless the planner says otherwise; the planner's Project copy needs the same number.
- **R178 versus the CLAUDE.md "Local environment" line.** CLAUDE.md says Herd and its services are started and stopped only by the owner; R178 (in force per PLAN "Standing") lets CC start Herd's PostgreSQL and Redis when they refuse connections and never stop one. As in cycle 12 r2, CLAUDE.md is not edited beyond the "Cloud sessions" section R198 authorises; HOW-WE-WORK gets R177/R178 at step 3. Planner: align the CLAUDE.md line when convenient.
- **Left unfixed from the reviews, all Low:** the 27 PASS WITH NOTE items stay in CYCLE-LOG REVIEW 15:12 (for example the per-IP throttle is weak for IPv6; a revoke racing a rotation can report "nothing revoked"; the plaintext token sits in the Livewire payload until dismissed); from the re-review: the identical-404 test depends on the order of the response keys, the secrets test does not probe the connection-object path, `CREATE INDEX` on `video_webhook_events` takes a short write lock, and the Operations page has no page-level `canAccess()` (the panel gate and the per-action role check hold).
- **`docs/cloud/ENVIRONMENT.md` §2 prints an older copy of the hook script** (no `.env` copy, no `key:generate` lines); the committed `scripts/cloud-session-start.sh` is the newer one and is what runs. Not edited (planner-written). Planner: refresh §2 when convenient. Also §2 calls `.claude/settings.json` "owner-only"; PLAN R194 has CC commit it, so the sentence is stale.
- **Carried from cycle 14 r2 (unchanged, none touched this cycle):**
  - R189(a) is met inside Daily's frame, not in our own markup; the pre-join screen, the two new Daily properties (`enable_prejoin_ui` on room and token) and the permission prompts are unproven until Owner action 1 below.
  - R189(b) wording versus `DisputeOpenedAdminNotification` (sends `learner_display_name` to admins); and whether rooms should be capped at two people.
  - The advisor tool does not name its model: consultations are counted on the session's configured `advisorModel` (`claude-fable-5-1`) basis with a disclosure, which rule 11 may not accept.
  - `MessageMasker` gaps (a combining digits U+A8E0–A8E9; b non-ASCII look-alike domain endings; c the country-code heuristic loses characters in names such as "Sa'id", "Mary-Jo" and reads "Dr. Dev Patel" as a domain; d "Ali. co" not read as a domain); the PR #47 Low notes; the 409 handler is global; tutor bio and headline are not masked. Stay carried until a masker cycle (PLAN: out of scope).
  - Sender name: rehearsal still sends as "TrusTutor (Support)" from the `from_name` setting, which CC may not write (Owner action 3).
  - Local dev database has five older migrations pending; the test database migrates itself.

## §7 Next step / Owner actions
No action is needed from the owner to continue this cycle: CC proceeds with step 3, then steps 4–5. Step 5 lists the R196 pilot actions at END. The actions below are open and none blocks steps 3–4.

**Owner action 1 (Recommended): prove the pre-join screen and both new Daily properties on rehearsal.** (1) As the demo parent, book a lesson with a demo tutor that starts **more than 15 minutes from now** (its room is created 15 minutes before the start). (2) Open the lesson page about 10 minutes before the start: the title reads "<subject> with <tutor first name> · for <learner>". Click "Join the lesson here": expect Daily's own check inside the frame (camera and microphone preview, device pickers, Daily's Join) and the Join button gone. Join, confirm you are in the call, leave. (3) As the tutor, the title reads "<subject> with <learner>". (4) If joining answers "The video room is unavailable right now", or the lesson never gets a room, reply with exactly that. Reply `update` when done.

**Owner action 2: the Daily retry test (R184), after Owner action 1 is clean.** Keep the real Daily webhook secret open in Daily's dashboard **before you start** (once overwritten it cannot be read back). (1) Admin → Video providers → Daily → Webhook secret: type `bm90LXRoZS1zZWNyZXQ=` → Save. (2) Book another demo-tutor lesson for the next top of the hour inside the tutor's availability, join as the parent, leave. (3) Reply `update`; CC reads `video:webhooks --since=1h` and should see deliveries rejected (401) and no stored events. (4) Paste the real secret back → Save. (5) Reply `update` again after an hour. "No redelivery" is an answer, not a failure.

**Owner action 3: the sender name.**
 1. (Recommended) In Admin → Settings → Mail, set "From name" to `trusTutor Team`. Reason: rehearsal sends as "TrusTutor (Support)" today and CC may not write settings.
 2. Leave "TrusTutor (Support)" and tell the planner it is the intended name.
Reply `update` when done.

**Owner action 4: SendPulse sender display name and unsubscribe link (R174(c); PLAN cycle 15 r1 "Carried forward").**
 1. (Recommended) In SendPulse set the sender display name to `TrusTutor` and turn off list-unsubscribe and unsubscribe-link insertion for transactional SMTP. Reason: both are SendPulse account settings, not ours, and these mails are transactional.
 2. Leave them as they are and tell the planner.
Reply `update` when done.

**Owner action 5: the Subjects form.** Open the tutor onboarding Subjects step on rehearsal at 1280 px and 375 px wide and confirm the subject row wraps without overflow. Reply `update` when done.

**Owner action 6: UI-developer review** of the onboarding checklist and copy (tutor onboarding, tutor dashboard, admin review page, parent "Get started", `docs/copy/onboarding.md`); carried items 8 and 9 stay at the owner's pace. Reply `update` when done.

**Owner action 7: the audit scope of `/ops/status`.**
 1. (Recommended) Keep it as built: successful reads are audited with the caller address; rejected calls are in `laravel.log` with the reason and address. Reason: a rejection has no subject row to attach to, and writing one audit row per probe lets anyone who finds the URL fill the audit table.
 2. Ask for one audit row per rejected call as well (CC adds one `audit_logs` write in the middleware's `reject()` with a fixed subject, in a small follow-up PR).
Reply `update` when done.

Owner-blocking, carried: D-04 with counsel (overseas tutor payouts and tax, recording consent); authorising `trustutor-production`; Postmark wiring (R120).

## §8 Programme board
Programme "cloud-ready" (cycle 15 r1).
| # | Item | State |
|---|------|-------|
| 1 | PLAN r1 commit + planner-written cloud files | done `4238911` |
| 2 | 15a status endpoint (R193) | done — merged `fa74c64` (PR #49), post-merge record on `main` |
| 3 | 15b cloud repo (R194) | next |
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
