# Otack Manager agent bridge

An autonomous worker that picks up tasks assigned to it in Otack Manager,
researches them, gets scope approved in a comment thread, implements the
change on its own branch, and hands it to review — over the REST API, one
tick at a time. Design: `.dev-notes/superpowers/specs/2026-08-27-agent-bridge-design.md`.

This directory is the single source of truth. `make install-agent` symlinks
`agent/skill` into `~/.claude/skills/otack-agent/` rather than copying it, so
editing a file here is the whole update — nothing to re-sync.

## Prerequisite

The Otack Manager instance you point this at must be **1.7.1 or newer**:
that's the release carrying `agent_state`, `GET /api/v1/tasks` with
cross-project filters, and `from_form` on task detail responses. Against an
older instance the CLI refuses with a clear error rather than silently
misbehaving.

## Install

1. In this repo: `make install-agent`. It creates
   `~/.claude/otack-agent/` (mode `0700`), a `.env` inside it (mode `0600`,
   written under `umask 077` so it is never briefly world-readable), and
   `projects.json` (copied from `agent/projects.example.json`) — but only if
   those two files don't already exist. It also makes `agent/bin/otack` and
   `agent/bin/otack-tick` executable and symlinks
   `~/.claude/skills/otack-agent` → `agent/skill`. Safe to re-run: it never
   overwrites an existing `.env` or `projects.json`, and it replaces the
   skills symlink cleanly even if something odd (a stale real directory) is
   sitting at that path.
2. In Otack Manager's web UI, create a user for the bot (role `employee` is
   enough — it does not need admin). Add that user as a member of every
   project you want the agent to see; membership is the only visibility
   lever tokens have (spec §10), so a project the bot isn't a member of is
   invisible to it regardless of what's in `projects.json`.
3. Issue an API token for that user at `/profile/tokens` and put it in
   `~/.claude/otack-agent/.env`:
   ```
   OTACK_API_URL=https://your-instance.example/api/v1
   OTACK_API_TOKEN=otk_...
   ```
   The file must stay mode `0600`, and its parent directory
   (`~/.claude/otack-agent/`) must not be group- or world-writable —
   `Config::load()` refuses to even start if either is looser than that,
   rather than reading a token out of a file anyone else on the box could
   have replaced.
4. Edit `~/.claude/otack-agent/projects.json` to list every local repository
   the agent may touch — the machine-local half of the config (spec §7.3).
   A project absent from this file is invisible to the agent, not
   read-only: the server can't redirect a worker into an arbitrary
   directory just because someone edited a project's fields in the web UI.
   ```json
   {
     "projects": {
       "42": { "path": "/Users/you/Work/otack/otack-cms", "write": true,  "test_cmd": "make test", "in_progress_column": 3, "review_column": "In review" },
       "51": { "path": "/Users/you/Work/otack/eva-site",  "write": false }
     }
   }
   ```
   `write: false` lets the agent research and comment on tasks in that
   project but never touch the working tree — useful for a first, supervised
   run.

   Every entry is validated strictly at load time, and any failure here
   refuses to run rather than degrading into "no work to do":
   - `path` must be a JSON string, absolute (start with `/`), and must exist
     as a real directory. `~` is never expanded by PHP, so a `~/...` path
     is rejected with a hint to spell it out in full.
   - `write` must be a real JSON boolean. `"false"` in quotes is rejected on
     purpose — a quoted string is the most common hand-editing mistake, and
     it must never silently coerce to `true`.
   - `in_progress_column` and `review_column` are each either an integer
     column id or the exact column name as a string (e.g. `"В работе"`).
     They tell the agent which board column to move a card into when it
     starts work and when it hands over for review. They're required
     because board columns are free text and may be named in any language —
     the agent cannot infer "in progress" from a column called `"В работе"`
     or `"Do zrobienia"` — and a freshly created board typically has no
     review column at all, so there is nothing to guess from server-side
     flags either. Omit either key to leave that move up to the agent's own
     judgement (see `agent/skill/references/phases.md`); anything present
     but of the wrong type (a float, a bool, an array) is rejected loudly,
     naming the project and the key.
   - `projects.json` itself, and the directory it lives in, must not be
     group- or world-writable, for the same reason as `.env` above.
5. In the web UI, fill in each project's `repo_url`, `default_branch`, and
   `agent_instructions` (stack, how to run tests, conventions, what not to
   touch). The agent checks `repo_url` against `git remote get-url origin`
   in the local path before doing anything — a mismatch is a hard stop, not
   a warning.

## One-time login (do this before the first scheduled tick)

`otack-tick` launches `claude -p` as a child process with `CLAUDE_CONFIG_DIR`
pointed at an isolated directory, `~/.claude/otack-agent/claude-home`,
instead of your own `~/.claude`. This is deliberate: your personal
`~/.claude/settings.json` accumulates broad allow rules over time (arbitrary
code execution like `php -r`/`npx tsx -e`, network egress, ready-made
production `ssh` commands — 185 entries is not unusual), and the CLI merges
those into whatever the bot is launched with. Left alone, every rule you've
ever granted yourself becomes part of the bot's authority too. Isolating
`CLAUDE_CONFIG_DIR` closes that off — verified empirically: a `claude -p`
child pointed at this isolated directory was refused a command
(`php -r`) that the same child executed without complaint when it inherited
your normal `~/.claude` config.

