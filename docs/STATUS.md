# STATUS — cycle 13 r1 — written 2026-10-07 18:57 (END: 13a and 13b merged, rehearsal deployed at `2f50206`; halting for R181)
Tests: 2181 passed / 10990 assertions on `main` at `2f50206`; `ledger:verify` OK on `main` and on rehearsal; `npm run build` exit 0; phpstan 0, pint clean, RTL green · Advisor: 4 consultations this cycle (13a 3, 13b 1), all answered, model named as "not reported by the tool" per R63 · Review: PR #44 merged under R182 after a fresh review; PR #45 11 verdicts, 1 FAIL (Medium) fixed in round 1 of 2 and re-checked PASS · Context: not measured by the tool this write · Resume cap 0 of 8 · Halted at END.

## §1 Git state
`main` and `origin/main` at the cycle-13 END docs commit (after `2f50206`), working tree clean. `rehearsal` at `2f50206` (fast-forwarded from `421cb3f`); the server head reads `2f50206`. No open PR. No production server exists.

## §2 Step map
1. Commit PLAN r1 — done (`bf4ee96`).
2. 13a, R179 tutor-chosen booking lead time — done: PR #44 merged `0c21a93`, post-merge record `01e0108`.
3. 13b, R180 webhook delivery visibility — done: PR #45 merged `2f50206` under R182/R147 with CI green on the head.
4. Rehearsal deploy (R111), R91 checks with the R169 Horizon lines, `allow_immediate_booking` check, END — done (CYCLE-LOG 18:46, 18:50, 18:55).

## §3 What changed
- **13a (R179):** a tutor chooses the shortest notice they accept (`tutor_profiles.min_lead_hours`), from the admin's `lead_time_options`; unset falls back to `booking_min_lead_hours` (12). New admin settings group `booking` with `allow_immediate_booking` (default true outside production). Demo tutors set to lead 0 by migration. ADR-025, DATA_MODEL v1.9.
- **13b (R180):** every request that reaches `POST webhooks/video/{code}` writes one metadata row to `video_webhook_deliveries` (provider, time, HTTP status, outcome, body length, and for received/duplicate/ignored only: event type, event id, key names). Never a body, header, signature or value. `php artisan video:webhooks {--since=24h} {--limit=50}` lists them; `video:prune-webhook-deliveries` deletes rows older than 30 days daily at 03:30. ADR-017 amended, DATA_MODEL v1.10.
- **Deploy:** rehearsal fast-forwarded to `2f50206`; four new migrations (`2026_10_07_100000`, `100100`, `100200`, `110000`) ran; 0 pending.
- Server commands were allow-listed reads only, plus the R111 deploy.

## §4 Decisions and by whom
- Planner (PLAN r1): R179, R180, R181, R182 (merge rule), the programme shape.
- CC, logged as DECISION: lead-time fallback and rounding (ADR-025); the delivery row is written once at each exit of the controller with the final status, not literally before the events logic; an `outcome` column added beyond the plan's list; the review disposition and the merge of both PRs under R182/R147 (quoted in the log).
- CC, logged as DEVIATION: the `booking` settings-group migration and the demo-tutor lead migration (neither was named in the plan); the Profile-page lead-time control for already-submitted tutors.
- Advisor: pre-push consultation on 13a led to the settings-group migration test and the Profile-page fix.

## §5 Why stopping
The plan ends here: R181 is an owner-run proof on Daily's side, and CC may not run it (no browser, no real identities). Offering a clear because this is a halt that needs the owner.

## §6 Mismatches
- **DATA_MODEL version:** the plan assumed v1.8 at the start; it was v1.8, so 13a produced v1.9 and 13b v1.10.
- **R181's booking time:** lead-0 slots step in whole hours, so the booking must target the next top-of-hour that falls inside the tutor's availability, not "now plus a few minutes".
- **Reminders:** a lesson booked less than an hour ahead may send an immediate "1h" reminder mail.
- **Profile and dashboard selects** save the effective value as the explicit choice.
- **`allow_immediate_booking`:** `config:show` shows the config default (true, APP_ENV is `rehearsal`). A `settings` row can override it and CC cannot read rows (no raw SQL). If R181's booking is refused as too soon, check that setting in Admin first.
- **`crontab -l`** prints "no crontab for forge" on rehearsal; the scheduler is a Forge scheduled job, proved by `/home/forge/.forge/scheduled-2138095.log` modified within the same minute.
- **Log clock:** earlier cycle-12 entries show times up to 18:42, ahead of this machine's clock when this cycle's entries were written (18:09 to 18:40); the order in the file is the true order.
- **ADVISOR entries** say "not reported by the tool": that is R63's fixed phrase, not a failure to consult.
- **Same-day first-lesson generation gap** (13a review item 3): carried to the planner.
- **Low code items from the 13a review**, carried to the owner: a deny-list env default for the flag (2a), the `resolve()` docblock (2b), a hard-coded "24 hours" (4), items 9c and 9d.
- **Pre-existing, from the 13b review:** `DailyVideoProvider::reject()` logs the raw `X-Webhook-Timestamp` on the `no_key` and `bad_timestamp` paths (not a secret). Any Throwable in the webhook controller is recorded as 500.
- **Unauthenticated writes:** the delivery row is written for every request that reaches the controller; the throttle is 300 a minute per IP and an unauthenticated row is a few dozen bytes, pruned at 30 days.
- **PRD §2.2 point 2** permit text is stale (carried). **CLAUDE.md and HOW-WE-WORK** lag R177 and R178.

## §7 Next step / Owner actions
**Owner action 1 (Recommended): re-run the Daily proof (R181) on rehearsal.** Book a lesson with a demo tutor (lead 0) for the next top-of-hour inside their availability, join it as the parent on Daily, and leave. The delivery table will then show whether Daily called us and what we answered. Reply `update` when done.

**Owner action 2: allow CC to read the deliveries.**
 1. (Recommended) Add `php8.4 artisan video:webhooks --since=24h` to the rehearsal read-only allow-list in `CLAUDE.local.md`. Reason: it prints statuses and key names only, never a payload, so CC can read the R181 result itself.
 2. Leave the list as it is and run the command yourself over ssh, pasting only the output.
Reply `update` when done.

Then `/clear` this session and reply `update — <answer>`.

Carried unchanged, at the owner's pace (PLAN items 8, 9, 12): UI-developer reviews (booking screen PR #22, 7g branding, messaging/reviews/Report button walkthrough). R174(c), the SendPulse "Unsubscribe" link, owner-only, no code. Owner-blocking, carried: D-04 with counsel (then CP5); authorising `trustutor-production`. Also carried: Postmark wiring (R120). Roadmap items from 2026-10-01 stay gated on consent text with counsel.

## §8 Programme board
Programme "lead-time" (cycle 13 r1) complete.
| # | Item | State |
|---|------|-------|
| 1 | PLAN r1 commit | done `bf4ee96` |
| 2 | 13a R179 lead time | merged `0c21a93` (PR #44) |
| 3 | 13b R180 webhook visibility | merged `2f50206` (PR #45) |
| 4 | Rehearsal deploy, R91, R169, END | done at `2f50206` |
| R181 | Daily proof re-run | open, owner |
Resume cap 0 of 8.
