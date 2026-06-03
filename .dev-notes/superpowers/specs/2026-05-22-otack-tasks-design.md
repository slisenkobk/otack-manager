# Otack Tasks — Design Spec

**Date:** 2026-05-22
**Status:** Approved for planning
**Source repos referenced:** `otack-cms` (architecture), `otack-assystant` (kanban), `dashboard-editorial-mockup.html` (design system)

---

## 1. Goal

Minimal multi-user project & task manager with a Kanban board per project, threaded discussion on projects and tasks, file/image attachments, role-based auth with admin approval, and Telegram notifications to a single shared channel. Server-rendered, no SPA, no frontend framework.

## 2. Non-goals

- Real-time collaboration (no websockets, no live cursors)
- Per-user Telegram routing (one shared channel only, v1)
- Time tracking, burndown charts, gantt
- Email notifications
- Mobile app
- Public API beyond what the UI itself calls
- i18n (Ukrainian only, v1)

## 3. Tech stack

| Layer | Choice | Rationale |
|---|---|---|
| Runtime | PHP 8.2+ | matches otack-cms baseline |
| DB | SQLite via PDO | zero-deps, file-based fits flat-file philosophy |
| Backend | Procedural-OO, namespaced (`App\…`), manual PSR-4-like autoloader in `system/bootstrap.php` | mirrors otack-cms |
| Composer deps | none | parity with otack-cms |
| Templating | plain PHP files in `views/` via tiny `View\Renderer` | no Twig/Blade |
| Frontend | vanilla JS modules (`<script type="module">`), no bundler | parity with otack-cms |
| Frontend deps | **SortableJS** (kanban DnD) + **FontAwesome 6 Free** (icons), both via CDN with SRI; vendored copies in `public/assets/vendor/` for offline | minimum surface |
| CSS | hand-written single `app.css` driven by CSS variables from dashboard-editorial-mockup; no Tailwind | bundle-less, faithful to mockup |
| Fonts | **Manrope** (400/500/600/700) + **JetBrains Mono** (400/500/700), self-hosted in `public/assets/fonts/` | sans-serif, no italics, no serifs per user req |
| Session | native PHP, files in `data/sessions/` | parity with otack-cms |
| Notifications | Telegram Bot API via `curl_exec`, synchronous | simplest possible |

## 4. Directory layout

