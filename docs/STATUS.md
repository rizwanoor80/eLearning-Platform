# STATUS — cycle 11 r3 — written 2026-10-01 17:26
Tests: 2052/2052 passed, 10311 assertions (unchanged — no code touched this run) · Advisor: consulted 1 time (1 confirmed) · Review: n/a (no PR this run) · Context: not measured by the tool this write — **HALT: owner action needed.** Root cause of the mail-delivery report is now confirmed, and it is bigger than mail: Horizon on `trustutor-rehearsal` has never provisioned a worker for any queue. The fix needs an authorisation this cycle's plan does not currently grant.

## §1 Git state
`main` at `04d5a19` (PLAN.md cycle 11 r3, `[skip ci]`) plus this write's own commit (docs-only, `[skip ci]`, pushed immediately after writing). `origin/main` fast-forwarded, confirmed current before any work this run. `rehearsal` unchanged at `c4c6d75` — no server state was touched this run; the decisive evidence came from reading two files already in the repo checkout (`config/horizon.php`, `vendor/laravel/horizon/src/ProvisioningPlan.php`), not from any new server command. No open PR. No production server exists yet.

## §2 Step map (cycle 11 r3)
- r1's three programme steps ("daily-webhook") remain **done, END** (CYCLE-LOG 2026-10-01 15:44) — unchanged, reproduced for continuity only.
- r2's R168 mail diagnosis — **superseded by r3's finding**, not re-opened: r2's two undetermined candidates (stale Horizon worker, SendPulse-side silent drop) are withdrawn now that the real cause is confirmed.
- r3's added scope (widened R168 command list) — **done; root cause confirmed; HANDOFF.** The widened log-reading commands ruled out the "daily-rotated file" guess directly; the decisive step was reading `config/horizon.php` and Horizon's own `ProvisioningPlan::deploy()` locally, which the advisor pointed at.

