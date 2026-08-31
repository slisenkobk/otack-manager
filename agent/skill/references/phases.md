# Phases

`otack next` hands you a `reason`. When it also hands you a task, `reason`
is one of `new`, `approved`, `rework`, `resume` — this file covers all four,
plus the `blocked` state any of them can end in, and the handover to review
that `approved`→`implementing` and `rework` both converge on. (`next` can
also come back with `task: null` and `reason: "idle"` or
`"idle_form_bound_exceeded"` — see SKILL.md's "The tick", step 1. Neither of
those is covered here because there is no task to act on.)

Every `otack state <id> <phase>` below is paired with an `otack tick-note
<id> <phase>` call — SKILL.md's "The tick" step 2 explains why. Do both
whenever you change phase, not just at the start of the tick.

## Finding "the" in-progress or review column

Steps below tell you to move the card into an in-progress column, or later
into a review column. The API only marks two columns specially —
`is_done` and `is_backlog`, both visible on each entry of the project's
`columns` array (from `otack task <id>`). Every other column is an ordinary
board column with a free-text `name`; there is no field saying "this is the
in-progress column" the way there is for done/backlog, and a board can have
more than one column that is neither done nor backlog. Match by name: look
for something containing "progress" (case-insensitive) for the in-progress
move, "review" for the review move — that matches the naming this system's
own docs use as an example (`"In review"`). If nothing matches, or more
than one column plausibly does, pick the most reasonable candidate rather
than stall the tick over it — the column is there for a human glancing at
the board, not something `otack next` or any other command reads back.
`agent_state` is the field that actually drives the state machine.

## reason: new — nobody has started this

1. `otack move <id> <column-id>` into the in-progress column (see above for
   how to find it).
2. `otack state <id> researching` — **before** you start, so a crash is
   visible as "was researching, died".
3. Read the task, the project's `agent_instructions`, and the relevant code.
   Use subagents.
4. Post a comment with: what you understand the task to be; what you propose
   to do; what you are deliberately leaving out; risks you see. End with an
   **explicit question about scope** — not "does this look right?" but
   "should I do A and B, or A only?".
5. `otack state <id> awaiting_approval`. Stop. The tick is over.

## reason: approved — someone answered your question

Read the new comments (`new_comments` from `otack task <id>`). If the
answer is ambiguous, ask again and stay in `awaiting_approval` — do not
guess at scope.

Otherwise: `otack state <id> implementing`, then work. Branch
`otack/task-<id>`, TDD to the repository's own conventions, small commits,
run its tests and linters. When green, go to the review phase below.

## reason: rework — review feedback arrived

Read the new comments. If the feedback is a small correction, go straight to
`otack state <id> implementing` and do it. If it actually changes the scope,
ask first and go back to `awaiting_approval`.

## reason: resume — a previous tick died mid-flight

The card says `researching` or `implementing`, but nothing is running. Do
**not** continue blindly. Establish what actually happened from git, inside
`local.path`:

- Does branch `otack/task-<id>` exist? What is on it?
- Is the working tree dirty?
- Do the tests pass?

Then post a comment saying plainly where the previous attempt stopped and
what you found, and continue from there. If the picture does not add up,
`otack state <id> blocked` and ask a human. Never invent a version of events.

## blocked — how any of the above ends when it doesn't add up

Any reason above can end in `otack state <id> blocked` instead of the phase
it names — a `repo_url` mismatch against `git remote get-url origin`, a
`from_form: true` discovered mid-tick, a resume whose git history doesn't
tell a coherent story, a request (in the task or a comment) for something
`references/boundaries.md` marks as never-allowed or human-first, or an
ambiguity you've already asked about once and still can't resolve. Always
post the comment explaining why *before* setting the state.

`blocked` is a dead end the agent does not walk back from on its own:
`otack next`'s picking logic drops any task whose `agent_state` is
`blocked` from consideration entirely, so a blocked task will not resurface
in a future tick by itself. It stays blocked until a person changes
`agent_state` by hand (in the UI, or via the API) — there is nothing further
for you to do on it once the comment is posted and the state is set.

## Finishing: handing over to review

- Project has a `dev_branch`: push there and comment with the `dev_url` so a
  human can look at it running.
- No `dev_branch`: say plainly in the comment that it must be checked
  locally, and give the exact commands to run it.

Then `otack move <id> <column-id>` into the review column (see "Finding
'the' in-progress or review column" above), and `otack state <id> review`.
