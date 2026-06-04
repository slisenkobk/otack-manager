# Handoff prompt — execute TODO #9 Wave 9.1e (close everything, ship v1.4.0)

Copy-paste the block below into a fresh Claude Code session. Self-contained — no prior session memory required.

---

```
You are executing Wave 9.1e — the final wave of TODO #9 — for the otack-tasks
repository at /Users/slisenkobogdan/Work/AINeoLab/internal/otack/otack-tasks.

This wave closes the last carry-forward debt (S-6: CSP `'unsafe-inline'` for
styles), runs a full visual + functional verification across browsers + mobile
breakpoints, and ships v1.4.0 — formally closing TODO #9.

## Current state (verify first)

- Branch: `main`, commit `5f1a74a` ("Merge wave 9.1d — blockers + carry-forward
  debt (v1.3.3)"). Latest tag `v1.3.3`.
- 142 inline `style=""` attributes remain in `views/` (vs 355 at the start of
  9.1d). Of these, ~15 are truly dynamic (`<?= ?>` inside), ~127 are unique
  static one-offs.
- CSP still ships `style-src 'self' 'unsafe-inline'` — that's the directive
  this wave finally tightens.
- All 4 CI jobs green on `main` (unit-sqlite, unit-mysql, api, e2e).
- 304 unit / 85 api / ~73 e2e tests passing.

Run `git status` and `git log --oneline -3` to confirm. If the head isn't
`5f1a74a` or a descendant, stop and ask the user — something changed.

## The plan

Single plan file (read it in full, do not skim):

  .dev-notes/superpowers/plans/2026-06-04-todo-9-1e-implementation.md

It has 14 tasks in three parts:
- Part A (Tasks 1-6): inline-style elimination + flip CSP to nonce-only
- Part B (Tasks 7-12): cross-browser e2e + visual baseline regeneration +
  live walkthrough + a11y (axe-core) + Lighthouse smoke
- Part C (Tasks 13-14): version bump, tag v1.4.0, push

Each task has exact code, exact commands, exact commit messages. Don't
re-design. Execute as-written.

## Your job

Execute all 14 tasks SEQUENTIALLY using superpowers:subagent-driven-development.
Do NOT stop between tasks (continuous execution per the SDD skill). The only
hard stop is on a real blocker — see Escalation below.

This is a single wave — when v1.4.0 ships at the end of Task 14, you're done.

## Rules of engagement

1. **Use superpowers:subagent-driven-development** for each task. Fresh
   implementer subagent per task; two-stage review (spec compliance + code
   quality) after each; fix issues before moving on.

2. **Branch:** `polish/9-1e` off main. Merge with `--no-ff` at end of Task 14.

3. **Tag:** v1.4.0 — annotated tag at the end (Task 14). No interim tags
   during the wave; the plan is one cohesive shipment.

4. **Verify before tagging.** Task 14 must:
   - `make test` (unit + api + e2e all green)
   - Manual confirmation that CSP DevTools panel shows zero violations on
     the key pages from Task 10
   - axe-core sweep from Task 11 clean

5. **Update TODO.md in Task 13.** Mark #9 as `done` with all 7 release tags
   listed (v1.2.0 through v1.4.0). Don't shorten the list — the audit
   timeline matters.

6. **Continuous execution.** Don't ask "should I continue?" between tasks
   inside the wave. The plan is the contract; only stop on:
   - genuine ambiguity in the plan text (rare, plan is detailed)
   - test count off by ≥3 from the wave-pre baseline (304/85/73)
   - a real visual regression you can't immediately localise
   - the user redirects

7. **Use TodoWrite** to track all 14 tasks. Mark each completed as it ships.

## Critical context for specific tasks

**Task 1 (`inline_style()` helper):** `csp_nonce()` should already exist from
Wave 9.1b. If `grep -n csp_nonce system/View/helpers.php` returns nothing, the
v1.3.1 hot-fix may have removed it — re-add per the plan snippet before
proceeding.

**Task 2 (sweep ~114 static styles):** This is the bulk of the work. Three
exits per style: existing utility → use it; pattern-specific → new semantic
class in the matching stylesheet; truly one-off → `inline_style()` helper from
Task 1. The plan has a per-file checklist; commit per file group (~5-10
files), not per file, to keep history readable.

**Task 3 (15 dynamic styles → data-attr + JS bridge):** CRITICAL — the JS
bridge uses `el.style.setProperty(...)` which is NOT subject to CSP `style-src`
(CSP only governs HTML-source `<style>` and `style=""` attributes, not DOM
mutations). This is what lets us drop `'unsafe-inline'` while keeping dynamic
colors.

**Task 4 is a GATE.** `grep -rnE 'style="[^"]*"' views/ | grep -v 'nonce=' | wc -l`
must return 0 before Task 5 (CSP flip). If non-zero, fix every offender
before flipping CSP — otherwise prod styles silently break.

**Task 5 (CSP flip):** Remember the v1.3.1 lesson — adding `'nonce-X'` to
`style-src` AUTOMATICALLY DISABLES `'unsafe-inline'` per spec. Don't keep
both for "safety"; explicitly remove `'unsafe-inline'`.

**Task 10 (live walkthrough):** Seed admin credentials are
`admin@task.otack.eu` / `30926565`. Walk through all 14 listed flows. For
each, in Chrome DevTools Console: confirm zero CSP-violation reports, zero
JS errors, zero failed asset requests.

**Task 11 (axe-core):** May need `npm install --save-dev @axe-core/playwright`
if not already in package.json. The 10 pages listed are the minimum sweep;
add more if a violation hints at a systemic class issue.

## Escalation protocol

- **BLOCKED:** re-dispatch implementer with more context. Still blocked after
  one retry: ask user.
- **Genuinely ambiguous plan text:** ask user, don't guess.
- **Test count drift ≥3 from baseline (304/85/73):** investigate before
  proceeding (real regression vs accidentally-uncovered test).
- **e2e flake on retry:** re-run once isolated; if still flaky, document in
  `.dev-notes/superpowers/follow-ups/wave-9-1e.md` and proceed. Don't bury.
- **CSP violation found in Task 10/11 that you can't trace:** STOP. Document
  in chat with the exact URL + element + violation report from DevTools.
  Don't ship v1.4.0 with a CSP regression — that's the whole point of
  this wave.
- **Visual regression you can't pixel-match:** keep the new semantic class;
  do NOT bless a snapshot diff that hides a real shift. Note it for the
  user, get sign-off before regenerating that specific baseline.
- **Production code change required outside the plan:** STOP, surface to
  user. Plan is the contract; expansion needs sign-off.

## Reporting cadence

- Per-task: one-line summary in chat after the implementer + two reviewers
  finish. Don't narrate every grep.
- Per-part: brief status when Part A → Part B → Part C boundaries cross
  (e.g. "Part A done, 6 commits, CSP now nonce-only — moving to e2e
  matrix"). One sentence each.
- At Task 14 success: final summary
  - Commit count on `polish/9-1e`
  - Final test counts (unit / api / e2e × 3 browsers)
  - Lighthouse scores from Task 12
  - axe-core violations (should be 0)
  - Final inline-style count (should be 0 non-nonced, ≤10 nonced)
  - Final `!important` count (should be ~10-12)

## Estimated wall-clock

6-10 hours of focused execution. Plan breakdown:
- Task 1: 30 min — helper + TDD
- Task 2: 3-4 h — the bulk (sweep ~114 static styles)
- Task 3: 1-1.5 h — 15 dynamic → data-attr bridge
- Task 4: 5 min — gate
- Task 5: 15 min — CSP flip
- Task 6: 30 min — final !important prune
- Task 7: 20 min — Playwright matrix config
- Task 8: 45 min — full e2e × 3 browsers
- Task 9: 15 min — baseline regen
- Task 10: 1-2 h — live walkthrough
- Task 11: 1-1.5 h — axe-core sweep + fixes
- Task 12: 30 min — Lighthouse
- Task 13: 15 min — version + TODO
- Task 14: 10 min — tag + push

## How to start

1. `git status` and `git log --oneline -3` — confirm clean `main` at
   `5f1a74a` (or descendant).

2. Read the plan file in full:
   `.dev-notes/superpowers/plans/2026-06-04-todo-9-1e-implementation.md`

3. `git checkout -b polish/9-1e`

4. Invoke superpowers:subagent-driven-development (Skill tool).

5. Create TodoWrite with all 14 tasks (one per plan task — copy task
   titles verbatim from the plan's table of contents).

6. Dispatch Task 1's implementer subagent with the FULL task text from the
   plan + scene-setting (what came before, where we are in the wave).
   Don't make the subagent read the plan file.

7. Spec-compliance review → code-quality review → fix loop → mark Task 1
   complete → dispatch Task 2 → … → Task 14 → STOP and report.

## Begin

Start now. Verify git state → checkout branch → invoke SDD skill → create
TodoWrite → dispatch Task 1 (the `inline_style()` PHP helper + TDD — 30
minutes incl. review).
```

