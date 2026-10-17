# STATUS — cycle 17 r1 — step 2 in progress; written 2026-10-17 16:18
Tests: **not yet run this cycle** (`main` at `94d8afa` code: 2646 passed / 13419 assertions locally, 13412 in CI, green on both PostgreSQL jobs; the 12:27 cloud control run 2646 / 13412) · `ledger:verify`: OK on rehearsal through `/ops/status` at 12:05 · Advisor: **consulted 0 times so far this cycle** (the 17a design consultation is launched, not yet answered; counted on the configured `advisorModel` basis with the R63 phrase when it is) · Review: n/a (no PR yet) · Context size: not measured by the tool (cloud session, no `/context`).

## §1 Git state
- `origin/main` = `e8af468` ("PLAN.md cycle 17 r1 … [skip ci]", the planner's commit under R215); `git fetch origin main` at 16:12 found nothing newer; local `main` = `e8af468`. `docs/PLAN.md` on disk equals the committed one, so no PLAN commit was needed (rule 1 as a no-op, R215).
- This is a cloud session on the `trustutor` environment; the harness put the clone on `claude/amazing-brown-rjmvya`, cut at `e8af468`. Under R210 that branch is scratch: this docs-only commit (`docs/CYCLE-LOG.md`, `docs/STATUS.md`, `[skip ci]`) is made on it and pushed to `main` with `git push origin HEAD:main` (fast-forward only). The code branch `cp/17a-cloud-hardening` is cut from `origin/main`, not from the harness branch, and is not yet created at this write.
- `rehearsal` = `94d8afa` (`git ls-remote`). PRs [#52](https://github.com/rizwanoor80/eLearning-Platform/pull/52) and [#53](https://github.com/rizwanoor80/eLearning-Platform/pull/53) MERGED; `cp/16a-tooling-portable` and `cp/16b-cloud-build` still on the remote, untouched per the authorisation boundary.

## §2 Step map
1. Planner: PLAN r1 and `docs/cloud/ENVIRONMENT.md` on `main` — **[done]** (`e8af468`).
2. 17a under R212 and R210 — **[in progress]**: START logged (CYCLE-LOG 16:15); this STATUS; `composer install --prefer-source` and `npm ci` running in the sandbox; the design consultation launched; no file of R212 edited yet.
3. Rehearsal deploy and verification under R213, R214 value recorded, END — **[not started]**.

## §3 What changed this run
Nothing in code yet. Facts established (CYCLE-LOG START 16:15): R211 is done — the five variables are unset in the sandbox shell, so both R212(1) proof runs can be made here; `vendor/`, `node_modules/` and the phar are absent at session start and `.env` has an empty `APP_KEY` (the R212(3) silent failure, reproduced before the fix); the harness `GH_TOKEN` is invalid for `gh`, so the PR, the CI checks and the merge go through the GitHub MCP tools.

## §4 Decisions and by whom
- Owner (PLAN r1): R210–R215; the authorisation boundary; every session of this cycle is a cloud session.
- CC: the START/STATUS commit goes on the harness branch and is pushed to `main` as R210 says; the harness branch is also pushed as the session's own harness requires (it is scratch, nothing is deleted).
- CC: because `gh` cannot authenticate, PR creation, check reading and the one merge attempt use the GitHub MCP tools; the merge stays a squash as every previous PR (R147's one attempt, no retry).
- Advisor: not yet answered this cycle; "0 times" stands in the header until the entry exists.

## §5 Why stopping
Not stopping: this STATUS is the step-boundary write that step 1 asks for ("step 2 in progress"); work continues at once with the R212 edits, the proof runs, the PR and the review. The next rewrite comes at the PR push, the merge, and step 3.

## §6 Mismatches
- The plan's conditional ("if the variables are still present … Owner action 1") does not apply: the variables are gone (evidence in CYCLE-LOG 16:15).
- Carried, unchanged, from the 12:27 STATUS §6: HOW-WE-WORK §17 "the same four" versus CLAUDE.md's five cloud lines; the R178 wording; the advisor model basis (the tool names no model; counted on the configured `advisorModel`, R63); the Low notes of PR #52 and #53; the setup script's `composer install` not surviving into the session (the session installs itself, about 9 minutes); `.env` role `root` versus the exported `postgres`.

## §7 Next step / Owner actions
Next (CC, no halt): R212(1)–(4) on `cp/17a-cloud-hardening`, the two proof runs with literal counts, PR → fresh-subagent review → CI on both jobs (R177 wait) → one merge attempt under R147 → post-merge record → step 3.

No new owner action this write. Carried unchanged from the 12:27 STATUS §7, independent of this cycle: **Owner actions 2, 3, 4, 6, 7, 8, 9, 10, 11, 12** (the pre-join screen and Daily properties on rehearsal; the Daily retry test R184; SendPulse sender name and unsubscribe link; UI-developer reviews of the booking screen, the 7g branding and the onboarding checklist; the Subjects form at 1280/375 px; the audit scope of `/ops/status`; the literal password in the cloud hook — this cycle's R212(3) removes it, so that action closes when 17a merges; the display-name queue). Their full text is in `git show 467f057:docs/STATUS.md` §7 and is not repeated here. Owner action 5 is reopened by R214: CC changes nothing and records the `mail.from_name` value it reads at step 3. Owner action 1 of 12:27 is closed by R211 and R212.
Owner-blocking, carried: D-04 with counsel; authorising `trustutor-production`; Postmark wiring (R120).

## §8 Programme board
none (cycle 17 r1 is a plain three-step cycle with a resume cap of 8; no programme is active).

## Deferred
Carried unchanged from the 12:27 STATUS (`git show 467f057:docs/STATUS.md` `## Deferred`), except: the two cloud items (the five exported variables; the hook's silent `key:generate`/`migrate` and the literal password) are this cycle's R211/R212 and leave the list when 17a merges; the cycle-17 candidate "harden CI against a Bunny timeout (R209)" is R212(2).
