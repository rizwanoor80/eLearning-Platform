# STATUS — cycle 08 r3 (programme "CP7") — **RUNNING**, step 3 done and merged, starting step 4 (8c) — written 2026-09-27 17:16 (machine clock) — Context: not measured by the tool — no Owner action needed right now

Tests: full suite on `main` at `c665eef` (post-8b-merge): **`php artisan test --parallel` 1783/1783 (9098 assertions)**, no shrink from the pre-merge branch count. `MessageMaskerTest` 170/170 (181 assertions) included in that total. `ledger:verify --no-interaction` "Ledger OK: every lesson sums to zero."; `npm run build` exit 0; Pint pass; RTL grep clean of anything 8b touched (pre-existing vendor-primitive matches only, unchanged by this merge, not chased). PR #31 merged via `gh pr merge 31 --merge` (matching PRs #27–30's strategy), merge commit `c665eef`, CI green on the head (`b5cd980`) before merging. Round-5 scoped review (R144(d)) found one Medium (confirms, not introduces, the already-disclosed gate-open catastrophic-backtracking gap) — disclosed, not fixed, no halt, per R144(d)'s pre-ruled outcome. Advisor total for 8b across the whole cycle: 11 counted consultations + 1 correctly-uncounted timeout. Resume count 0 of 8.

## §1 Git state
`main` = `dba20c1` (this run's docs-only commits: PLAN.md cycle 08 r3, the R144 VERIFICATION/DEVIATION/ADVISOR/REVIEW/DECISION CYCLE-LOG entries, ADR-019 amendment, CHECKPOINTS CP8 hardening line, the 8b-merge VERIFICATION/DECISION entries, this file) — pushed with `[skip ci]`. Branch `cp/8b-messaging` at `b5cd980`, merged into `main` (`c665eef`, PR #31 closed/merged), kept per the "never delete branches other than a merged `hold/<step>`" rule (this is a merged feature branch, not a `hold/<step>`, so it stays — matches how #27–30's branches were left). `rehearsal` = `042f7c0` (unchanged this cycle; step 7 deploys the final `main` under R111). Branch `cp/8a-parallel-suite` merged, kept, unchanged this run.

## §2 Step map (cycle 08 r3)
1. Docs-only commit of PLAN cycle 08 r3 (R144 only) — **done** (`9c68d11`).
2. `cp/8a-parallel-suite` (R128) — **done**: PR #30 merged `e4c762d` (cycle 08 r1).
3. `cp/8b-messaging` (R133–R135, R140, R143, R144) — **done**: R144 fix in (`d8d214b`, `bac9e82`), finding-1 fixtures and timing test green (170/170), round-5 scoped review run and its one Medium disclosed (ADR-019, `docs/reports/8b.md`, CP8 hardening checklist), merged under R141 as amended by R144 (`c665eef`), CP7 boxes 1 and 2 ticked.
4. `cp/8c-reviews` (R136) — **starting now, this run.** Not yet branched. Own advisor consultation (review eligibility and aggregate recompute) owed before the first edit, per the PLAN's standing line.
5. `cp/8d-safeguarding` (R137, R138) — not started.
6. `cp/8e-notifications` (R139, `docs/reports/8e.md`) — not started.
7. Deploy rehearsal under R111, then END — not started.

