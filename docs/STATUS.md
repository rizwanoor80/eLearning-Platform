# STATUS — cycle 12 r3 — written 2026-10-07 16:28 (END: no code; item 4 still open, halting for the planner)
Tests: not re-run (no code changed); last full suite 2096/2096, 10529 assertions on the 12b head `437977d`; `ledger:verify` OK on rehearsal at 15:20 · Advisor: 0 consultations this cycle (docs-only), 5 verified in r2 · Review: none (no code); r2 PR #42 6 PASS / 0 FAIL, PR #43 PASS WITH NOTE · Context: not measured by the tool this write · Resume cap 0 of 8 · Halted at END.

## §1 Git state
`main` and `origin/main` at the r3 END docs commit (after `ab48d26`), working tree clean. `rehearsal` at `421cb3f` (unchanged, no deploy this cycle). No open PR. No production server exists.

## §2 Step map
1. Commit PLAN r3 — done (`ab48d26`).
2. Read the Daily webhook evidence — done: `video_webhook_events` 0 (CYCLE-LOG 16:15).
3. Close item 4 / ADR-017 note — not done, by the plan's own rule: it needs at least one `participant.joined` row.
4. Confirm TEST RABIA's row — not verifiable by CC (CYCLE-LOG 16:18).
5. Log the three outcomes as ADVICE (owner) — done (16:08).
6. Rewrite STATUS §7, END — done (16:25).

## §3 What changed
Docs only: PLAN r3, CYCLE-LOG entries, this STATUS. No code, no deploy, no server state change. Server commands run were allow-listed reads only (`db:show --counts`, `route:list --path=webhooks`, `ls`, `tail`), plus two unsigned `curl` POSTs to the public webhook URL, which are rejected before any storage (`VideoWebhookController.php:39-63`).

## §4 Decisions and by whom
- Owner (via the planner's record in PLAN r3): the email looks right and the sender name resolved; TEST RABIA onboarded with only a LinkedIn URL, approved with subjects and rate by the admin, and she appears in search; one fresh lesson joined as the parent on Daily. Logged as ADVICE 16:08. CC did not observe any of these.
- Planner (PLAN r3): the standing rule that the owner replies bare `update` and the planner records what he did in PLAN.md.
- CC: none beyond reading the evidence as the plan instructs.

## §5 Why stopping
PLAN r3 says that if `video_webhook_events` is still empty, record that the join produced no webhook and halt with the Daily-side checks for the planner. It is empty (0 rows), so this is that halt. Only Daily's own delivery log can say whether Daily called and was rejected or never called.

## §6 Mismatches
- **The empty log tells us nothing about rejections.** The webhook controller returns JSON on a 401 or 404 and writes no log line (`app/Http/Controllers/Webhooks/VideoWebhookController.php:39-63`), so "no 401/stale lines in our log" cannot be read as "Daily never called". The r3 wording expects such lines; there are none to find in any case. A rejection log line would need a code change, which r3 does not authorise.
- **Our side is healthy:** an unsigned POST to `/webhooks/video/daily` on rehearsal returns 401 (route live, Daily row has a `webhook_secret`); an unknown code returns 404.
- **TEST RABIA's row cannot be read by CC.** The allow-list has counts only; raw SQL and tinker are never-list. `tutor_profiles` stays 9 (also 9 at 15:20), so the count cannot show her approval. The owner's observation that she appears in search is the stronger proof of `bookable()`. If the planner wants CC-readable proof, a read-only report command must be authorised.
- **The 3 Oct joins and the fresh join both produced nothing stored.** Possible causes, in order of likelihood: the webhook is not registered, disabled or pointing at another URL in Daily; Daily is calling and the signature or timestamp check fails (we would answer 401); or the room event type the lesson used is not one `parseWebhook` accepts (we would answer 200 `ignored`, also storing nothing). The third cannot be told apart from the first two from our side.
- **PRD §2.2 point 2 (~line 35)** still says a tutor needs "a valid permit" to appear in search; stale under R170. Planner to authorise the one-line fix.
- **CLAUDE.md and HOW-WE-WORK are behind the plan** (R177, R178; and Owner loop rule 6 versus R175's fix loop). CLAUDE.md is not mine to edit.
- Carried, non-blocking: the review follow-ups (plain-text alternative of the two notifications, `default.css` `text-align: left`, substring sweep tests, no plain-text mailable alternative); LinkedIn host-check `parse_url` disagreement; AY, AZ; the `config/app.php` allow-list; the untraceable "R91" defining text; a Horizon config change needs a daemon restart.

## §7 Next step / Owner actions
**Owner action 1 (Recommended): ask the planner to take the item 4 evidence to Daily, then bring back what Daily shows.** The planner decides the next plan; CC has no Daily access. The checks, all on Daily's side, in the order that narrows fastest:
 (a) In the Daily dashboard (Developers → Webhooks): is a webhook registered for `https://rehearsal.trustutor.com/webhooks/video/daily`, is its state active, and which event types are subscribed (`participant.joined` and `participant.left` are the ones we store)?
 (b) The webhook's delivery log or `failedCount` for the day of the fresh join: any attempts, and the HTTP status we returned (401 means a signature or stale-timestamp rejection; 200 means we accepted and ignored it; no attempts means Daily never sent).
 (c) Whether the secret stored in Admin → Video providers → Daily equals the HMAC secret Daily shows for that webhook (compare the two in the UI yourself; do not paste either into chat or any file).
Reason: our side is proven live and an empty table with no rejection logging cannot be told from "never sent". Reply `update` when done; the planner records the outcome in PLAN.md.

**Owner action 2 (optional): if you want CC to confirm TEST RABIA's status and subjects itself, tell the planner to authorise a read-only report command** (it would be a code change, so a plan cycle). If you are satisfied that she appears in search, skip it. Reply `update` when done.

Carried unchanged, at the owner's pace (PLAN items 8, 9, 12): UI-developer reviews (booking screen PR #22, 7g branding, messaging/reviews/Report button walkthrough). R174(c), the SendPulse "Unsubscribe" link, owner-only, no code. Owner-blocking, carried: D-04 with counsel (then CP5); authorising `trustutor-production`. Also carried: Postmark wiring (R120). Roadmap items from 2026-10-01 stay gated on consent text with counsel.

## §8 Programme board
Programme "tutor-onboarding" (cycle 12 r2, R175) complete. Cycle 12 r3: follow-up, no programme steps.
| # | Item | State |
|---|------|-------|
| 1 | PLAN r3 commit | done `ab48d26` |
| 2 | Item 4 (Daily webhook proof) | open: `video_webhook_events` 0; halt for planner |
| 3 | TEST RABIA row read | not verifiable by CC |
| 4 | Owner outcomes logged | done (ADVICE 16:08) |
Resume cap 0 of 8.
