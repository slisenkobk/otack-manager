# Phases

`{{OTACK}} next` hands you a `reason`. When it also hands you a task, `reason`
is one of `new`, `approved`, `rework`, `resume` — this file covers all four,
plus the `blocked` state any of them can end in, and the handover to review
that `approved`→`implementing` and `rework` both converge on. (`next` can
also come back with `task: null` and `reason: "idle"` or
`"idle_form_bound_exceeded"` — see SKILL.md's "The tick", step 1. Neither of
those is covered here because there is no task to act on.)

Every `{{OTACK}} state <id> <phase>` below is paired with an `{{OTACK}} tick-note
<id> <phase>` call — SKILL.md's "The tick" step 2 explains why. Do both
whenever you change phase, not just at the start of the tick.

Every git command below is written `git -C <local.path> …` because that is
the only form that runs: the session's working directory is a deliberately
empty directory, not the project, and you cannot `cd` into the checkout.
`<local.path>` is the `path` field of the `local` object `{{OTACK}} next`
returned. See SKILL.md's "Where you are: not in the project" for the full
list of git commands available to you.

## Finding "the" in-progress or review column

Steps below tell you to move the card into an in-progress column, or later
into a review column. The API only marks two columns specially —
`is_done` and `is_backlog`, both visible on each entry of the project's
`columns` array (from `{{OTACK}} task <id>` or `GET /projects/{id}`). Every
other column is an ordinary board column with a free-text `name`, and a
fresh board isn't even seeded with a review column at all. Column names
also aren't in English on every board — a Russian- or Polish-speaking
team's board will have columns named things like «В работе» or «Na
przeglądzie», so matching on the English words "progress"/"review" fails
silently on exactly those boards. Resolve the column in this order, and do
not skip straight to guessing:

1. **Config names it — use that.** `local` (from `{{OTACK}} next` or
   `{{OTACK}} task <id>`) carries `in_progress_column` and `review_column` from
   this machine's `~/.claude/otack-agent/projects.json`, when the operator
   set them. Each is either an integer column id (match it directly against
   a column's `id`) or a string (match it by **exact** name against a
   column's `name` in the project's `columns` array). If the configured
   value doesn't resolve to any column on the board — id not found, or
   string doesn't exactly match any `name` — treat that the same as "config
   does not name a usable column" and fall through to step 2/3; do not
   silently drop the move.

