# STATUS — cycle 15 r1 — step 3 (15b) merged, step 4 (15c) next, written 2026-10-13 16:37 (programme "cloud-ready"; resume cap 0 of 8)
Tests: **2336 passed / 12022 assertions (453 s)** on `main` at the merge commit `0d479dd` (local smoke; baseline `main` at `fa74c64`: 2336 / 12022, so 15b adds no test, as designed: three files, no PHP, JS, test, migration or config); `ledger:verify` OK; CI on the PR head `7cca91a` green on **both** jobs: `CI (PostgreSQL 16)` (server 16.15) 2336 passed / 12022 assertions, `CI (PostgreSQL 18)` (server 18.6) 2336 passed / 12022 assertions, each with "Ledger OK" and the Horizon boot proof · Rehearsal at `19d4411` (unchanged; deploys at step 5) · Advisor: 3 consultations this cycle so far (13:57 and 14:28 on 15a; 15:59 on 15b); the tool does not name its model (§6) · Review verdict: PR #50 at `9f980d0` — 34 verdicts (17 PASS, 13 PASS WITH NOTE, 4 FAIL: 1 Medium, 3 Low); scoped re-review of `7cca91a` — 9 verdicts (4 PASS, 4 PASS WITH NOTE, 1 FAIL Low); no High, so no R109 halt; the Medium and two Lows fixed in fix loop 1 (`7cca91a`), the re-review's FAIL fixed in this post-merge record; fix loop 1 of 2 used · Context size: not measured by the tool (the session has been compacted several times).

