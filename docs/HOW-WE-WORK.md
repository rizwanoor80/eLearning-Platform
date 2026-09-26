# How We Work — Operating Method for AI-Assisted Projects

_Version 1.3 · September 2026 · Owner: Rizwan_
_Merged from the Franchise OS and SaaS operating manuals. Hand this to every new project on day one together with PROJECT_BRIEF.md, the PRD and CLAUDE.md. Roles, loop, files and rules are fixed; only the names in §16 change per project._

_Changes in 1.1: window/session vocabulary made precise (§1); new §15 Cost and context discipline, covering both seats; advisor consultations must now name the responding model (§10); HANDOFF log kind (§5); `[skip ci]` on docs-only commits (§8); one planning conversation per cycle (§15, Appendix A)._
_Changes in 1.2: the clear moves from every step to the cycle END; auto-compaction inside a cycle is expected, not a failure; the 100k ceiling and the programme context cap are removed (§1, §2, §12, §15, Appendices A–B). 1.1's per-step clear stopped work every few minutes and cost more owner time than it saved in tokens._
_Changes in 1.3 (owner ruling 2026-09-26, R119): the planner asks owner questions one at a time, each with its recommended option first, and writes each answer into PLAN.md as a ruling; every Owner action line carries exactly one question (§2, §7, Appendix A)._

---

## 1. The three parties

**The owner (Rizwan).** Decides, prioritises, sets policy, and personally authorises every external or irreversible action. Does not write code or documents; directs, decides and verifies from evidence. Hands-on across product, architecture, marketing and legal. Is never the messenger between the two seats, and is never reminded of a process already agreed — the files enforce it.

**The planning seat** — a Claude Project (Cowork or Chat) with the repository folder connected. Reads everything: channel files, Git refs, reports. Writes exactly one file, `docs/PLAN.md` (and `docs/PROJECT_BRIEF.md` only on "refresh the brief"). Never runs git or any shell command in the repo, never edits code, migrations or config, never relays instructions to Claude Code through chat. Its job is to think, argue, ask the right questions, recommend, and turn the owner's decisions into a plan Claude Code can execute without talking to anyone. The Project persists; the **conversation inside it does not** — one conversation per cycle, retired when the cycle closes (§15).

**Claude Code (CC)** — the implementer. Owns git, writes all code, runs the tests, deploys when authorised, and reports. Works only from `docs/PLAN.md`, logs while it works in `docs/CYCLE-LOG.md`, reports only through `docs/STATUS.md`. Never asks the owner for anything in chat. Its built-in advisor (a stronger model) is always on, every consultation is logged, and every consultation names the model that answered (§10).

The separation is the point: the planner keeps the plan honest because it cannot touch code; CC keeps the code honest because it cannot change the plan; the owner stays in control because every decision passes through him in writing.

**Words used precisely.** A **window** is one live Claude Code workspace on one checkout — the thing the owner types `update` into. A **session** is one conversation inside that window, ended by `/clear` (or by closing the window), after which a fresh session continues in the same window on the same checkout. A **cycle** is one PLAN.md revision from START to END, and normally spans several sessions. "New session" never means "new window".

**One CC window. One checkout. No worktrees.** Never a second Claude Code window open on the same project — one `update` at a time, always. A window runs many sessions in sequence: each cycle is worked in a fresh session, cleared when the cycle closes, and the next cycle's `update` continues in the same window. That sequence is required, not an exception (§15). What is forbidden is concurrency — two windows, two `update`s, two views of one checkout drifting apart. One window **per project** is correct; three projects in parallel means three windows, each on its own checkout. When a plan needs independence (reviewing the previous cycle's work), CC delegates to a fresh subagent with no prior context inside the current session. Any tool offer to spin work into a separate window is declined.

---

## 2. The loop