```
otack-tasks/
├── public/
│   ├── index.php               # front controller — routes everything
│   ├── .htaccess               # rewrites all non-file requests to index.php
│   ├── assets/
│   │   ├── css/app.css
│   │   ├── js/
│   │   │   ├── ui.js           # Modal/Confirm/Prompt/Toast/Lightbox
│   │   │   ├── kanban.js       # SortableJS init, move API call
│   │   │   ├── comments.js     # post/load comments
│   │   │   ├── attachments.js  # upload, list, delete
│   │   │   └── tags.js         # tag picker
│   │   ├── fonts/              # Manrope*.woff2, JetBrainsMono*.woff2
│   │   └── vendor/{sortable.min.js, fontawesome/*}
│   └── uploads/                # YYYY/MM/{uuid}.{ext} — served directly by Apache
├── system/
│   ├── bootstrap.php           # autoloader + App container + env load
│   ├── Routing/Router.php
│   ├── Http/{Request,Response,Csrf}.php
│   ├── Auth/{AuthManager,SessionManager,PasswordHasher}.php
│   ├── Database/{Connection,SchemaBootstrap}.php
│   ├── Repository/
│   │   ├── UserRepository.php
│   │   ├── ProjectRepository.php
│   │   ├── ProjectMemberRepository.php
│   │   ├── TaskColumnRepository.php
│   │   ├── TaskRepository.php
│   │   ├── TagRepository.php
│   │   ├── CommentRepository.php
│   │   └── AttachmentRepository.php
│   ├── Controller/
│   │   ├── AuthController.php           # login/register/logout
│   │   ├── DashboardController.php
│   │   ├── ProjectController.php        # index/show/create/update/delete
│   │   ├── TaskController.php           # show/create/update/delete/move/reorder
│   │   ├── ColumnController.php         # CRUD for kanban columns
│   │   ├── UserController.php           # admin: list/approve/block/delete
│   │   ├── ProfileController.php        # self profile edit
│   │   ├── CommentController.php
│   │   ├── AttachmentController.php
│   │   └── TagController.php
│   ├── Service/
│   │   ├── TelegramNotifier.php
│   │   ├── FileUploader.php
│   │   ├── Markdown.php                 # minimal: bold/italic-weight/links/code/lists
│   │   └── EventBus.php                 # in-process: fires Telegram notifs
│   └── View/
│       ├── Renderer.php                 # render($template, $data, $layout)
│       └── helpers.php                  # e($s), url(), csrf_field(), icon('user'), …
├── views/
│   ├── layouts/{main.php, auth.php, blank.php}
│   ├── partials/
│   │   ├── sidebar.php
│   │   ├── topbar.php
│   │   ├── modal-root.php               # single template, JS drives content
│   │   ├── toast-root.php
│   │   ├── lightbox-root.php
│   │   ├── comment-thread.php
│   │   ├── attachment-list.php
│   │   └── tag-picker.php
│   ├── auth/{login.php, register.php, pending.php}
│   ├── dashboard/index.php
│   ├── projects/
│   │   ├── index.php                    # card grid in mockup style
│   │   ├── form.php                     # create/edit
│   │   └── show.php                     # kanban + project info sidebar
│   ├── tasks/show.php                   # standalone page, opened in new tab
│   └── users/index.php                  # admin only
├── data/
│   ├── app.sqlite
│   ├── .schema/                         # version markers per table
│   └── sessions/
├── docs/superpowers/{specs,plans}/
├── .env.example                         # APP_URL, APP_DEBUG, TG_BOT_TOKEN, TG_CHAT_ID, UPLOAD_MAX_FILE, UPLOAD_MAX_IMAGE
├── .htaccess                            # blocks /system/, /data/, /.env
└── README.md
```

## 5. Data model

All tables created on first request via `SchemaBootstrap` (parity with otack-cms `SchemaBootstrapTrait`). Marker files in `data/.schema/{table}.{version}` prevent re-runs.

### `users`
```
id           INTEGER PK
email        TEXT UNIQUE NOT NULL
password_hash TEXT NOT NULL                 # password_hash(BCRYPT)
name         TEXT NOT NULL
role         TEXT NOT NULL DEFAULT 'member' # 'admin' | 'member'
status       TEXT NOT NULL DEFAULT 'pending'# 'pending' | 'approved' | 'blocked'
created_at   TEXT NOT NULL                  # ISO8601
last_login_at TEXT
```
- First registered user is auto-promoted to `admin` + `approved`.
- Login allowed only when `status='approved'`.

### `projects`
```
id          INTEGER PK
name        TEXT NOT NULL
slug        TEXT UNIQUE NOT NULL    # for nicer URLs: /projects/{id}-{slug}
description TEXT                     # markdown
status      TEXT NOT NULL DEFAULT 'active' # 'active' | 'archived' | 'done'
created_by  INTEGER NOT NULL REFERENCES users(id)
created_at  TEXT NOT NULL
updated_at  TEXT NOT NULL
```

### `project_members`
```
project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE
user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE
role       TEXT NOT NULL DEFAULT 'member'   # 'owner' | 'member' — owner can delete project
PRIMARY KEY(project_id, user_id)
```

### `task_columns`
```
id         INTEGER PK
project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE
name       TEXT NOT NULL
color      TEXT NOT NULL DEFAULT '#8B7C68'   # hex
position   INTEGER NOT NULL
is_done    INTEGER NOT NULL DEFAULT 0        # marks completion column (for dashboard counters)
```
- On project create: seed **3 default columns** — `To Do` (color `#5A4E3F`, pos 0), `In Progress` (color `#C2410C`, pos 1), `Done` (color `#4D6840`, pos 2, `is_done=1`).
- Fully editable per project: rename, recolor, reorder, add, delete (cannot delete if tasks present; UI offers "move tasks to…" select).