## §1 Git state
`main` and `origin/main` at `0d479dd` (PR #50 squash-merged, CI green on both jobs on its head `7cca91a`) plus the docs-only post-merge record on top of it (the commit that carries this file; `git log origin/main..HEAD` is quoted in CYCLE-LOG NOTE after the push). Branches `cp/15a-status-endpoint` (`146258c`) and `cp/15b-cloud-repo` (`7cca91a`) stay on `origin` (CLAUDE.md: only a merged `hold/<step>` may be deleted). `rehearsal` at `19d4411`. No open PR.

## §2 Step map
1. Docs-only commit of PLAN r1 and the planner-written cloud files — **done**, `4238911`.
2. `cp/15a-status-endpoint` (R193) — **done**: merged `fa74c64` (PR #49). The R193 line "R91 gains the curl form with `TRUSTUTOR_STATUS_TOKEN`" is carried out at step 5, not in a PR: no CC-owned runbook carries R91 (CYCLE-LOG ADVISOR 14:28).
3. `cp/15b-cloud-repo` (R194, R198) — **done**: merged `0d479dd` (PR #50): CI matrix on PostgreSQL 16 and 18 (R194's "composer test passes on 16 as well as 18" is met, no incompatibility to document); CLAUDE.md "Cloud sessions"; HOW-WE-WORK v1.4 (R177 and R178 folded in, new §17). Post-merge record on `main`; smoke on `main` at the merge commit recorded in CYCLE-LOG NOTE.
4. `cp/15c-display-names` (R195) — **next**: branch from `main` after this record; advisor consultation 1 (design) before the first edit, 2 (pre-PR) before the PR.
5. Rehearsal deploy (R111), R91 + R169 checks (including the first `/ops/status` read if the owner has placed a token), END — not started.

## §3 What changed
- Merged to `main` (`0d479dd`): `.github/workflows/ci.yml` (matrix `postgres: ['16','18']`, `fail-fast: false`, job names `CI (PostgreSQL 16)` and `CI (PostgreSQL 18)`; `main` has no branch protection and no rulesets, so the rename broke nothing); `CLAUDE.md` "## Cloud sessions" (four bullets: no `/clear`; no `CLAUDE.local.md` so no ssh, and `ops:status` describes the environment it runs in, never a server; R178 does not apply; deploys stay push-to-deploy on a `main` commit green on both PostgreSQL jobs); `docs/HOW-WE-WORK.md` v1.4 (the same four lines as §17, R177 in §14, R178 folded into the §14 Herd line, the §14 closing note corrected). Appendix B is unchanged by design.
- Post-merge record (docs only): `docs/CHECKPOINTS.md` line 209 corrected (the re-review's one FAIL: it still said `ops:status` lets a session with no shell read the deploy's state), CYCLE-LOG, this file. No ADR and no DATA_MODEL change for 15b.
- Untouched: `scripts/cloud-session-start.sh` and `.claude/settings.json` (committed at step 1; outside CC's edit scope), `LessonStateMachine.php`, `LedgerService.php`, `app/Services/Payments/*`.

## §4 Decisions and by whom
- Planner, accepted by the owner ("go ahead with recommendation", 2026-10-13): PLAN cycle 15 r1, R193–R198.
- CC: PostgreSQL 16 compatibility claimed only after the PG 16 job ran green (ADVISOR 15:59); the Medium found in round 1 handled through the R197 fix loop, not a halt (DECISION 16:22); the self-merge under R197 and R147 with both checks named (DECISION 16:28); fix loop 2 deliberately not spent on Low wording notes; HOW-WE-WORK numbered v1.4 (§6); DATA_MODEL for 15c will be v1.13 (§6).

## §5 Why stopping
Not stopping: this file is rewritten at the step boundary as the loop requires. Step 4 (`cp/15c-display-names`) starts next.

## §6 Mismatches
- **Hook password (new; Owner action 8).** `scripts/cloud-session-start.sh` line 6 holds a literal throwaway password for the sandbox PostgreSQL. It is a sandbox-only value and not a real secret, but the repository is public and a literal credential invites secret scanners and copying. The file is outside CC's edit scope (PLAN "CC may NOT"), so this is disclosed, not fixed, and the value is not repeated here.
- **Hook behaviour (new, round-1 verdict 4; out of scope).** The `SessionStart` command path in `.claude/settings.json` is relative, so it depends on the session's working directory; the empty matcher fires on startup, resume, clear and compact, so `migrate` runs on every compaction; the script has no `set -e`. None of this runs locally (the guard on line 3 exits at once unless `CLAUDE_CODE_REMOTE=true`, proved at step 1). The pilot (R196) is the test.
- **How `docs/PLAN.md` reaches a cloud clone (new; unproven until the pilot).** The owner loop has the planner write PLAN.md uncommitted into the local checkout, so a clone from GitHub would not contain a plan that has not been committed. `docs/cloud/ENVIRONMENT.md` does not say how the cloud session gets it. Planner to say, before R196.
- **Cloud unknowns (new, all unverified until the pilot).** Whether a cloud session can push `rehearsal` (R111 lives in PLAN and `CLAUDE.local.md`, which a cloud session lacks); whether the `/ops/status` IP allow-list would return 404 to the cloud VM's egress address (the allow-list is optional and empty by default); `DB_DATABASE=elearning` in the cloud environment against `phpunit.xml`'s `elearning_testing` (no `force`); the CI image `postgres:16` is not the cloud VM's apt PostgreSQL, so PG 16 is proven only for the Docker image.
- **Round-2 review notes carried, all Low, wording only, in files already merged (new).** (a) HOW-WE-WORK §17's lead-in still says "the method is unchanged … except for four lines", while CLAUDE.md now says everything applies "except what exists only on the owner's machine"; (b) neither document names the host `GET /ops/status` is called on (PLAN R193: `https://rehearsal.trustutor.com/ops/status`); (c) "a `main` commit whose CI is green" can only mean the latest code commit, because docs-only commits carry `[skip ci]` and `paths-ignore` skips docs, so the tip of `main` often has no CI run; (d) in CLAUDE.md the "never quote a password, connection string …" sentence sits in the Herd paragraph and could be read as excused in the cloud (HOW-WE-WORK §8 and the repository rule at line 11 still bind), "four ways" is no longer exhaustive, and the Stack line says PostgreSQL 18 while the cloud VM runs 16. A plan that authorises it lets CC fix (a), (b) and (d) in one docs-only commit.
- **Doubled CI minutes (new).** Both matrix legs run the full suite; the two legs share one Composer cache key (a harmless warning on a miss). Public repository, negligible; not documented elsewhere.
- **DATA_MODEL numbering.** The plan names v1.13 for 15a's table, but the file stood at v1.11 (v1.12 was never used), so 15a is v1.12 and 15c will be v1.13.
- **HOW-WE-WORK version.** R194 and R198 say "HOW-WE-WORK v1.3", but the file already was "Version 1.3 · September 2026" (R119, 2026-09-26). Written as v1.4 with a "Changes in 1.4" line (merged in `0d479dd`); the planner's Project copy needs the same number.
- **R178 versus the CLAUDE.md "Local environment" line.** HOW-WE-WORK §14 now carries R178; CLAUDE.md's "Local environment" paragraph still says Herd and its services are started and stopped only by the owner. As in cycle 12 r2, CLAUDE.md is not edited beyond the "Cloud sessions" section R198 authorises. Planner: align the line when convenient.
- **Appendix B versus CLAUDE.md rule 13 (pre-existing).** Appendix B differs from CLAUDE.md's rule 13; unchanged by design.
- **`docs/cloud/ENVIRONMENT.md` is stale in three places (planner-written, not edited).** §2 prints an older copy of the hook script (no `.env` copy, no `key:generate` lines; the committed script is the newer one and is what runs); §2 calls `.claude/settings.json` "owner-only", but PLAN R194 had CC commit it; §3 still says "until the status endpoint exists", and the endpoint exists since `fa74c64`. Planner: refresh when convenient.
- **Audit scope of `/ops/status` versus R193 (DEVIATION 2026-10-13 15:12).** R193 says the endpoint "logs each call to the audit log with the caller IP". As built, a successful read is audited (`ops_status_token.read`, caller address) and a rejected call goes to `laravel.log` (reason code and address) only, because `audit_logs` needs a subject and most rejected calls have none. Ruled on by the owner in Owner action 7 below; nothing waits for it.
- **Local suite not re-run before the 15b push.** HOW-WE-WORK rule 7 asks for a green suite before every feature-branch push; the diff held no PHP, JS, test, migration or config file, so the local baseline was the smoke on `main` at `fa74c64` and the literal counts for the head come from the two CI jobs (CYCLE-LOG NOTE 16:07). A local smoke on `main` at the merge commit followed (head of this file).
- **A flag used by mistake (logged).** `git commit -n` on the 15a post-merge record is `--no-verify`; no hook exists in this repository, so nothing was skipped (CYCLE-LOG NOTE 16:04).
- **Left unfixed from the 15a reviews, all Low:** the 27 PASS WITH NOTE items stay in CYCLE-LOG REVIEW 15:12 (for example the per-IP throttle is weak for IPv6; a revoke racing a rotation can report "nothing revoked"; the plaintext token sits in the Livewire payload until dismissed); from the re-review: the identical-404 test depends on the order of the response keys, the secrets test does not probe the connection-object path, `CREATE INDEX` on `video_webhook_events` takes a short write lock, and the Operations page has no page-level `canAccess()` (the panel gate and the per-action role check hold).
- **Carried from cycle 14 r2 (unchanged, none touched this cycle):**
  - R189(a) is met inside Daily's frame, not in our own markup; the pre-join screen, the two new Daily properties (`enable_prejoin_ui` on room and token) and the permission prompts are unproven until Owner action 1 below.
  - R189(b) wording versus `DisputeOpenedAdminNotification` (sends `learner_display_name` to admins); and whether rooms should be capped at two people.
  - The advisor tool does not name its model: consultations are counted on the session's configured `advisorModel` (`claude-fable-5-1`) basis with a disclosure, which rule 11 may not accept.
  - `MessageMasker` gaps (a combining digits U+A8E0–A8E9; b non-ASCII look-alike domain endings; c the country-code heuristic loses characters in names such as "Sa'id", "Mary-Jo" and reads "Dr. Dev Patel" as a domain; d "Ali. co" not read as a domain); the PR #47 Low notes; the 409 handler is global; tutor bio and headline are not masked. Stay carried until a masker cycle (PLAN: out of scope); step 4 builds its validator around them (R195: "a masker check", and the admin as backstop).
  - Sender name: rehearsal still sends as "TrusTutor (Support)" from the `from_name` setting, which CC may not write (Owner action 3).
  - Local dev database has five older migrations pending; the test database migrates itself.

## §7 Next step / Owner actions
No action is needed from the owner to continue this cycle: CC proceeds with step 4, then step 5. Step 5 lists the R196 pilot actions at END. The actions below are open and none blocks step 4.

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

**Owner action 8: the literal password in the cloud hook (§6, new).**
 1. (Recommended) Ask the planner to change `scripts/cloud-session-start.sh` to read the sandbox database password from a variable set in the `trustutor` cloud environment (CC may not edit that file). Reason: a literal credential in a public repository is a bad habit and trips secret scanners, even when the value only protects a throwaway sandbox database.
 2. Leave it as it is: the value guards nothing outside the cloud VM.
Reply `update` when done.

Owner-blocking, carried: D-04 with counsel (overseas tutor payouts and tax, recording consent); authorising `trustutor-production`; Postmark wiring (R120).

## §8 Programme board
Programme "cloud-ready" (cycle 15 r1).
| # | Item | State |
|---|------|-------|
| 1 | PLAN r1 commit + planner-written cloud files | done `4238911` |
| 2 | 15a status endpoint (R193) | done — merged `fa74c64` (PR #49) |
| 3 | 15b cloud repo (R194, R198) | done — merged `0d479dd` (PR #50), post-merge record on `main` |
| 4 | 15c admin-approved display names (R195) | next |
| 5 | Rehearsal deploy, R91, R169, first `/ops/status` read, END | — |
Resume cap 0 of 8.

## Deferred
- **`max_participants: 2` on Daily rooms** — a Daily property that cannot be run from here.
- **Inertia history encryption** — app-wide; page props (including the lesson title) stay in the browser's history state on a shared device.
- **English title literals in `LessonRoomView`** — "with" and "for" not yet translated; wait for the i18n pass.
- **`MessageMasker` gaps (§6)** — touches messaging and invariant 8; planner to schedule.
- **`YearGroupMigrationTest` line 27 is order-dependent** — add an `ORDER BY` or compare with `toEqual`; a test-only fix.
- **A screen in our own markup** (R189(a) as worded) — only if the planner accepts the cost recorded in cycle 14 r2 §6.
