# How to use Otack Manager

Welcome to **Otack Manager** — a lightweight project & task management tool for small teams that prefer doing real work over wrangling tooling. This page is your operator's manual: what the app does, how its features fit together, and which methodologies map cleanly onto them.

> If you are setting up the tool for the first time, skip to **Setup essentials** at the bottom. If you are an everyday user, the **Daily workflow** section is where most of the value lives.

---

## What's inside

- **Projects** — long-lived containers for related tasks, with their own board, members, tags and pinned status.
- **Tasks** — the unit of work, lives on a column on the project board, can carry a priority, sub-status, assignees, comments, attachments and links.
- **Tags** — global cross-project labels (per-scope: project, task, knowledge).
- **Polls** — quick async decisions when you don't want a meeting.
- **Forms** — intake or feedback collection with submissions piped into the app.
- **Knowledge** (this page lives here) — Markdown wiki with categories, tags, comments, and explicit version snapshots.
- **Users & roles** — admin / manager / employee, with approval gates on register.
- **Compass** *(admin)* — sysadmin control panel: run migrations, clear caches/sessions, bust asset cache, prune activity log, view DB stats and error logs. Operations-only — not a daily-driver page.

---

## Daily workflow

A healthy day on Otack Manager looks like this:

| Step | Where | What you do |
|------|-------|-------------|
| 1 | **Projects** sidebar | Open your pinned projects — that's your home base. |
| 2 | **Projects → board** | Pull in priorities from the top of "Backlog" or "To do" into "In progress". |
| 3 | **Task page** | Update sub-status, drop comments for context, attach screenshots/files. |
| 4 | **Knowledge** | Capture anything reusable — runbooks, decisions, onboarding tips. |
| 5 | End of day | Move finished tasks to "Done", post a quick standup-style comment on the project. |

This loop is intentionally short. The tool only earns its keep if it stays out of the way of step 2.

---

## Methodologies that fit naturally

Otack Manager is **opinionated about staying flexible** — it doesn't enforce a methodology. Here is how the common ones map onto its primitives.

### 1. Kanban

Best when work arrives unpredictably (support, ops, marketing).

- **Columns** = stages. The default `Backlog → To do → In progress → Review → Done` works for most teams. Customise per project.
- **WIP limits** are not enforced by the app — agree on them socially and call them out in the project description.
- **Priority** on tasks (`P1 / P2 / P3 / P4`) is your signal for what to pull next. Combine with **pinned projects** so the highest-bandwidth boards sit at the top of the sidebar.

```text
Backlog  →  To do  →  In progress  →  Review  →  Done
   ↑                                              │
   └──────── Recurring incoming work ─────────────┘
```

### 2. Scrum-lite (sprint cadence without ceremony overhead)

Best when planning happens in 1-2 week cycles.

- Create a **tag** named `sprint-N` (e.g. `sprint-12`). Apply it to every task that enters the sprint.
- Use **Polls** to vote on scope at the start of the sprint — store the result as a comment on the planning task.
- At the end, run a **Knowledge** retro page (see below) under the `Retros` category. Snapshot it when the team agrees on the final write-up.

### 3. GTD (Getting Things Done)

Best for individuals managing personal load.

- One project = your inbox. Default column `Backlog` is your "captured but not processed" zone.
- Move into `To do` only after you have decided the **next physical action**.
- Use **tags** as contexts (`@home`, `@deep-work`, `@quick-win`).
- Empty your inbox into project boards weekly — that's your weekly review.

### 4. Personal daily list

If methodologies feel heavy, just:

1. Pin one project called `Today`.
2. Each morning move 3-5 tasks into `In progress`.
3. Each evening close them or push them back.

The tool will not nag you. That is by design.

---

## Knowledge base — best practices

The Knowledge module (where you are right now) is for **things you'll need to read more than once**.

- **Categories** are flat. Don't over-engineer — `Engineering / Ops / HR / Sales / Decisions` is plenty for most teams.
- **Tags** are searchable across all knowledge — use them for cross-cutting topics (`security`, `oncall`, `customer-X`).
- **Snapshots** are explicit. Click *Save snapshot* before a major rewrite. Snapshots are read-only history — there is **no automatic restore**, by design.
- **Comments** belong on the page they discuss, not in chat. Future-you will thank you.

### Suggested first pages

```text
Engineering
  ├─ Deploy runbook
  ├─ Local dev setup
  └─ Incident response playbook

Ops
  ├─ Vendor & contact list
  └─ Recurring meeting cadence

HR
  └─ Onboarding checklist
```

---

## Roles & permissions

| Role | Can read | Can edit tasks | Can manage users | Can edit knowledge |
|------|----------|----------------|------------------|---------------------|
| **Admin** | yes | yes | yes | yes (and delete) |
| **Manager** | yes | yes | partial | yes |
| **Employee** | yes (assigned + public) | own tasks | no | comments only |
| **Pending** | nothing | nothing | nothing | nothing |

New registrations land in **Pending** until an admin approves them. This is intentional — it keeps the workspace small and trusted.

---

## Tags — the cheap, powerful cross-cut

Tags work across **projects**, **tasks**, and **knowledge pages**, each in their own scope. A few patterns that pay off:

- `priority:fire` — visible across the whole workspace, not just one project.
- `cust:acme` — every task and doc touching the Acme account, in one filter click.
- `area:billing` — code-area tags help engineers triage faster than reading task titles.

Keep the vocabulary small. A wall of tags is worse than no tags.

---

## Polls — async decisions

Use Polls when:

- The decision affects more than 2 people but doesn't need a meeting.
- You want a paper trail of who voted what.
- The options are countable (yes/no, A/B/C, multiple-choice).

Don't use Polls for open-ended questions — those belong in a task comment or a Knowledge page draft.

---

## Forms — light-weight intake

Forms are good for:

- Bug reports from non-technical teammates.
- Customer feedback links.
- HR / vacation requests.

Each submission turns up under **Forms Data**, where a manager can triage it into a task.

---

## Search

The search box on the Knowledge sidebar matches **title and body**, case-insensitive. Combine with a category in the rail to narrow the result set further. If the team grows large, lean on tags rather than free-text search — they're more precise.

---

## Setup essentials

If you're an admin onboarding the tool:

1. **Create the first project** under `/projects`. Pin it. This becomes the "home" board.
2. **Define your columns**. The default 5 are fine — only change them if you have a reason.
3. **Create core tags** (priorities, areas, customers). Limit to ~15 tags total at first.
4. **Invite your team**. They register, then you approve them under `/users`.
5. **Seed Knowledge** with at least:
   - This onboarding page (already here).
   - A deploy / runbook page.
   - A team norms / "how we work" page.
6. **Set the default locale** under Settings → keep it close to your team's working language.

---

## Quick reference

| Action | Where |
|--------|-------|
| Open the admin control panel | `/admin/compass` |
| Add a task | Project board → `+ Add task` on any column |
| Move a task | Drag & drop on the board |
| Mention someone in a comment | Type `@name` |
| Filter by tag | Tag chip at the top of a list |
| Save a knowledge snapshot | Knowledge page → *Save snapshot* |
| See API tokens | `/profile` → API tokens |

---

> **Remember:** tools amplify habits — good or bad. Otack Manager will not turn a chaotic process into a clean one on its own. Decide *how* you want to work, then use the closest mapping above to shape the app around it.