### `tasks`
```
id          INTEGER PK
project_id  INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE
column_id   INTEGER NOT NULL REFERENCES task_columns(id)
title       TEXT NOT NULL
description TEXT                                  # markdown
position    REAL NOT NULL                          # float for cheap reorder
assignee_id INTEGER REFERENCES users(id)
due_date    TEXT
created_by  INTEGER NOT NULL REFERENCES users(id)
created_at  TEXT NOT NULL
updated_at  TEXT NOT NULL
```
- `position` uses `REAL` and midpoint insertion (between neighbours) — no global re-numbering on each drop. Periodic rebalance not needed for expected scale.

### `tags` (shared table)
```
id    INTEGER PK
scope TEXT NOT NULL              # 'project' | 'task'
name  TEXT NOT NULL
color TEXT NOT NULL DEFAULT '#8B7C68'
UNIQUE(scope, name)
```

### `project_tag_map` / `task_tag_map`
```
project_id INTEGER, tag_id INTEGER, PK(project_id, tag_id)
task_id    INTEGER, tag_id INTEGER, PK(task_id, tag_id)
```

### `comments`
```
id          INTEGER PK
entity_type TEXT NOT NULL    # 'project' | 'task'
entity_id   INTEGER NOT NULL
user_id     INTEGER NOT NULL REFERENCES users(id)
body        TEXT NOT NULL    # markdown
created_at  TEXT NOT NULL
INDEX(entity_type, entity_id, created_at)
```

### `attachments`
```
id            INTEGER PK
entity_type   TEXT NOT NULL    # 'project' | 'task' | 'comment'
entity_id     INTEGER NOT NULL
filename      TEXT NOT NULL    # stored path relative to public/uploads/
original_name TEXT NOT NULL
mime          TEXT NOT NULL
size          INTEGER NOT NULL
is_image      INTEGER NOT NULL DEFAULT 0
uploaded_by   INTEGER NOT NULL REFERENCES users(id)
created_at    TEXT NOT NULL
INDEX(entity_type, entity_id)
```

### `notifications_log`
```
id        INTEGER PK
event     TEXT NOT NULL
payload   TEXT NOT NULL    # JSON
ok        INTEGER NOT NULL
error     TEXT
sent_at   TEXT NOT NULL
```

## 6. Routing

`public/.htaccess` rewrites everything to `index.php`. Router resolves the path to `{Controller, action}` and dispatches. Single router for HTML pages and `/api/*` JSON endpoints.

| Method | Path | Controller | Notes |
|---|---|---|---|
| GET | `/login` | Auth.loginForm | guest |
| POST | `/login` | Auth.login | |
| GET | `/register` | Auth.registerForm | guest |
| POST | `/register` | Auth.register | creates `pending` user |
| GET | `/pending` | Auth.pending | shown after register |
| POST | `/logout` | Auth.logout | |
| GET | `/` | Dashboard.index | requires `approved` |
| GET | `/projects` | Project.index | |
| GET | `/projects/new` | Project.createForm | |
| POST | `/projects` | Project.create | seeds 3 default columns |
| GET | `/projects/{id}` | Project.show | kanban + sidebar |
| GET | `/projects/{id}/edit` | Project.editForm | owner/admin |
| POST | `/projects/{id}` | Project.update | |
| POST | `/projects/{id}/delete` | Project.delete | owner/admin |
| GET | `/tasks/{id}` | Task.show | standalone page |
| POST | `/projects/{id}/tasks` | Task.create | from quick-add or modal |
| POST | `/tasks/{id}` | Task.update | |
| POST | `/tasks/{id}/delete` | Task.delete | |
| GET | `/users` | User.index | admin only |
| POST | `/users/{id}/approve` | User.approve | admin |
| POST | `/users/{id}/block` | User.block | admin |
| POST | `/users/{id}/role` | User.setRole | admin |
| GET | `/profile` | Profile.show | |
| POST | `/profile` | Profile.update | |
| **API (JSON, CSRF-protected)** | | | |
| POST | `/api/tasks/{id}/move` | Task.move | `{column_id, position}` |
| POST | `/api/columns` | Column.create | |
| POST | `/api/columns/{id}` | Column.update | rename/recolor/reorder |
| POST | `/api/columns/{id}/delete` | Column.delete | requires `move_to` if has tasks |
| POST | `/api/comments` | Comment.create | `{entity_type, entity_id, body}` |
| POST | `/api/comments/{id}/delete` | Comment.delete | author or admin |
| POST | `/api/attachments` | Attachment.upload | `multipart/form-data` |
| POST | `/api/attachments/{id}/delete` | Attachment.delete | |
| POST | `/api/tags` | Tag.create | |
| POST | `/api/projects/{id}/members` | Project.addMember | owner/admin |
| POST | `/api/projects/{id}/members/{userId}/delete` | Project.removeMember | |

