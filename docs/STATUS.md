# STATUS — cycle 14 r2 — END, written 2026-10-12 19:20 (programme "clarity" complete; resume cap 0 of 8)
Tests: **2266 passed / 11628 assertions** on the 14c head `59ae35f` and on `main` at the merge `19d4411` (CI runs 38267822380 and 38268229109, literal `Tests: 2266 passed (11628 assertions)`) · `ledger:verify` OK, locally and on rehearsal · phpstan 0 · pint clean · vue-tsc exit 0 · `npm run build` exit 0 · RTL check green (measured before PR #48; the fix loop touched only tests and docs) · Rehearsal at `19d4411`: R91 and R169 checks green (CYCLE-LOG, this date) · Advisor: 11 consultations this cycle (14a ×2, 14b ×7, 14c ×2); counted against the plan's minima: 14a 1 of 3 (**short by 2**), 14b met, 14c met; the tool does not name its model, so all are counted on the configured `claude-fable-5-1` basis (§6) · Review: PR #47 round 4 FAIL (High, resolved by R192's removal branch), PR #48 round 1 highest severity **Low**, fixed in fix loop 1 of 2 · Context: not measured by the tool this write; at least 2 compactions since the last `/clear`.

## §1 Git state
`main` and `origin/main` at `19d4411` (squash of PR #48) plus this docs commit. `rehearsal` at `19d4411` (fast-forwarded 2026-10-12 19:13:32, deployed by Forge). Branches `cp/14a-approval-rule`, `cp/14b-onboarding-clarity`, `cp/14c-lesson-polish` merged, not deleted. No open PR. An untracked `docs/cloud/` is not mine and is left alone.

## §2 Step map
1. Commit PLAN r1 and close R184 — done.
2. `cp/14a-approval-rule` (R185) — done, merged `3aaac2c` (PR #46).
3. `cp/14b-onboarding-clarity` (R186, R187, R188) — done, merged `3aa33dc` (PR #47); R188(a) removed under R192.
4. `cp/14c-lesson-polish` (R189) — done, merged `19d4411` (PR #48).
5. Rehearsal deploy (R111), R91 and R169 checks, END — done; this is the END halt.

## §3 What changed
- **14a (R185):** approved needs name, country, timezone, CV or LinkedIn, agreement, one subject and a rate inside the band; searchable and bookable needs approved plus one availability window (`bookable()` gained it); admin can edit a tutor's availability (audited); "Request changes" is a checklist of sections plus a note.
- **14b (R186–R188):** three-group Required/Optional checklist for tutors, the same on the admin review page, a parent "Get started" page and banner, the rate step priced against the highest level taught, a 409 on a form submit shown as a toast, the Subjects row wraps, "Today" keeps lessons already taught, lesson-mail brand test. The free-text display name was built and removed (R192): no column, rule or field; parents still see the first word of the account name.
- **14c (R189):** (a) Daily's own pre-join check (camera and microphone preview, browser permission, device pickers, its own Join) now shows inside the embedded frame before anyone is in the call; `enable_prejoin_ui` is sent on the room and on every meeting token, and the frame is mounted only after the "Join the lesson here" click. (b) The lesson title reads "<subject> with <learner>" for the tutor and "<subject> with <tutor> · for <learner>" for the parent; the lesson page's props no longer hold either display name, and the browser-tab title names no person. (c) The Join button hides once the room is open; a line says to reload the page to join again. `docs/reports/14c.md` holds the how, the deviation and what was not run.
- **Rehearsal:** at `19d4411`, one additive migration ran in the deploy, no seed.
- **R184:** the Daily proof was read and recorded in step 1 (CYCLE-LOG 2026-10-08 14:15, VERIFICATION: every delivery for lesson 3 answered 200 and stored); the retry test is §7 Owner action 2.

## §4 Decisions and by whom
- Owner (PLAN r2): R185–R189; R190 (self-merge under R147 with CI green on the head); R192 (one more fix loop, one review, removal branch on a High); R111 (standing rehearsal-deploy authorisation, quoted in the log before the deploy).
- CC, logged as DECISION: removing the free-text display name under R192; merging PRs #46, #47 and #48 with R190 and R192 quoted; Daily's own pre-join inside its frame rather than a screen of our own (§6); keeping the parent-side Daily `user_name` as the learner's display name (its only recipient is the lesson's tutor); deploying the code commit `19d4411`, not a docs-only tip.
- Advisor (`claude-fable-5-1` basis): remove by targeted revert and keep the masker findings visible (14b); pre-join via Daily's frame, token-level property as well as room-level, and "nothing in the committed tree blocks the push" (14c).

## §5 Why stopping
The plan says halt at END (step 5, "halt: yes, END"). Nothing is half-done. Handoff: **done** = everything in PLAN r2 and rehearsal live at `19d4411`; **next** = the owner actions in §7, then the planner writes the next PLAN (it reads this file); **ruled out** = a pre-join screen on `@daily-co/daily-js` (it rebuilds Daily's screen, 1.2 MB, a new dependency), `max_participants: 2` on rooms (a Daily property that cannot be run from here), Inertia history encryption (app-wide), the free-text display name (R192), and anything state-changing on rehearsal beyond the deploy.

## §6 Mismatches
- **R189(a) is met inside Daily's frame, not "on the lesson page" in our markup.** A device ID from `enumerateDevices()` on our origin is salted per origin and means nothing inside Daily's cross-origin frame, so a screen of ours would have working previews and pickers that change nothing in the call. The `@daily-co/daily-js` route would rebuild Daily's screen. Planner: if a screen in our own markup is wanted, a plan line must say which cost is accepted. With a provider that has `supports_embed` off there is no step on our page at all; Daily's check then appears only in the new tab.
- **Not run, only read:** that Daily's API accepts `enable_prejoin_ui` on a room and on a token, that the screen appears, that the pickers work, and how many permission prompts the browser shows. No Daily key is held (R125) and no browser was driven. If the token property were rejected, every join answers 422 "The video room is unavailable right now" (fix: delete one line in `DailyVideoProvider::joinToken`); if the room property were rejected, room creation fails (fix: delete one line in `createRoom`). Owner action 1 exercises both.
- **`Show.vue` is checked by source-reading Pest tests only** (no JS runner in the repository). Mutation probes confirmed the tests go red on the changes they guard.
- **R189(b) wording versus an existing notification.** "The learner's name is never shown to anyone but the tutor of that lesson and the parent" conflicts, read literally, with CP8's `DisputeOpenedAdminNotification`, which sends `learner_display_name` to every active admin when a dispute opens. Daily itself, the platform's Daily account holder, and anyone a party forwards their join link to also see the name Daily is given for the parent's side. Not changed here. Planner: say whether admins handling a dispute are an exception, and whether to cap rooms at two people.
- **Advisor.** The tool does not name its model. Every consultation is counted on the session's configured `advisorModel` (`claude-fable-5-1`) basis with a live quote and a disclosure, which rule 11 may not accept; a ruling against it lowers every figure above. 14a's minimum was 3 and one consultation was counted, a shortfall of 2, found at END, not caught at the time (my miss). None was taken for R184, the merges or the deploy.
- **Log timestamps.** Several CYCLE-LOG entries today carry estimated heading times, some later than the commit that holds them; the NOTE at the end of the log lists each against its commit time. Content, order and hashes are correct. My miss; every heading time is now read from the clock in the writing command.
- **Not fixed (all under `## Deferred`):** `max_participants` on rooms; Inertia history encryption (page props, now including the title, stay in browser history on a shared device); the English title literals in `LessonRoomView` (not yet translated); `titleFor` reads `->subject->name` without a null-safe call (safe: `subject_id` is NOT NULL); the flaky `YearGroupMigrationTest.php:27`.
- **Carried from 14b:** the `MessageMasker` gaps (a combining digits U+A8E0–A8E9; b non-ASCII look-alike domain endings; c the country-code heuristic loses characters in names such as "Sa'id", "Mary-Jo", "Noor-ul-Ain" and reads "Dr. Dev Patel" or "A. Page" as a domain; d "Ali. co" is not read as a domain); the PR #47 Low notes (LinkedIn tag wording when an admin marks the CV type required; the "contact" tick also needing the account name; `bookable()` ignores rate and documents; the "profile is with the review team" lock message shows for approved, rejected and suspended tutors; a draft or changes-requested tutor no longer sees the onboarding banner or the requested-changes nudge on the dashboard; the unused prop `missingForSubmission`; the admin checklist memo is per record object; the 409 handler is global; tutor bio and headline are not masked).
- **Sender name:** rehearsal still sends as "TrusTutor (Support)" (`mail.from.name`); the source is the `from_name` setting, which CC may not write (Owner action 3).
- **Subjects row:** checked by reading the layout only; no browser tool (Owner action 5).
- **Local dev database:** five older migrations (2026-10-07 and 08) are pending there; the test database migrates itself. Not run on the dev database.

## §7 Next step / Owner actions
**Owner action 1 (Recommended): prove the pre-join screen and both new Daily properties on rehearsal.** (1) As the demo parent, book a lesson with a demo tutor that starts **more than 15 minutes from now** (its Daily room is created 15 minutes before the start, so it is made after the deploy and carries the new room property). (2) Open the lesson page as the parent about 10 minutes before the start: the title reads "<subject> with <tutor first name> · for <learner>". Click "Join the lesson here": expect Daily's check inside the frame (camera and microphone preview, device pickers, Daily's own Join), and the Join button gone. Click Daily's Join and confirm you are in the call; leave. (3) As the tutor, the title reads "<subject> with <learner>". (4) If joining answers "The video room is unavailable right now", or the lesson never gets a room, reply with exactly that: the cause is one of the two properties and the fix is a one-line deletion. Reply `update` when done.

**Owner action 2: the Daily retry test (R184), after Owner action 1 is clean.** Have the real Daily webhook secret open in Daily's dashboard **before you start**: once overwritten it cannot be read back from our admin. (1) Admin → Video providers → Daily → Webhook secret: type `bm90LXRoZS1zZWNyZXQ=` (a wrong but well-formed value) → Save. (2) Book another demo-tutor lesson for the next top of the hour inside the tutor's availability, join it as the parent, leave. (3) Reply `update`; CC reads `video:webhooks --since=1h` and should see deliveries rejected (401) and no stored events. (4) Admin → Video providers → Daily → Webhook secret: paste the real secret from Daily → Save. (5) Reply `update` again after an hour; CC reads the same command. Whether and when Daily redelivers a failed webhook is Daily's behaviour and unverified; this test exists to find out, so a result of "no redelivery" is an answer, not a failure.

**Owner action 3: the sender name.**
 1. (Recommended) In Admin → Settings → Mail, set "From name" to `trusTutor Team`. Reason: rehearsal sends as "TrusTutor (Support)" today and CC may not write settings.
 2. Leave "TrusTutor (Support)" and tell the planner it is the intended name.
Reply `update` when done.

**Owner action 4: the SendPulse unsubscribe link (R174(c)).**
 1. (Recommended) In the SendPulse account, turn off list-unsubscribe and unsubscribe-link insertion for transactional SMTP. Reason: lesson and account mails are transactional and the link is injected by SendPulse, not by us.
 2. Leave it on and tell the planner.
Reply `update` when done.

**Owner action 5: the Subjects form.** Open the tutor onboarding Subjects step on rehearsal at 1280px and at 375px wide and confirm the subject row wraps without overflow. Reply `update` when done.

**Owner action 6: UI-developer review of the new onboarding checklist and copy** (replaces carried item 12's scope): tutor onboarding, tutor dashboard, the admin review page and the parent "Get started" page, with `docs/copy/onboarding.md`. Carried items 8 and 9 (booking screen PR #22 with 7g branding; messaging, reviews and the Report button walkthrough) stay at the owner's pace. Reply `update` when done.

**Owner action 7: planner decisions.**
 1. (Recommended) Ask the planner ("where are we?") to write the next PLAN with "admin-approved display names" (R188(a), carried by R192: a status field and an admin action) and the two wording questions in §6 (admin dispute notification; a screen in our own markup, or Daily's frame as built). Reason: each needs a plan line before code, and none blocks the owner actions above.
 2. Ask the planner to defer all three and run the retry test first.
Reply with 1 or 2, then `/clear` this session and reply `update — <answer>`.

Owner-blocking, carried: D-04 with counsel (overseas tutor payouts and tax, recording consent); authorising `trustutor-production`; Postmark wiring (R120). Roadmap items stay gated on consent text with counsel.

## §8 Programme board
Programme "clarity" (cycle 14 r2) complete.
| # | Item | State |
|---|------|-------|
| 1 | PLAN r1 commit, R184 | done |
| 2 | 14a R185 | merged `3aaac2c` (PR #46) |
| 3 | 14b R186-R188 | merged `3aa33dc` (PR #47); R188(a) carried as "admin-approved display names" |
| 4 | 14c R189 | merged `19d4411` (PR #48) |
| 5 | Rehearsal deploy, R91, R169, END | done, rehearsal at `19d4411`, this END |
Resume cap 0 of 8.

## Deferred
- **Admin-approved display names (R188(a))** — carried by R192; needs a PLAN revision for the status field and the admin action.
- **`max_participants: 2` on Daily rooms** — would narrow who can sit in a lesson room; another Daily property that cannot be run from here.
- **Inertia history encryption** — app-wide; page props (now including the lesson title) stay in the browser's history state on a shared device.
- **English title literals in `LessonRoomView`** — "with" and "for" are not yet translated; wait for the i18n pass.
- **`MessageMasker` gaps (§6 a–d)** — touches messaging and invariant 8; planner to schedule.
- **`YearGroupMigrationTest` line 27 is order-dependent** — add an `ORDER BY` or compare with `toEqual`; a test-only fix.
- **A screen in our own markup** (R189(a) as worded) — only if the planner accepts the cost in §6.