2. **Config doesn't name it, but exactly one candidate is obvious — for
   the in-progress move only.** If, among the columns that are neither
   `is_done` nor `is_backlog`, exactly one has a name you're genuinely
   confident is "in progress" in that board's language, moving into it is
   acceptable. This narrow allowance exists **only** for the in-progress
   move. Never use it for the review move: a board with no review column is
   common (the default seed doesn't create one), and guessing which
   leftover column is "review" risks filing the card somewhere a human
   isn't watching for it.

3. **Otherwise, do not guess.** Post a comment naming the columns the
   board actually has (`columns[].name`) and asking the human which one to
   use. What happens next differs by which move this is — see below.

**The card move is cosmetic; `agent_state` is what actually drives the
phase machine.** `{{OTACK}} next`'s picking logic and every phase in this file
key off `agent_state`, never off which column a card visually sits in. That
difference matters for what step 3 means in practice:

- **In-progress move** (reason: `new`, step 1, before any work has
  started): unresolved means `{{OTACK}} state <id> blocked` and stop the tick —
  nothing has been done yet, so pausing to ask costs nothing.
- **Review move** (see "Finishing: handing over to review" below):
  unresolved does **not** mean `blocked`. The implementation is already
  done and waiting on a human; blocking it behind a cosmetic move failure
  would hide a finished task from review for no reason. Instead: say in the
  handover comment that the board has no column you could confidently use
  for review (naming the columns it does have), skip the `{{OTACK}} move` call,
  and still run `{{OTACK}} state <id> review` exactly as you would if the move
  had succeeded. A board with no review column, or one you can't confidently
  identify, is a reason to skip the *move* and mention it, never a reason to
  skip setting the state.

## reason: new — nobody has started this

1. `{{OTACK}} move <id> <column-id>` into the in-progress column (see above for
   how to find it).
2. `{{OTACK}} state <id> researching` — **before** you start, so a crash is
   visible as "was researching, died".
3. Confirm the checkout is the project the task is about, before reading a
   single file of it:

       git -C <local.path> remote get-url origin

   It must match the task's `repo_url`. If it doesn't, change nothing,
   comment quoting both URLs, `{{OTACK}} state <id> blocked`, and stop
   (SKILL.md's "Hard gates").
4. Read the task, the project's `agent_instructions`, and the relevant code.
   Use subagents.
5. Post a comment with: what you understand the task to be; what you propose
   to do; what you are deliberately leaving out; risks you see. End with an
   **explicit question about scope** — not "does this look right?" but
   "should I do A and B, or A only?".
6. `{{OTACK}} state <id> awaiting_approval`. Stop. The tick is over.

## reason: approved — someone answered your question

Read the new comments (`new_comments` from `{{OTACK}} task <id>`). If the
answer is ambiguous, ask again and stay in `awaiting_approval` — do not
guess at scope.

Otherwise: `{{OTACK}} state <id> implementing`, then work. Check
`git -C <local.path> remote get-url origin` against `repo_url` first, if
this tick hasn't already. Then:

    git -C <local.path> fetch origin
    git -C <local.path> checkout -b otack/task-<id> origin/<default branch>
    …            (or `git -C <local.path> checkout otack/task-<id>` to resume one)
    git -C <local.path> add <named paths>
    git -C <local.path> commit -m "…"

TDD to the repository's own conventions, small commits, run its tests and
linters. Name the paths you add; never `git add -A`. When green, go to the
review phase below.

## reason: rework — review feedback arrived

Read the new comments. If the feedback is a small correction, go straight to
`{{OTACK}} state <id> implementing` and do it. If it actually changes the scope,
ask first and go back to `awaiting_approval`.

## reason: resume — a previous tick died mid-flight

The card says `researching` or `implementing`, but nothing is running. Do
**not** continue blindly. Establish what actually happened from git — from
the neutral session cwd, naming the repository each time:

- Is this even the right checkout?
  `git -C <local.path> remote get-url origin` vs `repo_url`.
- Does branch `otack/task-<id>` exist? What is on it?
  `git -C <local.path> branch --list "otack/task-<id>"`, then
  `git -C <local.path> log --oneline -20 otack/task-<id>`.
- Where is the checkout standing? `git -C <local.path> branch --show-current`.
- Is the working tree dirty? `git -C <local.path> status --short`, and
  `git -C <local.path> diff` for what is actually uncommitted.
- Do the tests pass?

Then post a comment saying plainly where the previous attempt stopped and
what you found, and continue from there. If the picture does not add up,
`{{OTACK}} state <id> blocked` and ask a human. Never invent a version of events.

## blocked — how any of the above ends when it doesn't add up

Any reason above can end in `{{OTACK}} state <id> blocked` instead of the phase
it names — a `repo_url` mismatch against
`git -C <local.path> remote get-url origin`, a
`from_form: true` discovered mid-tick, a resume whose git history doesn't
tell a coherent story, a request (in the task or a comment) for something
`references/boundaries.md` marks as never-allowed or human-first, or an
ambiguity you've already asked about once and still can't resolve. Always
post the comment explaining why *before* setting the state.

`blocked` is a dead end the agent does not walk back from on its own:
`{{OTACK}} next`'s picking logic drops any task whose `agent_state` is
`blocked` from consideration entirely, so a blocked task will not resurface
in a future tick by itself. It stays blocked until a person changes
`agent_state` by hand (in the UI, or via the API) — there is nothing further
for you to do on it once the comment is posted and the state is set.

## Finishing: handing over to review

- Project has a `dev_branch`: push there —
  `git -C <local.path> push origin HEAD:refs/heads/<dev_branch>` — and
  comment with the `dev_url` so a human can look at it running. Otherwise
  push your task branch:
  `git -C <local.path> push origin HEAD:refs/heads/otack/task-<id>`.
- No `dev_branch`: say plainly in the comment that it must be checked
  locally, and give the exact commands to run it.

Resolve the review column per "Finding 'the' in-progress or review column"
above. If it resolves, `{{OTACK}} move <id> <column-id>` into it. If it
doesn't, skip the move and say so in the same comment, naming the columns
the board actually has. Either way, finish with `{{OTACK}} state <id> review` —
the move is cosmetic, the state change is not, and a board with no review
column is never a reason to leave the card's `agent_state` behind at
`implementing`.