The cost of that isolation is that it isolates **authentication** along with
everything else. A freshly created `claude-home` has never logged in, so the
very first tick — and every tick until you fix this — fails immediately with
`Not logged in` and a non-zero exit. This is the single most important setup
step, and nothing about the error message points at it.

Log the isolated directory in once, out of band, before relying on the
schedule:

```
CLAUDE_CONFIG_DIR=$HOME/.claude/otack-agent/claude-home claude
```

then complete the interactive login flow, or run
`CLAUDE_CONFIG_DIR=$HOME/.claude/otack-agent/claude-home claude setup-token`
if you'd rather not sit through the interactive flow. Either way, do this
once per machine that will run the cron job — the login lives in that
directory, not in this repository.

## Verify

```
agent/bin/otack whoami
```

Expected: JSON with the bot's `id`, `name`, `email`, `role`. A `401` means
the token in `.env` is wrong or revoked; "config missing" means `.env`
wasn't created — re-run `make install-agent` or create it by hand.

## CLI reference

Every command prints JSON to stdout and nothing else; errors go to stderr as
`{"error": "..."}` plus a non-zero exit code. Run any of these directly to
inspect state without waiting for a scheduled tick.

| Command | What it does | Example |
|---|---|---|
| `otack whoami` | Verify the token, show the acting user | `agent/bin/otack whoami` |
| `otack next` | The task this tick should work, with a reason (`new`, `resume`, `rework`, `approved`, `idle`, or `idle_form_bound_exceeded`) | `agent/bin/otack next` |
| `otack task <id>` | Task + project + local path + full thread + what's new since the agent last spoke | `agent/bin/otack task 123` |
| `otack project <id>` | repo_url, branches, agent_instructions, columns | `agent/bin/otack project 42` |
| `otack new-comments <id>` | Comments written after the agent's own last one on that task (spec §4.1) | `agent/bin/otack new-comments 123` |
| `otack state <id> <phase>` | Set `agent_state` — one of `researching`, `awaiting_approval`, `implementing`, `review`, `blocked` | `agent/bin/otack state 123 researching` |
| `otack move <id> <column-id>` | Move the card; position is computed to append past the column's current end, not a fixed constant | `agent/bin/otack move 123 7` |
| `otack comment <id>` | Post a comment; body is read from stdin | `echo "done, see branch otack/task-123" \| agent/bin/otack comment 123` |
| `otack tick-note <id> <phase>` | Record on the running tick's lock file which task/phase it's on, so a wedged tick can be named in the *next* tick's warning | `agent/bin/otack tick-note 123 implementing` |

Every command that names a project or task (`task`, `project`,
`new-comments`, `state`, `move`, `comment`, `tick-note`) first checks that
its project is listed in `~/.claude/otack-agent/projects.json` and refuses
outright if it isn't — even `otack tick-note`, which only edits the lock
file's JSON payload, not the token itself. `otack next` and `tick-note` are
meant to be called by the skill during a tick, not usually by hand — the
rest are useful for debugging what the agent sees.

## Running on a schedule

The scheduled entry point is `agent/bin/otack-tick`, not `otack` directly:
it's the process that holds the session-lifetime lock (spec §5.1), so it
must be what cron launches. `otack-tick` itself runs `claude -p` as a child
process to execute one tick, with the isolated `CLAUDE_CONFIG_DIR` described
above.

macOS's `cron` starts jobs with a bare `PATH=/usr/bin:/bin` — neither `php`
(needed by the `#!/usr/bin/env php` shebang on both `otack` and
`otack-tick`; typically `/usr/local/opt/php/bin` on a Homebrew install, not
`/usr/local/bin` itself) nor `claude` (launched by `otack-tick`; typically
`~/.local/bin`) live there. Set `PATH=` explicitly on the crontab line
rather than relying on the shell's interactive PATH, which cron never sees:

```
PATH=/Users/you/.local/bin:/usr/local/opt/php/bin:/usr/local/bin:/usr/bin:/bin
*/30 * * * * cd /path/to/otack-tasks && agent/bin/otack-tick >> ~/.claude/otack-agent/tick.log 2>&1
```

Adjust the `claude` and `php` locations to match `which claude` / `which
php` (or, if `php` isn't on your interactive `PATH` either, wherever
Homebrew put it — check with `brew --prefix php`) on the machine actually
running the cron job — they are not guaranteed to be the same across
machines even when both are Homebrew installs.

### Exit codes

`otack-tick` uses distinct exit codes so cron / a human reading logs can
tell these apart:

| Code | Meaning |
|---|---|
| `0` | Ran a tick successfully (or did nothing because there was no work — that's still success). |
| `1` | Config error — missing `~/.claude/otack-agent/`, unreadable `.env`, bad `projects.json`, or the `claude` child itself failed (e.g. not logged in). |
| `2` | Usage error — a bad or unrecognised command-line flag. |
| `75` | Blocked: another tick already holds the lock, so this invocation did nothing and exited immediately. Not a failure — normal when a long implementation phase overlaps the next scheduled run. |

A monitoring cron or alert should treat `1` and `2` as real failures worth
paging on. `75` is expected background noise on a schedule tighter than the
longest tick can take, and `0` is fine by definition — neither should alert
on its own. If you want to catch a tick that's wedged rather than merely
overlapping, watch the log for the `WARNING: ... held the lock for` line
described below instead of the exit code.

### Is it alive?

- `tail -f ~/.claude/otack-agent/tick.log` — every scheduled run appends here.
- `cat ~/.claude/otack-agent/agent.lock` — while a tick is running, this has
  the holder's `pid`, `host`, `task_id`, `phase`, and `started_at`. A tick
  with no task picked yet (or one that died before its first pick) still
  leaves a well-formed file with `task_id: null`. Nothing currently running
  is the normal state between ticks, not a fault.
- `crontab -l` — confirms the schedule is actually installed.

### Stopping it

Remove (or comment out) the crontab line. A tick already in flight finishes
on its own — there is no separate daemon to kill, and killing the `claude`
child process directly is safe (the wrapper's lock releases the moment its
process exits, by kernel guarantee, spec §5.1) but leaves whatever the tick
was doing mid-implementation for the *next* tick's recovery protocol to sort
out (`agent/skill/references/phases.md`'s `resume` section) rather than
cleanly.

## Never delete `agent.lock` by hand

`agent.lock` is not just a status file — the `flock` held on its inode *is*
the mutex. Deleting the file while a session holds it does not release that
mutex; it only detaches the path from the still-locked inode. A second tick
would then create a brand-new, wholly uncontended inode at the same path,
lock *that* one successfully, and launch a second concurrent session —
exactly the failure this lock exists to prevent.

`otack-tick` guards against this: once it has ever acquired the lock
successfully on a machine, it writes a marker
(`~/.claude/otack-agent/.agent-lock-initialized`) that is never removed. If
`agent.lock` later goes missing while that marker is present, it refuses to
recreate the lock file and exits with an error explaining exactly this,
rather than silently bootstrapping a second one. If you ever hit that
message: check for a live process first (`ps aux | grep otack-tick`), kill
it if it's genuinely dead, and only then remove the marker file to let the
script bootstrap again.

## When something looks wrong

- **`tick already running ... doing nothing` in the log** — normal (exit
  `75`, see above). A tick that runs long (a big implementation phase) can
  still be going when the next scheduled one fires; the new one exits
  immediately rather than queuing or double-running.
- **A `WARNING: ... held the lock for Nh` line** — the running tick has been
  going for more than 3 hours (`STALE_AFTER_SECONDS`). It is *not* killed
  automatically — a long implementation phase can be legitimate — but past
  that threshold `otack-tick` also tries to post one "работаю подозрительно
  долго" comment on the task it's stuck on (read from the lock file's
  `task_id`), once per stuck run, not once per tick. Go look at what it's
  actually doing before deciding whether to intervene.
- **A task sitting in `blocked`** — the agent gave up and is waiting on a
  human. Read its last comment; that's always where it explains why.
- **`401` from any command** — the token in `.env` is wrong or was revoked.
  Re-issue one at `/profile/tokens` and update the file (mode stays `0600`).
- **`Not logged in`, or the child exits non-zero immediately** — the
  isolated `claude-home` directory has never completed the one-time login.
  See "One-time login" above.
- **`Agent config unusable` / a `Config::load` error naming `.env` or
  `projects.json`** — one of the strictness checks above tripped: wrong file
  mode, a group/world-writable directory, a relative `path`, a quoted
  `"false"` for `write`, or a malformed `in_progress_column`/`review_column`.
  The error message names the exact file and field; fix it and re-run.
- **A project the agent should see isn't showing up in `otack next`** — check,
  in order: is the bot a member of that project in the web UI; is the
  project listed in `~/.claude/otack-agent/projects.json`; is the task
  actually assigned to the bot and sitting in a column that is neither the
  done column nor the backlog.

## `--selftest` flags (test suite only)

`agent/bin/otack-tick` accepts `--selftest=hold:<seconds>` and
`--selftest=print-cmd`, plus — only to make the stale-tick warning testable
without waiting three real hours — `--selftest-task-id=<id>` and
`--selftest-started-at=<epoch>` alongside `hold:`. All of these require
`OTACK_TICK_ALLOW_SELFTEST=1` in the environment; without it they're refused
outright, so a stray `--selftest*` token surviving into a real cron line can
never be mistaken for anything but a configuration mistake.

With `--selftest=hold:<n>`, the wrapper takes the mutex, writes the lock
file, sleeps `<n>` seconds (capped at 60), and exits **without** launching a
`claude -p` session — it does no actual work. This exists purely so
`tests/unit/test_agent_lock.php` can drive two real, concurrent processes
against the same lock file and observe blocking behaviour honestly. There is
no reason to pass any `--selftest*` flag outside that test file; a real cron
invocation never does.