All `POST` mutations require `X-CSRF-Token` header (AJAX) or `_csrf` form field; mismatched → 419.

## 7. Auth flow

1. **First-run:** schema bootstrap creates tables. On `POST /register` if `users` is empty, the new user is `role=admin, status=approved` automatically (avoids chicken-and-egg).
2. **Subsequent registrations:** `status=pending`. Login attempt by `pending` user → flash error, redirected to `/pending` with message + Telegram notification of new pending user.
3. **Admin approval:** `/users` page shows pending users with `Approve` / `Block` / `Delete` buttons (custom confirm modals — no native dialogs).
4. **Brute force:** failed attempts counted in session per email; ≥5 within 15 min → throttle (HTTP 429) for 15 min. (Session-scoped is intentionally simple; we accept the trade-off.)
5. **Passwords:** `password_hash($pw, PASSWORD_BCRYPT)`. Minimum 8 chars on registration.
6. **Sessions:** native PHP, `session.cookie_httponly=1`, `cookie_samesite=Lax`. Session lifetime 12h sliding.
7. **CSRF:** one token per session, regenerated on login. Hidden field in every form, `X-CSRF-Token` header on every fetch.

## 8. Kanban behaviour (project show page)

Layout: horizontal scroll columns. Each column = header (color dot + name + count + `+` add) + sortable list of cards + footer quick-add.

```html
<div class="kanban">
  <div class="kanban-col" data-column-id="3">
    <div class="kanban-col-head">…</div>
    <div class="kanban-list" data-column-id="3">
      <a class="kanban-card" data-task-id="42" href="/tasks/42" target="_blank" rel="noopener">…</a>
      …
    </div>
    <form class="kanban-quickadd" data-column-id="3">…</form>
  </div>
  …
</div>
```

- Card is rendered as `<div class="kanban-card" data-task-id="…" data-task-url="/tasks/…">`. A pointerdown handler records `(x, y)`; on pointerup, if movement is < 5px, we call `window.open(url, '_blank', 'noopener')`. SortableJS handles real drags above that threshold. Using a `<div>` instead of `<a>` avoids the "click after drag triggers navigation" footgun entirely and still gives us middle-click via a separate `auxclick` handler that opens the same URL.
- **SortableJS** config: `group: 'kanban'`, `animation: 150`, `ghostClass: 'kanban-ghost'`, `dragClass: 'kanban-dragging'`.
- **Move API:** `POST /api/tasks/{id}/move` with `{column_id, position}` where `position` is computed client-side as midpoint between neighbours' `position` values (or `prev+1` / `next-1` at edges). Server validates and writes.
- **Optimistic update with rollback:** on network/server error → put card back to original list+index, show toast.
- **Quick-add:** inline form in column footer; Enter submits, Esc cancels; new task appended at bottom.
- **Column management:** gear icon on column header → modal with rename/color/delete + a `+` button at end of board to add a new column.

## 9. Task page (`/tasks/{id}`)

Standalone page using `layouts/main.php` (same shell as the rest). Two-column layout:

