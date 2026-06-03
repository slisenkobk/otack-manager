# Handoff prompt — execute TODO #9 waves 9.1a → 9.1b → 9.1c

Copy-paste the block below into a fresh Claude Code session. It's self-contained — no prior session memory required.

---

```
You are executing TODO #9 (project audit cleanup) for the otack-tasks repository
at /Users/slisenkobogdan/Work/AINeoLab/internal/otack/otack-tasks.

## Context you need (read first, then proceed)

The project is a self-hosted PHP/SQLite+MySQL task manager. Latest tag is v1.1.0
(External REST API). A comprehensive 2026-06-03 audit identified 73 findings
across backend/frontend/tests/docs/ops. Three implementation plans exist:

- docs/superpowers/specs/2026-06-03-todo-9-audit-and-cleanup-plan.md — audit + tier triage
- docs/superpowers/plans/2026-06-03-todo-9-1a-implementation.md — Wave A (17 tasks, ~24h, ends in v1.2.0)
- docs/superpowers/plans/2026-06-03-todo-9-1b-implementation.md — Wave B (39 tasks, ~5 days, ends in v1.3.0)
- docs/superpowers/plans/2026-06-03-todo-9-1c-implementation.md — Wave C (21 polish items, bundled as v1.3.x patches)

The plans are exhaustive — each task has exact code, exact commands, expected
test counts. Do NOT re-design what's already specified. Execute as-written;
escalate only on genuine ambiguity.

## Your job

Execute all three waves SEQUENTIALLY using superpowers:subagent-driven-development.
STOP and wait for user confirmation between waves. Do not chain waves
unattended.

## Rules of engagement

1. **Always use superpowers:subagent-driven-development** for each wave. Fresh
   subagent per task; two-stage review (spec compliance + code quality) after
   each task; fix issues before moving on.

2. **Branch per wave.** Wave A on `fix/9-1a-ship-blockers`. Wave B on
   `refactor/9-1b-architecture`. Wave C on `polish/9-1c`. Branch from latest
   main; merge with --no-ff at end of wave.

3. **Tag at end of each wave.** v1.2.0 after A, v1.3.0 after B, patch tags
   (v1.3.1, v1.3.2, ...) every 5-7 tasks during C.

4. **Verify before tagging.** Each wave's final task must run `make unit`,
   `make api`, `make e2e` (full suite) and confirm green. The plan specifies
   expected test counts — match them or investigate the delta.

5. **Update TODO.md after each wave.** Mark #9.1a / #9.1b / #9.1c as done with
   the commit SHA + tag.

6. **STOP after each wave.** When wave A's final commit lands on main and
   v1.2.0 is pushed:
   - Output a concise summary (commit count, test counts, key changes,
     anything unexpected discovered).
   - Ask the user: "Wave A shipped as v1.2.0. Ready to proceed with Wave B
     (architecture tidy-up, ~5 days)?"
   - WAIT for user confirmation. Do not start Wave B autonomously.
   - Same for B → C transition.

7. **DON'T touch the plans/spec.** If you find a real defect in a plan during
   execution (genuinely wrong code, missing dependency, impossible step),
   escalate to the user before improvising. Do not silently rewrite plans.

8. **DON'T expand scope.** Each wave's task list is closed. If you spot a new
   issue during execution, note it in a "follow-ups" file at the end of the
   wave; do NOT fix it inline.

9. **Use TodoWrite** to track wave progress (one todo per task in the active
   wave). Mark each task completed as it ships.

## How to start

1. Run `git status` and `git log --oneline -5` to confirm you're on `main` at
   a commit matching the latest plans (look for "docs(plans): TODO #9
   implementation plans" — commit 081e57a or descendant).

2. Read the three plan files in full. Read them, don't skim — the per-task
   steps are the contract.

3. Invoke `superpowers:subagent-driven-development` (Skill tool).

4. Start Wave A, task 1. Dispatch the implementer subagent with the FULL task
   text (don't make the subagent read the plan file — paste the task block
   into the prompt). Provide scene-setting context (which wave, which task,
   what came before).

5. After each task: spec-compliance review → code-quality review → fix loop
   → mark complete in TodoWrite → next task.

6. After wave's final task: full test suite, merge, tag, push, STOP.

## Escalation protocol

- **BLOCKED**: re-dispatch with more context. If still blocked, ask user.
- **Genuinely ambiguous spec text**: ask user, don't guess.
- **Test count off by more than 2**: investigate before moving on (the delta
  is either a missed test or a real regression).
- **e2e flake on retry**: re-run once; if still flaky, mark as known flake
  in the follow-ups file and proceed. Don't bury it.
- **Production code change required outside the plan**: STOP, surface to
  user. The plans are the contract; expansion needs sign-off.

## Reporting cadence

- Per-task: implementer's DONE/DONE_WITH_CONCERNS report → your one-line
  summary in chat. Don't narrate every grep.
- Per-wave: full summary at the stop point (commit log, test counts, time
  spent, anything notable).
- Don't ask "should I continue?" between TASKS (the wave plan answers that);
  ask only between WAVES.

## Estimated wall-clock

- Wave A: 4-8 hours of subagent execution (17 tasks × ~15-30min each + reviews).
- Wave B: 1-2 days (39 tasks, some are large refactors with multiple sub-steps).
- Wave C: ~half a day across patch ships (mostly trivial fixes).

## Begin

Start now. Verify git state → invoke subagent-driven-development → dispatch
Wave A Task 1. Wave A's first task is "Delete the legacy backup directory
(K-1)" — should take 2 minutes including review.
```

---

## Notes on using this prompt

- **Fresh session is best.** This prompt is self-contained; starting fresh avoids context bleed from the previous session's discussion threads.
- **The model needs to be able to run `make e2e`** (~3.5 minutes per full run). Run on a machine with Playwright + Chromium installed.
- **The user (you) only needs to engage at wave boundaries.** Between waves: review the summary, decide if anything needs follow-up, then approve the next wave.
- **If a wave goes badly** (e.g., > 3 task escalations or genuine regressions): pause, examine, decide whether to update the plan or accept what shipped and roll forward.
- **The follow-ups file** the session writes at the end of each wave is the input to either a 9.1d wave or scope-bumped 1.x.y patches.