---

## Notes on using this prompt

- **Fresh session is required.** The plan file is detailed; starting a new
  session keeps the model focused on it without context bleed from earlier
  waves. The session DOES NOT need the previous wave's chat history — only
  the plan file and the current `main` commit.

- **The agent runs `make e2e`** (~4 minutes per full run on chromium alone,
  ~12 min across the new chromium+webkit+firefox matrix). Allocate machine
  time accordingly.

- **The user only needs to engage on:**
  1. Pre-start: confirm credentials are unchanged
     (`admin@task.otack.eu` / `30926565` — known seed admin).
  2. CSP violations found in Tasks 10/11 that the agent can't trace.
  3. Visual regressions where the agent asks for sign-off on a baseline
     change.
  4. The final v1.4.0 summary — review the deltas before considering #9
     formally closed.

- **If the agent stops mid-wave** (e.g., on a real blocker), the work is
  preserved on `polish/9-1e` — resumable from any commit via the same
  prompt + a note "resume at Task N".

- **The wave's follow-ups file** (`wave-9-1e.md`) is written if anything
  surfaces — likely small Lighthouse perf notes or out-of-scope items.
  Empty file = clean wave. Most likely outcome: a couple of minor a11y
  notes that didn't make the 90-min Task 11 budget.

- **TODO #9 closure:** Task 13 marks `#9 - done` in TODO.md with all 7
  release tags. After v1.4.0 ships, the audit is formally resolved. Any
  future work derived from this audit cycle (e.g. View typed DTOs) lives
  as new top-level TODO items, NOT as #9 follow-ups.
