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
       "42": { "path": "/Users/you/Work/otack/otack-cms", "write": true,  "test_cmd": "make test", "default_branch": "main", "in_progress_column": 3, "review_column": "In review" },
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
   - `default_branch` (optional, `write: true` projects only) names the
     branch the agent must never commit to, check out or push to. It is
     what the git gate below enforces, and it is deliberately **not**
     guessed as `main`/`master`. If you omit it, `otack-tick` reads the
     repository's own `refs/remotes/origin/HEAD` instead; if that is
     missing too (common in a clone made before the remote had any
     branches), the tick refuses to launch and tells you to add the key.
     `git remote set-head origin -a` inside the repository is the other
     way to fix it.
   - `test_cmd` becomes a `Bash(<test_cmd>:*)` grant for the session. The
     validation on it is **syntactic only** — it checks characters, not
     authority — so `"sh"`, `"bash"` or `"make"` all pass and each grants
     a correspondingly broad slice of the shell, and `make` runs whatever
     the project's Makefile happens to say. Put the narrowest command that
     actually runs the tests here, and treat the project's Makefile as
     part of what you are trusting the agent with.
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

`otack-tick` now resolves `claude` to an absolute path itself (PATH first,
then `~/.local/bin`, `/usr/local/bin`, `/opt/homebrew/bin`) and fails with a
message naming this crontab line if it finds none — so a missing `claude`
is no longer a bare "command not found". `php` still has to be reachable,
because both scripts start with `#!/usr/bin/env php`.

The session's own transcript does **not** go to `tick.log`: it is written to
`~/.claude/otack-agent/tick-session.log` (mode `0600`, rotated to `.1` past
5 MB). That is deliberate — the session has plain `Read` over the
filesystem, so its transcript can quote anything it read, and cron mail is
the wrong place for that. `tick.log` gets only the wrapper's own
diagnostics, including a line naming the transcript.

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
| `1` | Config error — missing `~/.claude/otack-agent/`, unreadable `.env`, bad `projects.json`, a loosened `claude-home`, a write-enabled repository whose default branch cannot be determined, or `claude` not found. |
| `2` | Usage error — a bad or unrecognised command-line flag. |
| `75` | Blocked: another tick already holds the lock, so this invocation did nothing and exited immediately. Not a failure — normal when a long implementation phase overlaps the next scheduled run. |
| `100 + N` | The wrapper ran fine; the `claude` session exited `N`. E.g. `101` = session exited 1 (the usual "not logged in"), `147` = session exited 47. |

These used to collide: `exit($code)` propagated the child's status verbatim,
so a session that happened to exit `1`, `2` or `75` was indistinguishable
from the wrapper's own config error, usage error or "blocked". Anything
`>= 100` is now unambiguously the child's.