## §3 What changed this run
- **8b R144 fix (on the branch, now merged):** see the prior halt-STATUS and this cycle's CYCLE-LOG for the full build detail (uncapped junk-gap patterns per R144(a); `hasCandidate()` presence-gate mechanism for R144(b); 16 new R144(c) fixtures + 4 timing-dataset cases + 1 fail-closed case, 170/170 total; docblock correction for the `[a-z]{2}` vs `[\p{L}]{2}` mismatch and the overclaimed "linear in practice" line).
- **8b docs closure (this run, before the merge):** `docs/reports/8b.md` round-4/5 fixture table, known-limits disclosure and review-history rows (`b5cd980`); `docs/DECISIONS.md` ADR-019 amended a third time (`00d22a2`); `docs/CHECKPOINTS.md` CP8 hardening bullet for the gate-open backtracking gap (`e7454ea`); `docs/CYCLE-LOG.md` REVIEW entry (round-5's 5 numbered verdicts) and DECISION entry formalising no-halt (`cdbbb77`).
- **8b merge (this run):** PR #31 merged (`c665eef`) once `mcp__ccd_pr__get_status` showed CI green and `mergeStateStatus: CLEAN`; post-merge full-suite/ledger/build/Pint/RTL proof taken on `main` at the merge commit; CYCLE-LOG VERIFICATION and DECISION entries recorded (`dba20c1`).
- **8a:** unchanged this run; see cycle 08 r1 STATUS for build detail.

## §4 Decisions and by whom
- CC (8b merge, this run): self-merge taken directly under R144(d)'s pre-ruling once CI showed green (no separate owner GO sought — none is required by the plan for this step, and there is no user in this autonomous run to ask); merge strategy (`--merge`, not squash/rebase) matched PRs #27–30 by checking `main`'s merge-commit history first rather than assumed; `mcp__ccd_pr__set_monitor`'s `auto_merge` switch was not used (left `false`) — the merge was performed directly via `gh pr merge`, since enabling auto-merge is reserved for an explicit user ask, which did not occur.
- CC (8b R144 process, this cycle): the round-5 review's one Medium (gate-open catastrophic backtracking, confirmed not newly introduced) triggers no halt condition under R144(d)'s pre-ruled outcome — disclosed in four places rather than fixed; no third structural redesign attempted this cycle (the advisor's own direction, after two reverted attempts already logged as DEVIATIONs). See cycle 08 r3 CYCLE-LOG for the full advisor/deviation/review chain.
- Earlier cycle decisions (7b–8a, R104–R143 process decisions): unchanged, see the prior halt-STATUS (2026-09-27, cycle 08 r2) and CYCLE-LOG for full detail — not restated here to keep this file to the current run's boundary.

## §5 Why stopping
Not stopping. Step 3 is done and merged; per PLAN step 3's "halt: no" and R144(d), the programme runs on to step 4 (`cp/8c-reviews`, R136) in the same run. This STATUS write is a step-boundary write (rule 5: "rewritten at every step boundary"), not a halt.

## §6 Mismatches
Carried unchanged from the prior halt-STATUS (2026-09-27, cycle 08 r2), items A–I, plus:
- **J (new, disclosed, not blocking):** the round-5 scoped review's one Medium — `MessageMasker`'s bare-domain patterns still exhibit catastrophic backtracking once a gate opens and the matched text contains a run of `-` characters that cannot resolve to a valid ending (e.g. a dash-joined reference code); fails closed (throws, message not stored) rather than unmasking; not a regression (the same shape threw before R144 at roughly the same threshold); routed to the CP8 hardening checklist (`docs/CHECKPOINTS.md`) with the fix direction (cap label-run iterations, remove the `-` overlap between LABEL and JUNK).

## §7 Next step / Owner actions
No Owner action needed right now. Next step (this same run, no halt): branch `cp/8c-reviews` from `main` at `dba20c1`, take the review-eligibility/aggregate-recompute advisor consultation before the first edit, build R136 (one review per lesson on an eligible `completed` lesson, `rating_avg`/`rating_count` recomputed in-transaction, profile display, admin unpublish/republish with audit, `features.reviews` gate), open PR, run the R141 fresh-subagent review, fix loop (cap 2), self-merge under R141, tick CP7 box 3, continue to step 5.

## §8 Programme board (CP7, cycle 08)
| Step | Sub-cycle | Ruling(s) | State |
|---|---|---|---|
| 1 | docs (PLAN + PRD) | R127 | done |
| 2 | 8a parallel suite | R128 | done, merged `e4c762d` |
| 3 | 8b messaging | R133–R135, R140, R143, R144 | done, merged `c665eef` |
| 4 | 8c reviews | R136 | starting |
| 5 | 8d safeguarding | R137, R138 | not started |
| 6 | 8e notifications | R139 | not started |
| 7 | deploy + END | R111 | not started |