- **Left (main):** breadcrumb `Project / Task #id`, editable title (click-to-edit), markdown description with edit toggle, **attachments grid** (images render as 120px thumbs in a grid → click opens lightbox; non-images render as icon + filename + size + download/delete), **comments thread** (chronological, newest at bottom, post form pinned at bottom).
- **Right (meta sidebar):** column/status select, assignee select (project members only), due date, tags multi-select, created-by + created-at, delete button (with confirm).

Saves are AJAX with toast feedback. No full page reloads on field changes.

## 10. Project page (`/projects/{id}`)

Same shell as task page; tabs at top:

- **Board** (default): kanban as in §8.
- **Overview:** project name (h1), markdown description with edit, members list with add/remove, tags, attachments, comments — same components as task page.

## 11. Comments

- Markdown rendered via `Service\Markdown`, hand-rolled minimal subset: `**bold**` → `<strong>`, inline `` `code` ``, fenced ` ``` ` code blocks, `[text](url)` links (URLs validated to `http:`/`https:`/`mailto:` only), unordered (`- `) and ordered (`1. `) lists, blank-line paragraph breaks. **No italic syntax** — `*single-asterisk*` is rendered literally. No raw HTML; input is `htmlspecialchars`'d first, then transformed.
- Edit: author can delete within the UI (no edit in v1 — simpler). Admin can delete any.
- File attachment on a comment: drag-drop onto the comment composer attaches; the new attachment is linked with `entity_type='comment'` and shown inline.
- **Same component reused** for project comments and task comments via `entity_type` + `entity_id`.

## 12. Attachments

- Storage: `public/uploads/YYYY/MM/{uuid}.{original_ext}` (kept extension for content-type sniffing safety; mime stored separately and validated by `finfo`).
- Limits (from `.env`):
  - `UPLOAD_MAX_IMAGE = 5MB` for `image/*` mime types
  - `UPLOAD_MAX_FILE  = 50MB` for everything else
  - rejected with toast on the client (pre-check) and 422 on server
- Allowed images: `image/jpeg`, `image/png`, `image/gif`, `image/webp`, `image/svg+xml` (svg sanitised by stripping `<script>` / `on*` attributes server-side; or — to stay zero-dep — we reject SVG in v1 for simplicity and revisit if needed). **Decision:** SVG **disallowed** in v1.
- Images get a thumbnail-on-render via `<img loading="lazy" style="max-height:120px">`; full image opens in **lightbox** (vanilla, ~60 LOC: dim backdrop, click to close, Esc closes, arrow keys navigate within current entity's image set).
- Delete: uploader or admin. File removed from disk on delete.
- `.htaccess` in `public/uploads/` sets `Content-Disposition: attachment` for non-image mime types via `<FilesMatch>` and disables PHP execution.

## 13. Tags

- Two scopes: `project` tags (attached to projects) and `task` tags (attached to tasks). Separate registries because semantics differ.
- Tag picker: input with autocomplete from existing tags of that scope; Enter or click adds; backspace removes last; click on chip's × removes.
- Admin can rename/delete tags from a `/admin/tags` page (deferred to v1.1 — for v1 tags are created inline and live forever).

## 14. Telegram notifications (single shared channel)

- Config: `.env` → `TG_BOT_TOKEN`, `TG_CHAT_ID`. If either missing → notifier no-ops and logs `skipped` in `notifications_log`.
- One method: `TelegramNotifier::send(string $text, ?string $url = null): bool` — sends `sendMessage` with `parse_mode=HTML`, disables web preview, appends `\n\n<a href="…">Open</a>` if URL provided.
- Triggered by `EventBus` from controllers **after commit** (so failures don't roll back app state). Synchronous `curl`, 3-second timeout, retry-once on network error. Result logged to `notifications_log`.
- Events:
  - `user.registered` — "🆕 Registration request: {name} <{email}> — awaiting approval" (we do **not** use emoji per user constraint — replaced with text prefix `[NEW]`) → **"[NEW] Registration request: …"**
  - `user.approved` — "[USER] {name} approved by {admin}"
  - `project.created` — "[PROJECT] {creator} created '{name}'" + link
  - `project.updated` (name/description/status change only) — "[PROJECT] {actor} updated '{name}'" + link
  - `task.created` — "[TASK] {creator} added '{title}' to {project}" + link to task
  - `task.status_changed` — "[TASK] {actor} moved '{title}' → {newColumn}" + link
  - `task.assignee_changed` — "[TASK] {actor} assigned '{title}' to {assignee}" + link
  - `comment.created` — "[COMMENT] {author} on {project|task} '{name}': {first 200 chars}" + link
- No emojis anywhere in messages (per spec: FontAwesome in UI, plain text in TG).

## 15. Design system adaptation

Adopted from `dashboard-editorial-mockup.html` as CSS variables in `app.css`:

```css
:root {
  --paper: #F5F2EC; --paper-2: #EDE7DC; --paper-3: #E2D9C5;
  --ink:   #1A1612; --ink-2:   #5A4E3F; --ink-3:   #8B7C68;
  --rule:  #D7CFBF; --rule-2:  #C5B99F;
  --brand: #C2410C; --brand-2: #9A2F06; --brand-3: #FCE4D6;
  --green: #4D6840; --red: #B23A2B; --blue: #2E5A88;
  --shadow:      0 1px 0 #0001, 0 2px 8px #0000000c, 0 24px 48px -24px #00000018;
  --shadow-card: 0 1px 0 #FFFFFF55 inset, 0 2px 0 #D7CFBF55, 0 18px 40px -28px #00000022;
  --shadow-pop:  0 18px 40px -16px #00000028, 0 2px 0 #00000010;
  --font-sans: 'Manrope', system-ui, sans-serif;
  --font-mono: 'JetBrains Mono', ui-monospace, monospace;
}
body { font-family: var(--font-sans); background: var(--paper); color: var(--ink); }
```

**Differences from mockup:**
- **Manrope** replaces Fraunces everywhere. Weights: body 400, UI labels 500, emphasis 600, headings 700.
- **No italic anywhere** — every `font-style: italic` from the mockup becomes `font-weight: 600` and (where the mockup used italics for the brand colour) `color: var(--brand)`.
- **No dot-pattern background** — body is plain `var(--paper)`. The `radial-gradient` rule from the mockup is omitted.
- Grain SVG overlay is kept (subtle paper texture, no dots).
- Icons: FontAwesome 6 Free `<i class="fa-solid fa-…">` everywhere; arrow glyphs (`→ ↗ ▾`) from the mockup → `fa-arrow-right`, `fa-arrow-up-right-from-square`, `fa-chevron-down`.
- Corner tags (`P · 01`, `01 / 04`) and mono kickers are kept for the editorial feel.

Reused mockup components (re-skinned to sans):

| Mockup element | Reuse in Otack Tasks |
|---|---|
| Sidebar with marker `01..06` | left nav: Dashboard, Projects, Users (admin), Profile |
| Topbar with seal + crumb + pills | global topbar; crumb shows current section/entity |
| Card grid with corner-tags | projects list |
| Section heads with `num/title/rule/meta` | section dividers on dashboard |
| `.brief` textarea card | reused for description editors and new-project form |
| `.submit` solid black CTA | primary buttons (Submit / Save) |
| `.plan-strip` dark info strip | reused for system-level info banners (e.g. "Awaiting approval") |

## 16. UI primitives (`assets/js/ui.js`)

Single global `UI` object — pure vanilla, no deps:

```js
UI.modal({ title, body, actions: [{label, variant, onClick}] }) → { close() }
UI.confirm(message, { confirmLabel?, danger? }) → Promise<boolean>
UI.prompt(message, { default?, placeholder? }) → Promise<string|null>
UI.toast(message, type) // type: 'info' | 'success' | 'error'
UI.lightbox(images, startIndex)
```

- Single `<div id="modal-root">` and `<div id="toast-root">` in `layouts/main.php`.
- Native `alert/confirm/prompt` are **forbidden** (parity with otack-assystant rule).
- Toasts auto-dismiss in 4s, click to dismiss.
- Modal: backdrop click + Esc close, focus trap, restore focus on close.

## 17. Dashboard

Sections, top to bottom:
1. **Hero kicker** "Welcome back, {name}" + small stats: open projects, my open tasks, unread comments-count (last 7 days).
2. **My tasks** — cards (max 6, link to task page) — title, project, due date, column.
3. **Recent projects** — same `.card` grid as `/projects`, 3 latest by `updated_at`.
4. **Recent activity** — last 10 comments + status moves across projects user is a member of, mono list with timestamps.

## 18. Error handling & logging

- 4xx → render small error page using `auth.php` layout with FA icon + message + back link.
- 5xx → render generic "Something broke" page; full trace written to `data/errors.log` (rotated nightly via simple size check on write — keep last 5MB).
- Form validation: server-side per field; on POST failure re-render the form with errors map + flash old input.
- AJAX errors: 4xx/5xx JSON `{error: string}` → `UI.toast(error, 'error')` automatically by a fetch wrapper in `assets/js/ui.js`.

## 19. Security checklist

- All output escaped via `e()` helper unless explicitly marked safe (markdown render).
- All POST mutations CSRF-checked.
- File uploads: mime sniffed with `finfo`, extension whitelisted, size capped, stored outside the executable PHP path (still inside `public/uploads/` for direct serving, but with `.htaccess` disabling PHP exec).
- Auth required for everything except `/login`, `/register`, `/pending`.
- Admin-only routes guarded by `$user->role === 'admin'`.
- Project visibility: the `/projects` index shows **only projects the current user is a member of** (admins see all). Project show, task page, comments, attachments, kanban API — all require membership (or admin role). 403 page if not a member.
- Passwords never logged; Telegram payloads sanitised (no message bodies > 200 chars logged in payload column).
- `.htaccess` blocks direct access to `/system`, `/data`, `/.env`, `/docs`.
- `Content-Security-Policy` header: `default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; font-src 'self'; connect-src 'self'`. FontAwesome and SortableJS are vendored (no CDN at runtime) to keep CSP tight.

## 20. Build sequence (phased)

1. **Skeleton:** `bootstrap.php`, autoloader, Router, Connection, SchemaBootstrap, layouts shell with design tokens. Smoke: visit `/` returns hello.
2. **Auth:** users table, register/login/logout, pending flow, first-user-admin, CSRF, session, brute-force throttle.
3. **Layout chrome:** sidebar, topbar, dashboard placeholder, `UI.modal/confirm/prompt/toast/lightbox`.
4. **Users admin:** `/users` page, approve/block/delete, role toggle.
5. **Profile:** `/profile` self-edit (name, password change).
6. **Projects CRUD:** list / create / edit / delete; member management.
7. **Columns + Kanban:** seed 3 defaults on project create, column CRUD via modal, SortableJS DnD with optimistic move + rollback.
8. **Task page:** `/tasks/{id}` with editable title/description/meta + delete.
9. **Comments:** shared component for project + task, markdown render, delete.
10. **Attachments:** upload (50MB / 5MB images), grid render, lightbox for images, delete.
11. **Telegram notifier + EventBus:** wire all events listed in §14.
12. **Tags:** picker, project + task scopes.
13. **Dashboard:** real stats and lists.
14. **Polish:** error pages, CSP, vendoring FontAwesome + SortableJS, README, `.env.example`, manual QA checklist.

## 21. Out-of-scope confirmations (explicit, to prevent scope creep)

- No real-time updates (no polling, no SSE). Kanban shows whatever was on the page at load; user refreshes if needed.
- No per-user TG; one channel.
- No email.
- No editing of comments (delete-and-repost).
- No SVG uploads in v1.
- No i18n; UI strings inline in Ukrainian.

## 22. Open decisions deferred to implementation

- Exact Markdown subset rules — refine when wiring `Service\Markdown`.
- Whether `Service\Markdown` needs a tiny dependency or stays hand-rolled. Default: hand-rolled, ~150 LOC. Re-evaluate if it grows.
- Pagination on dashboard activity list — start with simple `LIMIT 20`, add "load more" later if needed.