## §3 What changed this run
- Committed the uncommitted `docs/PLAN.md` r3 revision as `04d5a19` before any other work (git fetch confirmed `main` already matched `origin/main`; r3 had no prior END in CYCLE-LOG).
- Logged **START** and **ADVICE (owner)** entries quoting the triggering chat message, `"update — mail still not arriving"`, sent after the owner carried out r2's Owner action 1 (Forge Horizon restart) and retried.
- Ran r3's widened eight-plus-four read-only command list over the allow-listed SSH shape and logged the literal output as a **VERIFICATION** entry (CYCLE-LOG 2026-10-01 17:11): all mail/SMTP values unchanged from r2; `config:show logging.default` → `stack`; `config:show logging.channels.stack.path` → expected error (a wrapper channel has no `.path` of its own); `ls -lt storage/logs` → `laravel.log` is 0 bytes since Sep 27 00:00, `laravel.log.1.gz` (393 bytes, Sep 26) is the only other file — no dated/rotated file exists.
- Called the **advisor** before concluding (CYCLE-LOG 2026-10-01 17:18 **ADVISOR**). It corrected r2's §6 BA (the `.gz` file is ordinary system logrotate, not Laravel's `daily` channel — the "dated file" guess was wrong) and pointed at a server-free check: `config/horizon.php`'s `environments` array has no `rehearsal` key and no wildcard, and `ProvisioningPlan::deploy()` silently adds zero supervisors when nothing matches, while the Horizon master process still reports "running."
- Read both cited files locally and confirmed the claim directly: [config/horizon.php:215-229](config/horizon.php#L215-L229) (environments: `production`, `local` only) and [vendor/laravel/horizon/src/ProvisioningPlan.php:103-111](vendor/laravel/horizon/src/ProvisioningPlan.php#L103-L111) (`deploy()` returns early, silently, when `Str::is($name, $environment)` matches nothing).
- Logged a **DECISION** entry (CYCLE-LOG 2026-10-01 17:21) naming the confirmed root cause and withdrawing r2's two undetermined candidates.
- Logged a **HANDOFF** entry (CYCLE-LOG 2026-10-01 17:24) disclosing a plan-internal conflict (the fix touches `config/horizon.php`, outside this cycle's R166 file list and authorisation boundary) rather than opening a branch unilaterally, and am rewriting this file now as the closing record.
- `docs/DECISIONS.md` is unchanged this run — R168 only requires the D-09/SendPulse amendment, already landed in r2.

## §4 Decisions and by whom
- Owner (2026-10-01, in `docs/PLAN.md` r3): widened R168's command list after r2's §6 disclosure.
- Owner (chat, 2026-10-01): `"update — mail still not arriving"`, after performing r2's Owner action 1 — logged as ADVICE before being acted on.
- Advisor (Fable 5.1, configured not measured per R63): corrected the "daily-rotated log" guess and identified the Horizon-provisioning gap as the discriminating, server-access-free check. Adopted in full; both cited files verified directly by CC before being relied on.
- CC: wrote the DECISION entry naming the confirmed cause with file:line citations; wrote the HANDOFF disclosing the plan/authorisation conflict instead of opening `cp/11c-horizon-environments` (or similar) without a ruling.

## §5 Why stopping
**Halt needing the owner.** The fix is known and small (add a `rehearsal` or `*` entry to `config/horizon.php`'s `environments` array, mirroring the existing `local` shape), but PLAN's authorisation-boundary paragraph names only `cp/11a-daily-webhook` and R166's file list for this cycle — `config/horizon.php` is outside both. R168 itself allows "a code fix on a new branch under R167 if the cause is ours," but opening that branch without the boundary paragraph's own sign-off would be self-authorising a gate the plan states (Owner-loop rule 8). **Done:** root cause confirmed by direct code citation, not inference; r2's two candidate causes withdrawn; r2's §6 BA corrected. **Next:** the owner action below.

## §6 Mismatches
Carried from r1 unchanged (items AY, AZ — neither blocking). r2's **BA** is now resolved, not carried forward as open: the "daily-rotated file" guess was wrong (corrected 2026-10-01 17:18, see CYCLE-LOG ADVISOR entry); the real explanation is ordinary system logrotate plus the fact that no queued job has ever run to write a log line. No new mismatch this run — the finding is a confirmed root cause, not a disclosed uncertainty.

## §7 Next step / Owner actions
**Owner action 1 (Recommended): reply `update` to authorise a new ruling/plan revision adding a `rehearsal` (or `*`-wildcard) entry to `config/horizon.php`'s `environments` array** (around [config/horizon.php:215-229](config/horizon.php#L215-L229)), shaped like the existing `local` entry — e.g. `'supervisor-1' => ['maxProcesses' => 2 or 3]`. Reason: this is a confirmed, queue-wide defect (every `ShouldQueue` job on rehearsal has silently never run, not just mail) with a one-block fix; CC cannot self-authorise opening the branch because it falls outside this cycle's R166 file list and PLAN's own authorisation boundary. Once authorised, CC will branch, test, PR, review and merge the same way as 11a (R167's merge rule), deploy under R111, restart Horizon, and retest both the mail-verification path and the Daily webhook path (the latter is queued too, so this same defect may be masking retry behaviour there as well, worth the owner's awareness though not re-opening a closed step).

Carried unchanged from r1/r2, at the owner's own pace: **2** Daily webhook proof — two test lessons on rehearsal (one clean, one with the secret broken then restored to force a retry) — note this now also exercises the same queue path, so it should wait until Owner action 1 lands; **3**, **4**, **5** UI-developer reviews (booking screen PR #22, 7g branding, messaging/reviews/Report button walkthrough). Owner-blocking, carried unchanged: D-04 with counsel (then CP5); authorising `trustutor-production`. Roadmap items noted 2026-10-01 (Daily cloud recording, transcription, AI-drafted reports, parent digest, transcript library) remain gated on consent text in the terms and tutor agreement, with counsel, alongside D-04.

This write may be followed by a planner "where are we?" once the owner has decided on the Horizon fix, or the owner may simply reply `update` to authorise it directly — PLAN step 3 resumes on the next `update` regardless of which path the owner takes.

## §8 Programme board
None — programme "daily-webhook" (cycle 11 r1) is closed; the Horizon-provisioning fix, if authorised, would be a small follow-on cycle of its own, not a programme.