A monitoring cron or alert should treat `1`, `2` and anything `>= 100` as
real failures worth paging on. `75` is expected background noise on a schedule tighter than the
longest tick can take, and `0` is fine by definition — neither should alert
on its own. If you want to catch a tick that's wedged rather than merely
overlapping, watch the log for the `WARNING: ... held the lock for` line
described below instead of the exit code. (`90` also exists, but only inside
the session transcript: it is the git gate refusing a command, never a
scheduled run's own exit code.)

### Is it alive?

- `tail -f ~/.claude/otack-agent/tick.log` — every scheduled run appends its
  own diagnostics here (given the crontab redirect above).
- `tail -f ~/.claude/otack-agent/tick-session.log` — what the `claude`
  session itself printed, including any `otack git gate: REFUSED` lines.
  Mode `0600`: it can quote anything the session read.
- `cat ~/.claude/otack-agent/agent.lock` — while a tick is running, this has
  the holder's `pid`, `host`, `task_id`, `phase`, and `started_at`. A tick
  with no task picked yet (or one that died before its first pick) still
  leaves a well-formed file with `task_id: null`. Nothing currently running
  is the normal state between ticks, not a fault.
- `crontab -l` — confirms the schedule is actually installed.

### Stopping it

Remove (or comment out) the crontab line. A tick already in flight finishes
on its own — there is no separate daemon to kill, and killing the `claude`
child process directly is safe (the wrapper then exits and the lock goes
with it, *unless* the session left a background process holding the
inherited descriptor — see "The lock can outlive the session") but leaves whatever the tick
was doing mid-implementation for the *next* tick's recovery protocol to sort
out (`agent/skill/references/phases.md`'s `resume` section) rather than
cleanly.

## What actually bounds the session

Three mechanisms, each verified against a real `claude -p` child rather than
inferred from the CLI's documentation:

- **Files.** `--permission-mode acceptEdits` plus one `--add-dir` per
  write-enabled project. `Edit` and `Write` are deliberately **not** in
  `--allowedTools`: a bare tool name there is an *unconditional* grant that
  short-circuits the directory check entirely, which is how an earlier
  version let the session write anywhere on the disk while looking bounded.
  With them withheld, a write inside an `--add-dir` succeeds and a write
  outside every one of them is refused. The session's own cwd is writable
  too, which is why it is `~/.cache/otack-agent/session-cwd` — a directory
  with nothing in it and no sensitive neighbours, deliberately *not* beside
  `.env` and `projects.json`.
- **Tools.** An isolated `CLAUDE_CONFIG_DIR` (see "One-time login") so your
  personal allow-list is not merged in, an explicit `--allowedTools`, and an
  explicit `--disallowedTools` for the §9 absolutes. Bash fails closed:
  a command matching no allow prefix is not run, because in `-p` mode there
  is nobody to answer the prompt. What the allow-list contains for git is
  its own topic — see "What git the session may run" below.
- **Git.** A real gate, not permission prefixes — see below.

### The git gate

Permission prefixes cannot express a branch policy, and the attempt to make
them do so was demonstrably bypassable: `git checkout -B main` does not
match a `Bash(git checkout main:*)` deny rule, `git push origin HEAD:main`
does not match `Bash(git push origin main:*)`, and `Bash(git push:*)` also
permits `git push https://somewhere-else/x HEAD` — shipping the whole
repository past the `curl`/`wget`/`ssh`/`WebFetch` denies.

So every tick generates, under `~/.claude/otack-agent/git-gate/`:

- `policy.json` — for each write-enabled repository, its default branch and
  its origin URL, derived from `projects.json`;
- `git` — a shim placed **first** on the child's `PATH`, which inspects
  argv and then execs the real git;
- `hooks/pre-commit` and `hooks/pre-push`, activated for **every** git
  invocation in the session via `GIT_CONFIG_COUNT`/`core.hooksPath` —
  including a git run by absolute path from inside a Makefile, which the
  shim would never see.

Between them they refuse: committing, merging or rebasing while on the
default branch; any push whose destination ref resolves to the default
branch, in any refspec spelling; force pushes (by flag, by leading `+`, or
by being non-fast-forward at the hook); ref deletions; tags; branch
deletion, rename and force-move; `git remote`/`git config` writes (which
would move the push target or switch the hooks off); `--no-verify` and
`git commit -n` (which would skip the hooks); `git -c …` (which would
re-point `core.hooksPath`); and any push to a URL that is not that
repository's own `origin`. Ordinary work — `git status`, `git diff`,
committing on a task branch, pushing that branch to origin — passes
straight through. A refusal prints `otack git gate: REFUSED — …` and exits
`90`.

The bot cannot step around the shim: every command it is allowed to run
must literally begin with `git ` to match an allow prefix, so
`/usr/bin/git …`, `env git …`, `PATH=… git …` and `command git …` are all
refused by the permission layer before they execute. The one residual path
is a `test_cmd` broad enough to be a shell (`sh`, `bash`, `make`) invoking
git by absolute path *and* `--no-verify` — see the `test_cmd` note above.

### What git the session may run

The session's cwd is `~/.cache/otack-agent/session-cwd`, not a project, and
`cd` is not on the allow-list — so every git command the agent runs names
its repository: `git -C <project path> …`. For a while the allow-list did
not know that. It carried bare prefixes (`Bash(git status:*)`,
`Bash(git commit:*)`, …) written for a git invoked *inside* the checkout,
and the result was a bot that was perfectly safe and completely useless: a
real `claude -p` child was refused `git -C <repo> checkout -B main` by the
permission layer before the branch-policy gate ever saw it, and refused
`git -C <repo> remote get-url origin` too — the check the skill requires
before any work at all.

The allow-list now grants, per write-enabled project, one rule per verb:

    git -C <path> status | diff | log | show | rev-parse
    git -C <path> branch --show-current | branch --list | remote get-url
    git -C <path> checkout | switch | add | commit
    git -C <path> fetch | pull | push

Two things it deliberately is not:

- **not** a single `Bash(git -C:*)` grant. That is shorter, and it is
  `git <anything>` in disguise: inside a policy repository the gate passes
  `clone`, `archive`, `bundle`, `send-email`, `daemon`, `worktree` and
  `update-ref` straight through, so one blanket rule would hand back
  network egress and writes outside every `--add-dir`. (Nor is it a blanket
  `-C` *deny*: deny is a prefix rule too, and outranks allow, so that one
  line would refuse `git -C <repo> status` along with everything else.)
- **not** accompanied by the old bare forms. Those could only ever have
  authorised a git run in the empty session cwd, where the gate refuses
  every mutating subcommand anyway — dead weight that also read as though
  the workflow happened inside the checkout.

Two consequences worth knowing as an operator:

- a project path containing a space, `)` or `:` gets **no** git rules and a
  note on stderr, because such a path either rewrites the rule parser's
  input or produces a rule the session's (necessarily quoted) command can
  never match. Everything else about the project still works; only git is
  unavailable there. Move the checkout to a plain path to fix it.
- `pull` is new to the allow-list, so the gate learned about it in the same
  pass: it is fetch+merge, i.e. it writes history into whatever branch is
  checked out, and a tick often finds the checkout sitting on the default
  branch. It is therefore refused on the default branch and in any
  repository the policy does not list, exactly like `commit` and `merge`.
- widening the list did not move the branch policy back into it. The policy
  lives in the gate, which is command-shape independent, and the
  `-C` spellings of the §9 absolutes (`push --force`, `branch -D`, `tag`,
  `remote set-url`, `config`, `checkout <default>`, `push origin <default>`)
  are denied on top of it, exactly as the bare spellings were.

## Never delete `agent.lock` by hand

`agent.lock` is not just a status file — the `flock` held on its inode *is*
the mutex. Deleting the file while a session holds it does not release that
mutex; it only detaches the path from the still-locked inode. A second tick
would then create a brand-new, wholly uncontended inode at the same path,
lock *that* one successfully, and launch a second concurrent session —
exactly the failure this lock exists to prevent.

`otack-tick` guards against this: on every successful acquisition it records
the holder (pid, host, time) in `~/.claude/otack-agent/.agent-lock-initialized`,
which is never removed. If `agent.lock` later goes missing, the tick looks
at that record: if the process named there is **still running**, it refuses
to recreate the lock and says so; if it is not, nothing can be holding the
deleted file, so it bootstraps a fresh lock and logs that it did. (Deleting
the file while idle used to brick the bot with exit `1` on every subsequent
tick, recoverable only by deleting that marker.)

### The lock can outlive the session

`flock` is tied to the open file *description*, not to the process — so
`claude`, every shell it spawns, and anything those leave behind all inherit
it. A session that runs `make test` and leaves a dev server or a watcher
running hands the lock to that process, and the wrapper exiting does not
free it.

The symptom is a tick that says the holder is not running:

```
agent.lock at … is still locked, but its recorded holder (pid 1234, host …) is not running
```

There is no otack-tick in `ps` and no session in flight. Find the real
holder and kill it:

```
lsof ~/.claude/otack-agent/agent.lock
```

Deleting `agent.lock` still does **not** help, for the reason above. In this
state the tick also suppresses the "работаю подозрительно долго" comment —
there is no running session to be slow, so posting it on every tick for
hours would be noise on a task.

What the kernel does still guarantee is the useful half: there is no on-disk
lock state to clean up, so a crash, a reboot or a closed laptop never leaves
a stale lock file behind.

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
- **`Not logged in`, or the tick exits `101`** — the isolated `claude-home`
  directory has never completed the one-time login. See "One-time login"
  above. The message itself is in `tick-session.log`, not in `tick.log`.
- **`otack git gate: REFUSED` in `tick-session.log`** — the agent tried
  something spec §9 forbids (usually the default branch). This is working as
  intended; the fix is a human merging the agent's task branch, not
  loosening the gate.
- **`chmod 700 …/claude-home`** — the isolated config directory holding the
  bot's login became group- or world-accessible. It is checked on every
  tick now, not only when created.
- **`cannot determine the default branch of write-enabled project …`** —
  add a `default_branch` key for that project in `projects.json`, or run
  `git remote set-head origin -a` in the repository. The tick refuses to
  guess `main`.
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
`--selftest-started-at=<epoch>` alongside `hold:`, and
`--selftest-race-sleep-ms=<n>`, which widens the `fopen()`→`flock()` window
so a test can replace the lock file inside it and prove the inode re-check
fires. All of these require
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