1. Owner asks the planner **"where are we?"** → the planner reads the channel files and Git state from the repo (never the owner's memory of them), cross-checks, and briefs: current state with evidence, how CC performed (honestly, including its confessions), what the advisor said and whether it was adopted, recommendation, and what *not* to do.
2. Owner and planner discuss until the owner is satisfied. The planner asks clarifying questions before proposing, gives options with pros and cons, and recommends one. The planner asks questions one at a time, each with its recommended option first and a one-line reason, takes each answer immediately, and writes the answers into PLAN.md as rulings.
3. Owner says **"go ahead"** → the planner writes `docs/PLAN.md` into the checkout, uncommitted, **re-reads it from disk and diffs it against what it intended** before replying with one line — *"PLAN.md written (cycle NN rN) — tell Claude Code: update"* — and a short plain-language summary of what the plan does and what, if anything, the owner will be asked to do.
4. Owner types the single word **`update`** in CC. Nothing else.
5. CC: `git fetch` and fast-forward `main`; read `docs/PLAN.md`; if it is newer than the committed one, commit it as *"PLAN.md cycle NN rN"* before any other work; refuse to execute a revision that already has an END in the cycle log; then execute from the first unfinished step — logging as it goes, writing STATUS.md, committing, stopping at the next gate. When the cycle reaches END it raises the clear action (§15); within a cycle it keeps working, and auto-compaction along the way is normal. If there is no new work, it writes a NOTE and a STATUS.md saying so rather than staying silent.
6. Back to 1.

**Six phrases.** Three drive the loop: `where are we?` (planner → brief), `go ahead` (also "go", "approved", "write the plan": planner → write the plan), `update` (CC → execute). Three are auxiliary: `status` (CC → rewrite STATUS.md from repo evidence, execute nothing, touch no code), `stop` (CC → halt an autonomous programme cleanly with a logged reason and a STATUS.md), `refresh the brief` (planner → update PROJECT_BRIEF.md). If a seventh phrase seems necessary, something is wrong with the files, not the phrases.

---

## 3. Channel files — the only communication path

All under `docs/` in the repository. The repository is the source of truth; chat is not a record. The planning project holds only briefs, plans and this protocol, never a copy of the repo.

| File | Written by | Purpose |
|---|---|---|
| `PROJECT_BRIEF.md` | Planner, on "refresh the brief" only | Goals, architecture decisions, constraints. Stable background, under 200 lines. |
| `PLAN.md` | Planner, on "go ahead" only | The current work order. One revision overwrites the previous; never appends. Under 100 lines. Format in §4. |
| `CYCLE-LOG.md` | CC, during work | Append-only chronological log of typed entries. Format in §5. Advisor consultations live here as ADVISOR entries. |
| `STATUS.md` | CC, at the end of every run | The return channel. Fixed eight-section shape in §6. |
| `DECISIONS.md` | CC, only when the plan authorises | Accepted architecture/policy decisions (ADRs) that outlive any plan. Proposals go in STATUS.md §4 for the owner to accept first. |
| `reports/<step>.md` | CC, optional | Evidence too long for a log entry: full test output, screenshots per state, command transcripts. Always cited from the log entry that relies on it. |
| `CLAUDE.md` (repo root) | Planner drafts, owner approves; **committed** | Engineering conventions, domain invariants, checkpoint protocol, and the Owner-loop block (Appendix B). Binding on CC when building and on the planner when planning. |
| `CLAUDE.local.md` (repo root) | Owner; **git-ignored, never committed** | The security boundary: server allow-list and never-list, hosts, paths, anything environment-specific. CC loads it at the start of every session, so a rule added mid-session takes effect at the next clear or restart — which now happens at every cycle boundary; per-cycle must-haves still go in PLAN.md. |

The planner reads BRIEF, PLAN, STATUS, CYCLE-LOG (recent entries), DECISIONS and CLAUDE.md at the start of every task without being asked. If STATUS.md contradicts PLAN.md or the brief, the planner tells the owner first and assumes nothing is done unless STATUS.md says so.

These files carry the whole state between sessions. A fresh session after a clear knows nothing except what it reads here, which is why §15's handoff rule depends on them being current before any clear is raised.

---

## 4. PLAN.md — format

```
# PLAN — cycle NN rN — YYYY-MM-DD
Standing (applies to every step): suite green with the literal count before every push;
`git log origin/main..HEAD` quoted before every push; each step names its ADVISOR entries
(with the model that answered) or states none was triggered; one cycle per session, cleared
at END; temporary scripts deleted from every server and said so;
if the session nears a plan usage limit, STATUS.md first.
Advisor minimum this cycle: N consultations (design before the first edit; before the PR).
Authorisation boundary: what CC may push/merge/deploy in this cycle without asking, and
what needs an owner GO.

## Rulings (owner decisions, numbered, carried across revisions)
R1 …   R2 …

## Steps
1. <goal> — done means: <literal numbers / quoted output / screenshot per state> — files: <areas> — halt: <only if the owner must act>
2. …

## Out of scope this cycle
…

## Carried forward
Parked items, one line each, so nothing is lost without cluttering the steps.
```

A plan is a contract CC can execute without talking to anyone. Halts exist only where the owner must act; everything else runs through. A cycle is sized to one session — a day or two of CC work, at most a handful of compactions; a cycle that would run far past that is two cycles. When the owner overrides caution, the planner states the trade-off once and writes the plan the owner's way.

---

## 5. CYCLE-LOG.md — format

Append-only. One entry per line-block, written *during* the work, never reconstructed afterwards:

```
[YYYY-MM-DD HH:MM] KIND — text (cycle NN rN)
```

| Kind | Meaning |
|---|---|
| START | Cycle/step begun; plan revision echoed |
| ADVICE | Guidance from the **owner** in chat, quoted before it is acted on |
| ADVISOR | Consultation of the **advisor tool**: question, **the model that answered**, one quoted line of what it flagged, adopted or rejected, what changed |
| DECISION | A choice CC made itself, with reason |
| DEVIATION | Departure from the plan, with reason and the narrow re-check performed |
| BLOCKER | Cannot proceed; what is needed |
| VERIFICATION | A check and its literal result — hashes, counts, quoted output in this entry, never "in the next one" |
| REVIEW | The fresh-subagent verdict list for a PR (see §9) |
| HANDOFF | Session closed at cycle END: context size at close, compactions this session, and confirmation that PLAN/STATUS/CYCLE-LOG carry what is done, what is next and what was ruled out (§15) |
| NOTE | Anything else worth a record, including "update found no new work" |
| END | Step/cycle closed: test count, commit hashes, **advisor summary** ("consulted N times, N confirmed: …" — "0 times" written explicitly) |

ADVICE and ADVISOR are different things and never share an entry. If the advisor tool times out, or the responding model cannot be named, that is logged as a tool failure, not as advice and not as a consultation.

---

## 6. STATUS.md — shape and rules

Eight sections, always in this order:

```
# STATUS — cycle NN rN — written YYYY-MM-DD HH:MM
Tests: <count> (was <count>) · Advisor: consulted N times (N confirmed) · Review: <verdict or n/a> · Context: <n>k at write

§1 Git state — HEAD, branch, what is unpushed, open PRs, whether main is current
§2 Step map — every PLAN.md step with [done] / [in progress] / [blocked] / [not started]
§3 What changed this run — with evidence (cite log entry, report, file:line)
§4 Decisions — made by whom: owner ruling / advisor / CC's own judgment, never silent
§5 Why stopping — gate reached, halt, blocker, limit, context handoff, or "plan complete"
§6 Mismatches — PLAN.md vs repo vs reality, disclosed, with what CC did about it
§7 Next step and owner actions — numbered "Owner action N:" lines (§7 rules below)
§8 Programme board — only while a programme is active (§12); otherwise "none"
```

Rules:

- Written at the end of **every** run, no matter what: success, failure, halt, error, a bare `update` with nothing new, a cycle handoff, an interrupted turn. When in doubt, write it first. A run that ends without it is a protocol breach; the next run's first act is to write the missing one.
- A halt is expressed by writing STATUS.md, not by a chat message. "Task complete" without a STATUS.md write is not complete.
- Every step boundary, every push, every halt gets a rewrite, however small the work.
- The `Context:` figure is read from `/context` at the moment of writing. It is a fact for the owner to watch across cycles, never an estimate of cost.
- After every write: commit and push STATUS.md, CYCLE-LOG.md and any report to `main` (docs-only commits are the one exception to PR-first — see §8) so the owner and the planner read the current state there.

---

## 7. Owner actions and questions

CC never asks the owner anything in chat and never expects the planner to relay. Everything the owner must do — a GO, a credential to paste into the hosting panel, a script to run, a DNS record, a decision, a session clear — is a numbered **"Owner action N:"** line in STATUS.md §7 with the exact thing to do, ending *"reply `update` when done"*.

- **Every question carries a recommendation.** Option 1 is labelled "(Recommended)" with a one-line reason, and the reason is logged. Bare options are not acceptable. Better still: decide with the advisor, log a DECISION, continue — ask only at a genuine stop condition or before an irreversible action outside the plan. The owner is not a judgment-outsourcing service.
- **Evidence before asking.** Before restating an owner action, check whether it already happened — a key name in `.env`, a file on the server, a log line, a running service — and proceed on that evidence. Console output the owner pastes is welcome, never required. Anything the owner runs on a server logs its own output to a file on that box.
- One question per Owner action line.
- The planner asks the owner questions in chat one at a time, recommended option first, and writes each answer into PLAN.md as a ruling; the owner never answers a bundled list.

---

## 8. Authorisation model

- **Local commit ≠ push.** Pushing a *feature branch* is allowed once the suite is green with the literal count stated and `git log origin/main..HEAD` is quoted — CI and review need it. Pushing to `main` happens only by merging under the plan's merge rule or an owner GO.
- **Docs-only exception:** PLAN.md revisions, STATUS.md, CYCLE-LOG.md, reports, DECISIONS.md (when authorised) and the post-merge record commit directly to `main` and push immediately. That is the channel; it must never wait on a code gate.
- **Docs-only commits carry `[skip ci]` in the commit message.** They cannot affect a merge rule — they land after a PR is already green — and they are roughly half of all pushes, so running CI on them buys nothing. Code commits, feature-branch pushes and anything touching config, routes, migrations or tests **never** carry it. If a commit mixes docs and code, it is not a docs-only commit and the marker is omitted.
- **Code held behind a GO stays off main.** When the plan says "halt for the push/merge GO", the code is pushed as `hold/<step>`, STATUS.md is committed on `main`'s tip and pushed alone. When the GO arrives, rebase, merge, delete the hold branch. A GO can be given in advance inside the plan as a ruling; then no halt is needed.
- **Named authorisation, one per action:** merge to `main`, deploy, remote migration, creating real identities, sending real email or messages to real people, flipping any live-money switch (billing, payments, payouts), deleting production data (ask-first even after a ruling). Each is given in the plan or by the owner in chat, quoted in the log before it is acted on. Advice that widens scope needs the owner to say so explicitly. Nobody self-authorises a gate the plan states.
- **Servers are an allow-list**, kept in `CLAUDE.local.md`: read-only commands run freely; state-changing commands need a per-command "yes"; the never-list is absolute — displaying or copying secrets, `.env`, tokens, recovery codes, TOTP seeds; interactive shells; seeding or resetting production; rollbacks; raw SQL on production; deletes; sudo; key changes; `composer setup` or any destructive reset. Root is reached only through a recipe/script the owner runs himself; a failed recipe is fixed in the repo, pushed, and re-requested via §7. Never ask for a sudo password or an API token.
- **Secrets never appear anywhere** — not in chat, reports, logs, fixtures, seeders, commits or STATUS.md. Credentials live only in the hosting environment, pasted there by the owner. If one is pasted by mistake it is redacted immediately and treated as a closed incident. No real name, email or phone number in any artefact either.
- When relaying becomes a tax, the owner prefers to **extend CC's access under an explicit written allow-list** and record it as an ADR, rather than keep copying.

---

## 9. Quality rules that have earned their place

- **Independent review before merge.** Every PR is reviewed by a fresh subagent with no prior context, producing a numbered verdict list logged as a REVIEW entry: PASS / PASS WITH NOTE / FAIL with severity (Low, Medium, High), file, line and the failing scenario. Reviews are adversarial — try to break authorisation, tenant isolation, money paths, races, secret leakage; run the tests yourself; do not trust the PR description.
- **Severity decides the stop.** Low, documentation-only findings are fixed in-cycle (DEVIATION entry, narrow re-check, continue). Medium or above, or anything touching code, config, routes, migrations or tests, stops the cycle and returns to the owner — unless a programme's merge rule explicitly covers it.
- **Fix-loop cap:** at most two fix-and-re-review rounds per PR; then stop and report.
- **Cite before you claim.** Any line asserting what a command prints, a screen shows, or a route does names the file and line, or quotes the exact output, where it was verified. Reviewers check citations, not plausibility.
- **Read back after every write.** A file reported as written is not written until it has been read back from disk and diffed. This applies to the planner's PLAN.md and to every file CC touches.
- **Results go in the entry that reports them.** Hashes, counts, outputs — never deferred to a later entry unless that entry is written before stopping.
- **Suite never shrinks.** Every code change adds tests; the count is tracked in STATUS.md's header line.
- **Post-merge record on `main` directly**, so the next branch cut from `main` never starts stale.
- **Stale plan text is disclosed, not obeyed.** When PLAN.md describes something the repo shows is already done or different, CC records it in STATUS.md §6 and corrects the report; it neither redoes finished work nor silently does something else.
- **Own mistakes plainly.** When a session finds one of its own earlier claims was unverified, it says so in the log — "that was my bug" — and does not present the correction as if the claim had merely become outdated.

---

## 10. The advisor

- Every PLAN.md states a **minimum number of consultations** — default two: on design before the first edit, and before opening the PR.
- **Mandatory triggers** regardless of the minimum: touching an auth or tenancy boundary; touching money, ledger or payout code; any conflict between the plan and the repo; touching a frozen file (§11); before any production-affecting judgment call.
- Each consultation is its own ADVISOR entry: question, **the model that answered**, one quoted line of what it flagged, adopted or rejected, what changed.
- **Proof, not count.** An ADVISOR entry that cannot name the responding model is logged as a tool failure, not a consultation, and does not count toward the minimum. A cycle whose END claims the minimum was met but whose entries name no models is a protocol breach, disclosed in STATUS.md §6 by the next session that notices.
- **Verify the tool once per cycle.** On the first consultation of a cycle, CC confirms the advisor actually responded — not that it is configured, that it answered — and logs the model name. A configured advisor that never fires is the failure this rule exists to catch.
- Every END entry and every STATUS.md header carries the advisor summary as consulted-and-confirmed counts; "consulted 0 times" is written explicitly.
- A timeout is logged as a tool failure, never as advice.
- Every "where are we?" brief includes an advisor section. The owner judges CC partly by how it used — or ignored — that advice, and partly by whether the entries prove it was used at all.

---

## 11. Production and servers

- **Rehearse everything.** Production is touched only when the plan says so, and the plan says so only after the same change has run on a throwaway rehearsal server. Every change to a server script runs once on rehearsal first, however small.
- **Rollback open before cutover.** Prepared, pasted and open before any production change. Any failed check → rollback at once, no second attempt the same day.
- **Fresh connection per check;** verbose inspection commands (`config:show`, not commands that strip detail). A stale shell once showed an old release.
- **Frozen files.** `CLAUDE.md` names files that change only under the full protocol: build, prove, halt, GO, with a plant-a-failure / see-it-red / revert / see-it-green proof for every change. Typical candidates: ledger and state-machine services once money flows, offline-sync paths, auth boundaries.
- **Live-money switches stay off** until the owner flips them in writing. Deleting production data is ask-first, always.

---

## 12. Autonomous programmes (opt-in)

Off by default. Once the loop is trusted, the owner may authorise a **programme** in PLAN.md: an ordered list of sub-cycles, each branch → PR → fresh review → fix loop (cap 2) → merge → post-merge record → read-only smoke check, with:

- a **merge rule** stating exactly when CC may merge without the owner: review APPROVED with no open Medium+, CI green, no migration, no change to the named list of core files, diff limited to the expected files — otherwise leave the PR open, mark it awaiting the owner in §7, move to the next independent item;
- **stop conditions:** smoke failure, unfixable regression, anything needing a remote migration, any High security finding, or the owner typing `stop`;
- a **resume hook** so the window continues between sub-cycles, with a hard cap on automatic resumes stated in the plan;
- the **programme board** in STATUS.md §8 so "where are we?" is answerable at any moment;
- a review with the owner after ~24 hours or when the board says the programme is done.

A programme runs unattended, and nobody is there to clear. On a 200k window that is fine: auto-compaction carries it between sub-cycles, and STATUS.md is rewritten at every sub-cycle boundary so nothing a compaction drops is lost. Two things are stop conditions: a compaction that fails, and the automatic-resume cap in the plan being reached. When the programme finishes or stops, it raises the clear action like any cycle END.

---

## 13. Briefing style

Short. Plain language first, technical detail after. Prose, not bullet soup. State first, evidence second, then how CC performed (honestly, including its confessions), then advisor input, then a clear recommendation with the reasoning, the trade-off, and what *not* to do. Do not quote the owner's messages back at him. Do not remind him of the process — enforce it through the files. Flag a contradiction before acting on it. Once he has decided, do not re-litigate: state the trade-off once, then write the plan his way. Say "that was my bug" rather than a euphemism. If he asks a one-word question, answer with what matters: what happened, what you think, what he does next.

---

## 14. Things that went wrong once and are now rules

- A rewrite was reported as written but the disk kept old content → **read back and diff after every write**.
- `update` found nothing to do and stayed silent → **NOTE entry and STATUS.md on every no-op**.
- CC asked the owner for things in chat and the planner started relaying → **owner actions live in STATUS.md §7 and nowhere else**.
- CC worked in a worktree while the planner read the main checkout; the two drifted → **one window per checkout, no worktrees**.
- The loop depended on pasted console output and four `update`s went by with nothing attached → **evidence checked on the servers; scripts log their own output**.
- The word "advisor" was read as meaning the owner → **ADVICE = owner, ADVISOR = tool, separate entries**.
- A cycle ran with no advisor entries → **minimum consultations in every plan**.
- The advisor was configured and the command confirmed it, but across 54,201 logged messages it answered 25 times; the minimum had been met on paper by entries that named nothing → **every ADVISOR entry names the responding model, or it is a tool failure** (§10).
- A docs cycle blocked on a one-line inaccuracy → **Low doc-only findings fixed in-cycle**.
- A guide promised a feature the code did not have → **cite before you claim**.
- Three branches in a row started without the previous cycle's closing record → **post-merge record on `main`**.
- A merge prompt offered two options with no recommendation → **every question carries a recommendation**.
- A tool offered to spin a finding into a separate window → **declined; everything goes through the plan or the record splits**.
- A plan revision described a bug as open that was already fixed; CC disclosed and corrected instead of redoing → **that is the right behaviour; now a rule**.
- A guard script had never run against a real box until the rehearsal rule forced it; it failed → **rehearse everything**.
- A stale server shell showed an old release → **fresh connection per check**.
- Sessions were left open for weeks with the 1M context window enabled; auto-compaction sat at a 935–970k ceiling, cache re-reads reached 82% of all spend, and one 59-day session cost more than the rest of the month combined → **200k only, one cycle per session, clear at END** (§15).
- A planning conversation carried every cycle of a project for months, on the stronger model, until it began compacting mid-brief → **one planning conversation per cycle, retired at the cycle boundary** (§15).
- Docs-only commits to `main` triggered a full CI run each time, about half of all runs, on work that could not affect a merge rule → **`[skip ci]` on docs-only commits** (§8).
- A command that prompts, or hangs, stalled a whole run → **every artisan, composer, npm and gh call carries its non-interactive flag, and a command still running after ten minutes is stopped, logged as a BLOCKER and worked around** (TrusTutor R16).
- CC launched Herd from its own shell and tied Herd's console to it, then chased the crashes that caused → **Herd and its services are started and stopped only by the owner; a service that is down is a halt with an owner action** (TrusTutor R18).
- Browser automation prompts per action on local sites and cannot hold a standing permission, so an unattended run would stall → **no browser automation by CC; pages are checked with `curl`, behaviour is proven by feature tests** (TrusTutor R20).
- Three times a form or step that could be revisited left stale downstream state (a rate after subjects, twice; documents after a rejection) → **the design consult lists what each revisitable step invalidates and how it is re-derived, with a test per dependency** (TrusTutor R30).
- A path on a zero-downtime Forge site was written without `/current` and every artisan call failed with "Could not open input file: artisan" → **the release-directory shape is recorded in `CLAUDE.local.md`, not rediscovered** (TrusTutor R41).
- A setting was verified on a page that did not read it (the Filament panel title reads `APP_NAME`, not the `site_name` setting) → **verify a value on the surface that renders it, and name that surface in the ruling** (TrusTutor R48).
- Console work in Forge, the admin panel, GitHub settings and DNS was reachable by neither seat and silently fell to the owner → **the planner performs owner-console work in the owner's browser and records each one as a numbered ruling; it never signs in on his behalf** (TrusTutor R45).

_The seven lines above are TrusTutor's own additions to §14; the rest of this file is v1.2 unchanged._

---

## 15. Cost and context discipline

Context is re-read on every turn, so a session's cost is set by how large it has grown, not by how many sessions exist. A long-lived session pays for its entire history on every message. This section exists because that went unnoticed for two months and cost more than the work was worth.

**The window setting.** 200k context only. `CLAUDE_CODE_DISABLE_1M_CONTEXT: "1"` in the `env` block of user settings. The 1M window is never enabled for project work; if a task genuinely needs it, that is an owner decision recorded as a ruling in PLAN.md, for that cycle only.

**One cycle, one session.**

- A session covers one cycle — one PLAN.md revision from START to END. Never a project, never a month. Within the cycle CC runs through every step and every gate without asking for a clear.
- When the cycle reaches END, CC writes STATUS.md, logs a HANDOFF entry, and raises **"Owner action N: `/clear` this session, then reply `update`."** That is the only point at which it asks.
- STATUS.md §5 states **context handoff** as the stopping reason.
- The owner clears and types `update` for the next cycle. The fresh session reads PLAN.md, STATUS.md and the recent CYCLE-LOG as it does on any `update`.
- **CC raises the clear action only when the files carry the state** — what is done, what is next, what was tried and rejected. If they do not, it writes that first and raises the action afterwards.
- CC cannot clear itself. The **clear action** — the numbered §7 line reading *"`/clear` this session, then reply `update`"* — is an owner action like any other, and no rule pretends otherwise.
- A cycle that halts mid-way for an owner GO does **not** trigger a clear. The session waits; the owner's `update` after the GO resumes it.

**Auto-compaction inside a cycle is normal.** On a 200k window it fires around 180k, summarises, and the session carries on. That is the mechanism that lets a cycle run for hours unattended, and it is expected to fire a few times per cycle. What makes it safe is the existing rule that STATUS.md is rewritten at every step boundary, push and halt — the files hold the state, so a lossy summary loses nothing that matters. CC never asks the owner to clear because compaction is approaching. A cycle that has compacted more than a handful of times is a signal to the planner that the cycle was too large, not a reason to stop.

**Manual `/compact` is rarely needed.** Auto-compaction handles the in-cycle case. Compacting a session at cycle END is pure waste — it is about to be cleared — and compacting several open windows in one batch buys nothing.

**Sessions are free.** Cost comes from context size per turn, not session count. CC never consolidates work into a long-lived session to "save" anything, and never treats starting a fresh session as a cost. A session with no activity for 14 days is never resumed — its state is in the channel files. Clearing it out of the projects folder is housekeeping the owner does; CC neither archives nor deletes session logs.

**Keep the per-turn floor low.** MCP servers, plugins and skills not needed for the current cycle stay off — their schemas are paid on every message of every session. Simple subagents (file search, reading, grep) run on the cheapest capable model; the advisor and the review subagent are exempt and always use the stronger one.

**The planning seat has the same disease.** A Cowork or Chat conversation re-sends its whole history on every turn exactly as a CC session does, and each "where are we?" pulls STATUS.md, CYCLE-LOG, PLAN.md and Git state into it permanently. A planning conversation running for months costs more per message every week, and it typically runs on the stronger, dearer model — so it is the more expensive seat to leave open, not the cheaper one.

- **One planning conversation per cycle.** Started fresh from the Project when the cycle opens, retired when the plan it produced has been executed and the cycle is closed. The next cycle starts a new one.
- **The Project persists, the conversation does not.** Connected repo folder, instructions, attached knowledge and capacity all survive; only the chat history resets. That is the whole point — the planner is instructed to read the channel files at the start of every task, so it was never meant to work from conversation memory.
- **One active planning conversation per project.** Never two on the same repo: that is the same drift failure as two CC windows. The retired conversation stays readable in the Project, simply not typed into.
- **Write it down before retiring it.** Anything argued in the conversation that matters — decisions, rejected options, the reasoning behind a ruling — goes into `PROJECT_BRIEF.md` or `DECISIONS.md` first. If retiring a conversation feels like a loss, that is the signal something was never written down.
- **There is no `/clear`, `/context` or size indicator in Cowork or Chat.** Compaction starting is the only warning, and by then the conversation is already expensive. Retire on the cycle boundary rather than waiting for that signal.
- **Match the model to the work.** The stronger model earns its rate when architecture is genuinely being argued; routine "where are we?" briefs and plan writing do not need it.

**CI minutes.** Docs-only commits carry `[skip ci]` (§8). Workflows use path filters so documentation pushes do not trigger builds, a concurrency group with cancel-in-progress so a chain of pushes only finishes the newest, and dependency and build caching. These live in the repo's workflow file and are proposed through PLAN.md like any other change.

**No self-reported spend.** CC does not estimate, calculate or log its own token cost. It cannot see it reliably, and an unverifiable number in the record is worse than none. The `Context:` figure in STATUS.md is a measured fact and is the only thing reported.

---

## 16. Adapting this to a new project — day-one kit

Present these files together to both seats before the first "where are we?":

1. `docs/HOW-WE-WORK.md` — this file, unchanged.
2. `docs/PROJECT_BRIEF.md` — what and why, non-goals, roadmap.
3. `docs/PRD.md` (+ data model, checkpoints) — what to build.
4. `CLAUDE.md` — stack, invariants, conventions, checkpoint protocol, frozen-files list (may start empty), and the Owner-loop block from Appendix B with the path filled in.
5. `CLAUDE.local.md` — git-ignored; server allow-list and never-list (may start as "no servers yet").
6. `docs/PLAN.md` — cycle 01 r1, written by the planner on the first "go ahead".
7. `docs/STATUS.md` — the eight-section shape, empty, cycle 00.
8. `docs/CYCLE-LOG.md` — empty, with the entry format at the top.
9. `docs/DECISIONS.md` — empty ADR list.

Before the first `update`, confirm the environment once: `CLAUDE_CODE_DISABLE_1M_CONTEXT` is set in user settings, and `/context` reports a 200k window.

Then paste Appendix A into the planning project's instructions. Only the project name, repo path, branch naming and the two lists (frozen files, allow-list) change from project to project.

---

## Appendix A — paste-ready: planning-seat project instructions

```
This is the strategy and planning project for <project> (<repo path>). Claude Code does all
implementation in the same folder. You do planning only. Read docs/HOW-WE-WORK.md first; it is
binding.

AT THE START OF EVERY TASK, read these if they exist, without being asked:
- docs/PROJECT_BRIEF.md, docs/PLAN.md, docs/STATUS.md, docs/CYCLE-LOG.md (recent entries),
  docs/DECISIONS.md, CLAUDE.md — and the Git state of the repo.
These files are the source of truth. Never ask the owner to paste Claude Code output.

HOW WE WORK:
- Discuss freely. Ask clarifying questions before proposing if anything is ambiguous. Give
  options with pros and cons and one recommendation with a one-line reason. Ask questions one
  at a time, each with its recommended option first and a one-line reason, take each answer
  immediately, and write the answers into PLAN.md as rulings.
- On "go ahead" (or "go", "approved", "write the plan"): write the agreed plan to docs/PLAN.md
  in the HOW-WE-WORK §4 format, under 100 lines, overwriting the previous revision; read it back
  from disk and diff it; then reply with one line — "PLAN.md written (cycle NN rN) — tell Claude
  Code: update" — and a short plain-language summary of what the plan does and anything the
  owner will be asked to do.
- Size a cycle to one CC session — a day or two of work, a handful of compactions at most. A
  cycle that would run far past that is two cycles. The standing line states: one cycle per
  session, cleared at END. If STATUS.md shows a cycle compacted many times, say so in the brief;
  the next plan is smaller.
- This conversation lasts one cycle. When the cycle's plan has been executed and closed, say so
  and recommend the owner start a fresh conversation in this Project for the next cycle. Before
  that, write anything argued here that is not yet in a file — decisions, rejected options, the
  reasoning behind a ruling — into docs/PROJECT_BRIEF.md (on "refresh the brief") or propose it
  for docs/DECISIONS.md. Never assume the next conversation remembers this one; it reads the
  channel files and the repo, as you do at the start of every task.
- On "where are we?": read STATUS.md, the recent CYCLE-LOG entries and any reports they name;
  brief in prose — state, evidence, how CC performed including its confessions, what the advisor
  said, which model answered and whether it was adopted, recommendation with trade-off, what not
  to do. Flag any cycle whose advisor entries name no model.
- On "refresh the brief": update docs/PROJECT_BRIEF.md with decisions made since, under 200 lines.
- If STATUS.md contradicts PLAN.md or the brief, say so first; assume nothing is done unless
  STATUS.md says so.
- Every plan states the advisor minimum, the authorisation boundary, numbered rulings, and named
  halts only where the owner must act. Once the owner has decided, state the trade-off once and
  write it his way.
- Never relay instructions to Claude Code through the owner. Anything Claude Code needs from him
  arrives via STATUS.md §7.

HARD RULES:
- Never run git or any shell command in the repo. Claude Code owns git.
- Read-only everywhere except docs/PLAN.md, and docs/PROJECT_BRIEF.md on explicit request.
- Never edit code, migrations, config, or any other file.
- No real name, email, phone, key or secret in anything you write.
```

## Appendix B — paste-ready: `### Owner loop` section for the repository's `CLAUDE.md`

```
### Owner loop (standing protocol — no exceptions; full text in docs/HOW-WE-WORK.md)
The owner, the planning seat (a separate Claude session that writes only docs/PLAN.md) and
Claude Code work in one fixed loop: owner says "go ahead" → planner writes docs/PLAN.md into the
checkout at <repo path>, uncommitted → owner tells Claude Code `update` → Claude Code works,
logs, writes docs/STATUS.md → owner asks the planner "where are we?" → repeat.
1. On every `update`: git fetch; fast-forward main; read docs/PLAN.md; if newer than the
   committed one, commit it as "PLAN.md cycle NN rN" before any other work; refuse a revision
   that already has an END in docs/CYCLE-LOG.md; execute from the first unfinished step. No new
   work → NOTE entry + STATUS.md, never silence.
2. One window per checkout, one `update` at a time, no worktrees. A window runs many sessions in
   sequence (rule 13); that is required, not an exception. Independence comes from a fresh
   subagent with no prior context, never a second window.
3. Log while working in docs/CYCLE-LOG.md using the kinds START, ADVICE (owner), ADVISOR (tool),
   DECISION, DEVIATION, BLOCKER, VERIFICATION, REVIEW, HANDOFF, NOTE, END. Results, hashes and
   counts go in the entry that reports them. END carries the advisor summary; "0 times" is written.
4. Never ask the owner for anything in chat. Every GO, credential, script run, session clear or
   decision is a numbered "Owner action N:" line in STATUS.md §7 with the exact thing to do,
   ending "reply `update` when done". Every question carries "(Recommended)" on option 1 with a
   one-line reason. Check the evidence before restating an action.
5. STATUS.md has the fixed eight-section shape (§1 Git state, §2 Step map, §3 What changed,
   §4 Decisions and by whom, §5 Why stopping, §6 Mismatches, §7 Next step / Owner actions,
   §8 Programme board) and a header carrying tests, advisor counts, review verdict and the
   context size at write. Rewritten at every step boundary, push and halt, and at the end of every
   run no matter what — when in doubt, STATUS.md first. A halt IS a STATUS.md write.
6. Docs-only commits (PLAN revision, STATUS, CYCLE-LOG, reports, DECISIONS when authorised,
   post-merge record) go to main and push immediately, with `[skip ci]` in the message. Code goes
   on a branch per cycle → PR → fresh-subagent adversarial review with a numbered PASS / PASS WITH
   NOTE / FAIL(severity, file, line, scenario) list logged as REVIEW → fix loop (cap 2) → merge
   only under the plan's merge rule or an owner GO. Low doc-only findings fixed in-cycle; Medium+
   or anything in code/config/routes/migrations/tests stops and returns to the owner. Code commits
   and PR branches never carry `[skip ci]`.
7. Before every feature-branch push: full suite green with the literal count; `git log
   origin/main..HEAD` quoted. Suite never shrinks. Code held behind a GO is pushed as
   hold/<step>, never to main. Post-merge record lands on main directly.
8. Named authorisation, one per action, quoted in the log before acting: merge to main, deploy,
   remote migration, real identities, real email/messages, any live-money switch, deleting
   production data. Nobody self-authorises a gate the plan states. Owner advice in chat is
   logged as ADVICE before it is acted on; scope-widening advice needs an explicit "yes".
9. Servers follow the allow-list in CLAUDE.local.md: read-only free; state-changing per-command
   "yes"; the never-list (secrets, .env, tokens, interactive shells, prod seeding/reset,
   rollbacks, raw SQL on prod, deletes, sudo, key changes, composer setup) is absolute. Root only
   via a recipe the owner runs; never ask for a sudo password or API token. No real name, email,
   phone, key, secret or webhook payload in any report, log, fixture, seeder, commit or STATUS.
10. Every server-script change runs on the rehearsal server before production; rollback open
    before any cutover; failed check → rollback, no second attempt the same day. Frozen files
    (listed in CLAUDE.md) change only under build → prove → halt → GO with a red/green proof.
11. Consult the advisor, and log an ADVISOR entry, at least the minimum the plan states and
    always when touching auth/tenancy, money/ledger/payout code, a frozen file, a plan–repo
    conflict, or before a production-affecting judgment call. Every ADVISOR entry names the model
    that answered and quotes one line of what came back; an entry that cannot name the model is a
    tool failure, not a consultation, and does not count toward the minimum. Confirm on the first
    consultation of each cycle that the advisor actually answered. A timeout is a tool failure,
    not advice. Stale plan text → disclose in STATUS.md §6 and correct the report; never silently
    redo finished work, never silently do something else.
12. Read back and diff every file after writing it. Cite file:line or quoted output for every
    claim about behaviour. Own mistakes in plain words.
13. One cycle per session. Run every step and gate of the cycle without asking for a clear. Only
    at the cycle's END: write STATUS.md with §5 "context handoff", log a HANDOFF entry with the
    context size and the number of compactions this session, and raise "Owner action N: `/clear`
    this session, then reply `update`". Raise it only when PLAN.md, STATUS.md and CYCLE-LOG carry
    what is done, what is next and what was ruled out — if they do not, write that first. A halt
    for an owner GO is not a clear; wait, and resume on the next `update`. You cannot clear
    yourself; the owner does it.
14. 200k context only; the 1M window is never enabled except by an owner ruling for one cycle.
    Auto-compaction inside a cycle is normal and expected — it fires around 180k, you carry on.
    Never ask for a clear because compaction is approaching, and never stop work because it fired.
    STATUS.md at every step boundary is what makes compaction safe; keep that rule. Manual /compact
    is rarely needed and never at cycle END. A programme runs across compactions the same way and
    stops only on a failed compaction or its resume cap.
    Sessions are free — never consolidate work into a long-lived session to save cost, and never
    treat a fresh session as a cost. MCP servers, plugins and skills not needed this cycle stay
    off. Simple subagents (search, read, grep) run on the cheapest capable model; advisor and
    review subagents use the stronger one. Never estimate or log your own token spend; report only
    the measured context size.
```
