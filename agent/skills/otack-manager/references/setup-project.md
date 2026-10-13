# Setting up a project for the autonomous bot

The bot (otack-manager-agent) only touches projects listed in
`~/.claude/otack-agent/projects.json`. This dialogue creates the local
folder (or registers an existing checkout) and writes that entry.
`setup-project` validates everything before it creates or writes anything:
on any error nothing is changed.

1. **Look first.** Run `{{OTACK_MANAGER}} folder-name <project-id>`. It
   returns the suggested `folder`, the configured `roots`, and per root a
   `candidates` row: resulting `path`, `exists`, git `origin` and
   `same_repo` (does that origin match the project's `repo_url`; null when
   unknown). Also the project's `repo_url`, `default_branch`, board
   `columns`, and `local` (the current entry, if any).
   - `roots` empty → tell the user that `roots` must be added to
     projects.json (see agent/README.md) and stop.
   - `local` not null → the project is already set up; ask whether to
     change it (then `--replace` is needed).

2. **Ask which root — always, never default it.** List each root with its
   path and what it usually holds, judging by the path:
   `…/internal/otack` — our projects still in development, not yet promoted
   to internal; `…/internal` — internal products; `…/external` — client
   projects.

3. **Ask the folder name.** Offer the suggested `folder`. A different name
   must be lowercase `[a-z0-9._-]` and meaningful (the product or repo
   name, not "new-project" or "test"). If the chosen path exists with a
   *different* origin, say so and ask for another name.

4. **Ask the rest**, saying what each means:
   - clone the repo (`--clone`, needs `repo_url` in the project) or create
     an empty folder;
   - `--write` — may the bot change files, commit and push task branches
     there (it never pushes the default branch); default no;
   - `--test-cmd` — the command the bot runs before handing over (e.g.
     `make test`); characters `[A-Za-z0-9 _./-]` only; `--test-cmd ""`
     means no test command. Say plainly: the bot may run this command,
     and whatever it executes (a Makefile target, a test script in the
     repo) runs with no sandbox — anything the project's own code does,
     the bot can make it do (agent/README.md, "Known limits");
   - `--in-progress` / `--review` — board columns by exact name or id (list
     the columns from step 1);
   - `--default-branch` — defaults to the project's `default_branch`; a
     branch name must start with a letter or digit and must not contain `..`.

5. **Say plainly when the target already exists.** Before running, show the
   owner which case applies, from the `candidates` row of the chosen root:
   - the path does not exist → a new folder is created (cloned with
     `--clone`, otherwise empty);
   - it exists, is a git checkout with `same_repo` true → it is registered
     as is;
   - it exists with **no git origin** (or is an **empty folder** while the
     project has a `repo_url` but no `--clone`) → it is registered as is,
     with nothing verified against the repo; the owner must accept that;
   - it is a checkout but the project has **no `repo_url`** → it is
     registered **without a repo check**; the owner must accept that;
   - a different origin, a non-empty non-git folder or a symlink → the
     command refuses; pick another folder name.

6. **Show and confirm.** Print the exact command and the entry it will
   write, e.g.

   ```
   {{OTACK_MANAGER}} setup-project 900 --root otack --folder tili-tisto --clone --write --test-cmd "make test" --in-progress "В работе" --review "Review"
   ```
   ```json
   "900": { "path": "/Users/…/internal/otack/tili-tisto", "write": true, "test_cmd": "make test",
            "in_progress_column": "В работе", "review_column": "Review", "default_branch": "main" }
   ```
   Run it only after a yes.

7. **After.** The command prints `{project_id, entry, created_folder,
   cloned}`. Report the folder, whether it was created and cloned, and
   remind: the bot must be a member of the project in Otack Manager and
   the project's `repo_url` must match the folder's origin, or the bot
   blocks the task.
