# Otack Manager — External REST API

Integration guide for third-party clients (CI runners, Zapier-style automations,
internal services, MCP bridges) that want to drive an Otack Manager instance
over HTTP.

This document is the human-readable companion to [`docs/openapi.yaml`](openapi.yaml)
(OpenAPI 3.1.0). Where the two differ, the OpenAPI file is the contract — but
this document is the source of truth for **why** the API is shaped the way it is.

---

## Table of contents

1. [Overview](#1-overview)
2. [Integration setup — the 9-step checklist](#2-integration-setup)
3. [Authentication](#3-authentication)
4. [Request / response conventions](#4-request--response-conventions)
5. [Endpoint reference](#5-endpoint-reference)
6. [End-to-end recipes](#6-end-to-end-recipes)
7. [Rate limiting](#7-rate-limiting)
8. [Security & best practices](#8-security--best-practices)
9. [Versioning & deprecation](#9-versioning--deprecation)
10. [Troubleshooting](#10-troubleshooting)
11. [Reference](#11-reference)

---

## 1. Overview

Otack Manager exposes a versioned REST/JSON API under `/api/v1/`. The surface
mirrors the manager + employee capabilities of the web UI — projects, kanban
columns, tasks, comments, tags, attachments — plus a read-only window onto
forms and polls. Every endpoint returns JSON (with one exception: file uploads
accept `multipart/form-data`).

The API is **not** a replacement for the admin UI. It deliberately does **not**
expose user management, application settings, public-form submission, public
poll voting, short-link redirection, the in-app updater, or Compass. Those have
separate channels (admin web UI, public form URLs, public poll URLs, GitHub
releases). The API talks to authenticated humans-as-services and their
project/task data — nothing else.

`/api/v1/` is a stable contract. Any breaking change (removed field, changed
type, semantically different meaning) ships under a new prefix, e.g. `/api/v2/`.
Additive changes (new optional fields, new endpoints, new error subcodes) ship
inside v1 without notice. See [§9 Versioning](#9-versioning--deprecation).

The machine-readable contract lives at [`docs/openapi.yaml`](openapi.yaml) and
is also served live at `GET /api/v1/openapi.yaml` (no auth, no rate limit) so
clients can pull the schema for the exact instance they're talking to.

Stack note for the curious: the backend is PHP 8.2+ on SQLite or MySQL 8. None
of that is observable from the wire — consumers only see HTTP + JSON.

---

## 2. Integration setup

A nine-step walk to a working integration.

### 2.1 Decide who the integration runs as

**Recommendation:** create a dedicated `employee` user (e.g.
`ci-bot@example.com`) and use **that** account's token. Don't use a personal
admin's token.

Why: tokens in v1 do not carry scopes. A token has exactly the same powers as
the user it was issued for. The only effective scoping lever is **project
membership** — so the service-account pattern lets you limit blast radius by
adding the bot to only the projects it needs to touch.

If the bot is added to projects A and B but not C, it cannot see or write to C
even though its token is technically valid.

### 2.2 Get an API token

1. Log in as the service-account user.
2. Go to **Profile → API tokens** (`/profile/tokens`).
3. Click **Create token**, give it a descriptive label (e.g.
   `"jira-sync"`, `"CI release pipeline"`).
4. The next screen reveals the token **once**, in the form `otk_…`. Copy it.
   Otack stores only a hash — the raw value cannot be retrieved later.

If you lose the token, revoke it and create a new one. No "show me again"
button exists by design.

### 2.3 Store the token securely

- Use an environment variable: `OTACK_API_TOKEN`.
- Or a secrets manager (Vault, AWS Secrets Manager, Doppler, …).
- **Never** commit a token to git or paste it into a chat. The string itself
  is the credential — there is no second factor.
- Strip the `Authorization` header from any logs you write.

### 2.4 Configure the base URL

Per environment:

```bash
# Production
OTACK_API_URL=https://your-otack-host.example.com/api/v1

# Local dev
OTACK_API_URL=http://localhost:8000/api/v1
```

Pin the prefix to `/api/v1` — that's the version boundary. The day you migrate
to v2, only this string changes.

### 2.5 Smoke-test the auth

```bash
curl -sS -H "Authorization: Bearer $OTACK_API_TOKEN" "$OTACK_API_URL/me"
```

Expected output (`200 OK`):

```json
{ "id": 7, "name": "CI Bot", "email": "ci-bot@example.com", "role": "employee", "locale": "en" }
```

Failure modes:

| Symptom | Cause |
|---|---|
| `401 unauthorized` | Token wrong, revoked, expired, or user blocked |
| `404 not_found` | Wrong base URL, or instance routing is broken |
| Connection refused / DNS error | Wrong host, instance down, firewall |

### 2.6 Plan for rate limits

The API allows **60 requests per minute per token** in a sliding window. On
overflow you get `429` plus a `Retry-After` header in seconds. See
[§7 Rate limiting](#7-rate-limiting) for the algorithm and backoff strategy.

The counter is keyed on token id, so independent integrations using
independent tokens get independent budgets, even if they belong to the same
user.

### 2.7 Plan for errors

The response body's `error` field is a machine-stable code; the `message`
field is English prose that may change wording. **Switch on `error`, never on
`message`.** The full set of v1 codes is in [§4](#error-envelope).

For `422 validation_failed` responses, an additional `fields` object lists
which fields failed and why — that's the right place to surface "please fix X"
back to your users.

### 2.8 Idempotency

V1 does not implement an `Idempotency-Key` header. The recommended pattern for
client-side retries:

- **Reads** are naturally idempotent — retry freely.
- **Writes** that you might retry: pre-check (e.g. `GET /projects/{id}/tasks?…`
  to look for an existing matching task) before re-issuing the `POST`. Accept
  that you may occasionally create duplicates and dedupe in your own pipeline.
- One endpoint is **deliberately idempotent by design**:
  `POST /projects/{id}/pin` takes a `pinned: true|false` boolean and applies
  state-not-action — repeated calls converge.

### 2.9 Local development

Spin up a local Otack:

```bash
make dev    # PHP built-in server on :8000
```

Issue a token via `http://localhost:8000/profile/tokens` and point your
integration at `http://localhost:8000/api/v1`. Do not test against production.

---

## 3. Authentication

### 3.1 Token format

```
Authorization: Bearer otk_<random>
```

- Prefix `otk_` is fixed (lets us grep tokens out of pastes).
- The remainder is opaque random — do not parse it.
- This is the only authentication mechanism. No API keys, no OAuth, no HMAC.

### 3.2 What `401 unauthorized` means

Any of (we never distinguish — distinguishing would leak):

- `Authorization` header missing
- header malformed (not `Bearer otk_…`)
- token unknown to this instance
- token revoked
- token expired (if it had an `expires_at`)
- the user behind the token is no longer `status='active'`

If you get a 401 you didn't expect, re-issue at `/profile/tokens`.

### 3.3 Token lifecycle

```
            (admin or owner revokes)
created ────────────────────────────► revoked  ┐
   │                                            ├─► (terminal)
   └───► (optional expires_at passes) ──────────┘
```

A token is born active. It can carry an optional `expires_at` you set at
creation time. Otherwise it lives until revoked. Revocation is terminal — the
same `otk_…` string cannot be re-activated.

### 3.4 Worked example

Request:

```bash
curl -sS \
  -H "Authorization: Bearer $OTACK_API_TOKEN" \
  "$OTACK_API_URL/me"
```

Response (`200 OK`):

```json
{
  "id": 7,
  "name": "Ada Lovelace",
  "email": "ada@example.com",
  "role": "employee",
  "locale": "en"
}
```

---

## 4. Request / response conventions

### 4.1 Content-Type

| Method | Content-Type the API expects |
|---|---|
| `GET` / `DELETE` | none (no body) |
| `POST` / `PATCH` (default) | `application/json; charset=utf-8` |
| `POST /api/v1/attachments` (only) | `multipart/form-data` |

Responses are always `application/json; charset=utf-8` except `204 No Content`
(empty body, no Content-Type).

### 4.2 HTTP methods

- `GET` — read a resource or collection. Idempotent. Safe to retry.
- `POST` — create a resource, or perform a non-idempotent action
  (`/tasks/{id}/move`, `/projects/{id}/columns/reorder`, etc.).
- `PATCH` — partial update. Send only the fields you want to change.
- `DELETE` — destroy.

There are no `PUT` endpoints in v1.

### 4.3 Status codes

| Code | Meaning | Body |
|---|---|---|
| 200 | Success | Resource or list |
| 201 | Created | Created resource |
| 204 | Success, no body | empty |
| 400 | Malformed request | error |
| 401 | Auth failed | error |
| 403 | Forbidden | error |
| 404 | Not found / not visible | error |
| 409 | Conflict (e.g. delete non-empty column) | error |
| 422 | Validation failed | error with `fields` |
| 429 | Rate limited | error + `Retry-After` header |
| 5xx | Server error | error |

Note: `404` is also used when the caller cannot see the resource. This is
deliberate (no "exists but you can't see it" leak).

### 4.4 Error envelope

Every non-2xx response has the same shape:

```json
{
  "error": "validation_failed",
  "message": "title is required",
  "fields": { "title": "required" }
}
```

`fields` is present only on `422`. The `error` codes used in v1:

| `error` | Status | When |
|---|---|---|
| `unauthorized` | 401 | Auth failed (see §3.2) |
| `forbidden` | 403 | Authenticated but the action isn't allowed for this user/role |
| `not_found` | 404 | No such resource, or the caller cannot see it |
| `validation_failed` | 422 | Body or query failed validation; `fields` enumerates |
| `malformed_json` | 400 | Body wasn't parseable JSON on a write endpoint |
| `rate_limited` | 429 | 60-req/min window exceeded — see `Retry-After` |
| `conflict` | 409 | State precondition failed (e.g. column still has tasks) |
| `server_error` | 500 | Unhandled exception in the handler |

### 4.5 Timestamps

All timestamps are ISO-8601 UTC with the `Z` suffix:

```
2026-06-03T14:23:01Z
```

Storage rows that pre-date timestamp normalisation may surface as the raw
underlying string — clients should be robust to either ISO-8601 or
`YYYY-MM-DD HH:MM:SS` and treat both as UTC.

### 4.6 Pagination

List endpoints use **cursor-based pagination on `id`**:

```
GET /api/v1/projects?limit=50&after=42
```

Query params:

| Param | Type | Default | Max |
|---|---|---|---|
| `limit` | int | 50 | 100 |
| `after` | int | 0 | n/a — the cursor from the previous page |

Response:

```json
{
  "items": [ /* … */ ],
  "next_cursor": 91
}
```

When there are no more pages, `next_cursor` is `null`.

**Fetch-all pseudocode:**

```python
cursor = 0
while True:
    r = http.get(f"{url}/projects", params={"limit": 100, "after": cursor},
                 headers={"Authorization": f"Bearer {token}"})
    r.raise_for_status()
    body = r.json()
    yield from body["items"]
    if body["next_cursor"] is None:
        break
    cursor = body["next_cursor"]
```

Note: `GET /api/v1/polls/{id}/voters` returns voters newest-first (highest
id first). It uses the same id-based `?after=` cursor as every other list
endpoint — `next_cursor` is the smallest id in the current page, which you
pass back as `?after=` to fetch the next (older) page.

---

## 5. Endpoint reference

Organised by resource. Within each resource, listed in CRUD order. Every
endpoint registered in `ApiKernel` is documented here. Response shapes are
sourced from the handlers' `serialize*` methods — they match what the wire
delivers byte-for-byte.

Conventions used in the examples below:

```bash
export OTACK_API_URL=http://localhost:8000/api/v1
export OTACK_API_TOKEN=otk_yourtokenhere
```

### 5.1 Meta

#### `GET /ping`

Cheap liveness probe that requires a valid token. Useful for smoke-testing
deployment without the cost of a real query.

**Auth:** any active token.

**Response 200:**

```json
{ "ok": true, "user_id": 7 }
```

**Example:**

```bash
curl -sS -H "Authorization: Bearer $OTACK_API_TOKEN" "$OTACK_API_URL/ping"
```

---

#### `GET /me`

Returns the authenticated user's identity.

**Auth:** any active token.

**Response 200:**

```json
{
  "id": 7,
  "name": "Ada Lovelace",
  "email": "ada@example.com",
  "role": "admin",
  "locale": "en"
}
```

`role` is one of `admin | manager | employee`.

**Example:**

```bash
curl -sS -H "Authorization: Bearer $OTACK_API_TOKEN" "$OTACK_API_URL/me"
```

---

### 5.2 Projects

#### `GET /projects`

List projects visible to the caller.

**Auth:** any active token. Admins see all projects; everyone else sees
projects they're a member of (or originally created).

**Query parameters:**

- `limit` (int, default 50, max 100)
- `after` (int, cursor) — see [§4.6 Pagination](#46-pagination)

**Response 200:**

```json
{
  "items": [
    {
      "id": 42,
      "name": "Website Redesign",
      "slug": "website-redesign",
      "color": "#8B7C68",
      "status": "active",
      "pinned": false,
      "created_at": "2026-06-01T12:00:00Z",
      "updated_at": "2026-06-01T12:00:00Z"
    }
  ],
  "next_cursor": null
}
```

**Example:**

```bash
curl -sS -H "Authorization: Bearer $OTACK_API_TOKEN" \
  "$OTACK_API_URL/projects?limit=100"
```

---

#### `GET /projects/{id}`

Full project detail with columns + members embedded.

**Auth:** caller must be able to see the project (admin, member, or creator).

**Path parameters:**

- `id` (int) — project id.

**Response 200:**

```json
{
  "id": 42,
  "name": "Website Redesign",
  "slug": "website-redesign",
  "color": "#8B7C68",
  "status": "active",
  "pinned": false,
  "created_at": "2026-06-01T12:00:00Z",
  "updated_at": "2026-06-01T12:00:00Z",
  "columns": [
    { "id": 101, "name": "To Do", "position": 0 },
    { "id": 102, "name": "Doing", "position": 1 },
    { "id": 103, "name": "Done",  "position": 2 }
  ],
  "members": [
    { "user_id": 7, "name": "Ada Lovelace", "role": "owner" }
  ]
}
```

**Errors:**

- `404 not_found` — project does not exist, or caller cannot see it.

---

#### `POST /projects`

Create a project. The caller is auto-added as `owner`.

**Auth:** admin or manager (`canCreateProject`).

**Request body:**

```json
{
  "name": "Website Redesign",
  "description": "Optional description",
  "color": "#8B7C68"
}
```

Only `name` is required.

**Response 201:** the project shape (no `columns` / `members` — use
`GET /projects/{id}` to fetch those).

**Errors:**

- `403 forbidden` — caller is an `employee`.
- `422 validation_failed` — `name` missing/empty.

**Example:**

```bash
curl -sS -X POST \
  -H "Authorization: Bearer $OTACK_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"Website Redesign"}' \
  "$OTACK_API_URL/projects"
```

---

#### `PATCH /projects/{id}`

Update mutable project fields. Send only the fields you want to change.

**Auth:** `canEditProject` — admin, manager, or owner-membership.

**Request body** (all optional):

```json
{
  "name": "New name",
  "color": "#A8C8E8",
  "description": "Updated body",
  "status": "archived"
}
```

`status` is one of `active | archived`. Other fields are ignored.

**Response 200:** updated project shape.

**Errors:**

- `403 forbidden` — caller cannot edit.
- `404 not_found` — no such project.

---

#### `DELETE /projects/{id}`

Permanently delete the project.

**Auth:** **admin only.** Managers, owners, and members cannot delete projects
via the API.

**Response 204:** no body.

**Errors:**

- `403 forbidden` — caller isn't admin.
- `404 not_found` — no such project.

---

#### `POST /projects/{id}/pin`

Set the project's pinned state. Idempotent — repeated calls converge.

**Auth:** caller must see the project.

**Request body:**

```json
{ "pinned": true }
```

`pinned` defaults to `false` if omitted.

**Response 200:**

```json
{ "id": 42, "pinned": true }
```

Note: pin state is **global per project**, not per-user — pinning a project
pins it in every member's UI.

---

#### `POST /projects/{id}/members`

Add a user as a project member.

**Auth:** `canEditProject`.

**Request body:**

```json
{ "user_id": 12 }
```

**Response 201:**

```json
{ "project_id": 42, "user_id": 12 }
```

**Errors:**

- `403 forbidden`
- `404 not_found` — no such project
- `422 validation_failed` — `user_id` missing/zero

---

#### `DELETE /projects/{id}/members/{user_id}`

Remove a user from the project.

**Auth:** `canEditProject`.

**Response 204.**

---

### 5.3 Columns

#### `GET /projects/{id}/columns`

List a project's columns in board order.

**Auth:** caller must see the project.

**Response 200:**

```json
{
  "items": [
    {
      "id": 101,
      "project_id": 42,
      "name": "To Do",
      "color": null,
      "position": 0,
      "is_done": false,
      "is_backlog": false
    }
  ]
}
```

`is_done` flags the "Done" terminal column. `is_backlog` flags the backlog
(off-board) column.

---

#### `POST /projects/{id}/columns`

Create a column in a project.

**Auth:** `canEditProject`.

**Request body:**

```json
{ "name": "In review", "position": 2 }
```

Only `name` is required. `position` is optional — if omitted, the new column
goes to the end.

**Response 201:** column shape.

---

#### `PATCH /columns/{id}`

Update a column.

**Auth:** `canEditProject` on the parent project.

**Request body** (all optional):

```json
{ "name": "Renamed", "position": 1, "color": "#A8C8E8" }
```

**Response 200:** updated column shape.

---

#### `DELETE /columns/{id}`

Delete a column.

**Auth:** `canEditProject`.

**Query parameters:**

- `force=true` — if the column still contains tasks, delete them too. Without
  this flag, a non-empty column returns `409 conflict`.

**Response 204** on success.

**Errors:**

- `409 conflict` with `fields: {tasks: "<count>"}` when the column has tasks
  and `force` is not set.

---

#### `POST /projects/{id}/columns/reorder`

Set the absolute order of a project's columns. Idempotent state assignment.

**Auth:** `canEditProject`.

**Request body:**

```json
{ "order": [103, 101, 102] }
```

Every `id` in `order` must belong to the project — foreign ids return
`422 validation_failed`.

**Response 200:** the updated `items` array in new order.

---

### 5.4 Tasks

#### `GET /tasks/{id}`

Fetch a single task with full detail.

**Auth:** caller must see the parent project.

**Response 200:**

```json
{
  "id": 501,
  "project_id": 42,
  "column_id": 101,
  "title": "Write docs",
  "position": 1024.0,
  "priority": "high",
  "assignee_id": 12,
  "due_date": "2026-06-30",
  "sub_status": null,
  "created_at": "2026-06-01T12:00:00Z",
  "updated_at": "2026-06-01T12:00:00Z",
  "description": "Markdown body",
  "created_by": 7,
  "comments_count": 3,
  "attachments_count": 1,
  "tags": [
    { "id": 9, "name": "docs", "color": "#A8C8E8" }
  ],
  "links": [502, 504]
}
```

`links` is the array of other task ids linked to this one (symmetric).

---

#### `GET /projects/{id}/tasks`

List tasks in a project.

**Auth:** caller must see the project.

**Query parameters:**

- `limit` (int, default 50, max 100), `after` (cursor) — pagination
- `column_id` (int) — filter to one column
- `assignee_id` (int) — filter by assignee
- `tag_id` (int) — filter by tag
- `status` (string) — filter by column-status family
- `priority` (one of `none|low|medium|high|urgent`)
- `search` (string) — substring on title/description

**Response 200:** paginated list of the **light** task shape (no description,
no counts, no tags, no links — fetch the single-task endpoint for the rich
shape):

```json
{
  "items": [
    {
      "id": 501,
      "project_id": 42,
      "column_id": 101,
      "title": "Write docs",
      "position": 1024.0,
      "priority": "high",
      "assignee_id": 12,
      "due_date": "2026-06-30",
      "sub_status": null,
      "created_at": "2026-06-01T12:00:00Z",
      "updated_at": "2026-06-01T12:00:00Z"
    }
  ],
  "next_cursor": 501
}
```

---

#### `POST /projects/{id}/tasks`

Create a task in a project.

**Auth:** member of the project, or admin.

**Request body:**

```json
{
  "title": "Write docs",
  "description": "Markdown body, optional",
  "column_id": 101,
  "assignee_id": 12,
  "priority": "high",
  "sub_status": null,
  "tag_ids": [3, 7]
}
```

Only `title` is required. Defaults:

- `column_id` → first non-backlog column by position
- `priority` → `none` (any unknown value is normalised to `none`)

**Response 201:** the rich task shape (same as `GET /tasks/{id}`).

**Errors:**

- `403 forbidden` — caller is not a member.
- `404 not_found` — project does not exist or invisible.
- `422 validation_failed` — `title` empty, `column_id` does not belong to the
  project, project has no columns.

**Example:**

```bash
curl -sS -X POST \
  -H "Authorization: Bearer $OTACK_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"title":"Write docs","column_id":101,"priority":"high"}' \
  "$OTACK_API_URL/projects/42/tasks"
```

---

#### `PATCH /tasks/{id}`

Update mutable task fields.

**Auth:** `canEditTask`.

**Request body** (all optional):

```json
{
  "title": "New title",
  "description": "Updated body or empty string to clear",
  "column_id": 102,
  "assignee_id": null,
  "due_date": "2026-07-15",
  "priority": "medium",
  "sub_status": "blocked"
}
```

- Pass `""` or `null` for `description`, `assignee_id`, `due_date`,
  `sub_status` to clear them.
- `priority` unknowns are coerced to `none`.

**Response 200:** rich task shape.

---

#### `POST /tasks/{id}/move`

Move a task between columns (and/or within a column) by setting a new
`position`.

**Auth:** `canEditTask`.

**Request body:**

```json
{ "column_id": 103, "position": 512.0 }
```

`position` is a float (the board uses sparse floats to avoid renumbering
siblings on every reorder).

**Response 200:** updated task shape.

**Errors:**

- `422 validation_failed` — `column_id` missing, or doesn't belong to the
  task's project.

---

#### `DELETE /tasks/{id}`

Delete a task. Also cleans up any task-link rows pointing to or from it.

**Auth:** `canEditTask`.

**Response 204.**

---

#### `POST /tasks/{id}/promote-to-project`

Create a new project from a task (the task itself stays where it was).

**Auth:** `canEditTask` **and** `canPromoteTaskToProject` (admin / manager).

**Behaviour:**

- Creates a project whose `name` = the task's title, `description` = the
  task's description.
- Adds the caller as `owner` of the new project.
- Seeds the project's default columns.
- Duplicates the task's attachments onto the new project.
- Recreates the task's tags as project-scope tags on the new project.

**Response 200:**

```json
{ "project_id": 88, "url": "/projects/88?tab=overview" }
```

---

#### `POST /tasks/{id}/links`

Create a symmetric link between two tasks.

**Auth:** `canEditTask` on the caller's side. The caller must also be able to
see the **other** task's project (visibility check applies to both).

**Request body:**

```json
{ "other_id": 502 }
```

**Response 201:**

```json
{ "task_id": 501, "other_id": 502, "created": true }
```

`created` is `false` when the link already existed — calls are idempotent.

---

#### `DELETE /tasks/{id}/links/{other_id}`

Remove a link.

**Auth:** `canEditTask`.

**Response 204.**

---

### 5.5 Comments

Comments live on either tasks or projects (`entity_type` discriminator).

#### `GET /tasks/{id}/comments`

List comments on a task.

**Auth:** caller must see the parent project.

**Query parameters:** `limit`, `after` (id cursor).

**Response 200:**

```json
{
  "items": [
    {
      "id": 9001,
      "entity_type": "task",
      "entity_id": 501,
      "user_id": 7,
      "body": "First!",
      "parent_id": null,
      "created_at": "2026-06-01T12:00:00Z",
      "author_name": "Ada"
    }
  ],
  "next_cursor": null
}
```

`body` is raw Markdown — clients render.

---

#### `GET /projects/{id}/comments`

List comments at the project level (not task-level — for those use the task
endpoint).

**Auth:** caller must see the project. Same query params and response shape
as the task variant.

---

#### `POST /comments`

Create a comment.

**Auth:** caller must see the parent project.

**Request body:**

```json
{
  "entity":    "task",
  "entity_id": 501,
  "body":      "Looks good to me",
  "parent_id": 9001
}
```

- `entity` is `"task"` or `"project"`.
- `parent_id` is optional. If supplied, it must reference a comment on the
  same `(entity, entity_id)`; it is normalised to the root of its thread (so
  threads are at most two levels deep).

**Response 201:** the comment shape (same as the list-item shape).

**Errors:**

- `422 validation_failed` — `entity` not in `task|project`, `entity_id`
  missing, `body` empty, or `parent_id` references a different thread.

---

#### `DELETE /comments/{id}`

Delete a comment.

**Auth:** `canDeleteComment` — admin, manager, or the comment's own author.

**Response 204.**

---

### 5.6 Tags

Tags live in a global catalogue. They are attached to projects (project-scope
tag) and to tasks (task-scope tag) via mapping tables.

#### `GET /projects/{id}/tags`

List tags currently attached to a project.

**Auth:** caller must see the project.

**Response 200:**

```json
{
  "items": [
    { "id": 9, "name": "docs", "color": "#A8C8E8", "scope": "project" }
  ]
}
```

---

#### `GET /tags`

List **all** tags in the global catalogue (admin only — non-admins use the
project-scoped variant).

**Auth:** admin.

**Response 200:** same `{items: [Tag]}` shape.

**Errors:**

- `403 forbidden` — non-admin caller.

---

#### `POST /tags`

Create a new tag in the global catalogue (admin only).

**Auth:** admin.

**Request body:**

```json
{ "name": "design", "color": "#A8C8E8", "scope": "task" }
```

- `color` defaults to `"#8B7C68"`.
- `scope` is `"task"` or `"project"`; defaults to `"task"`.

**Response 201:** the tag shape.

---

#### `POST /projects/{id}/tags`

Attach an existing tag to a project.

**Auth:** `canEditProject`.

**Request body:**

```json
{ "tag_id": 9 }
```

**Response 201:**

```json
{ "project_id": 42, "tag_id": 9 }
```

---

#### `DELETE /projects/{id}/tags/{tag_id}`

Detach a tag from a project.

**Auth:** `canEditProject`.

**Response 204.**

---

#### `POST /tasks/{id}/tags`

Attach an existing tag to a task.

**Auth:** `canEditTask`.

**Request body:**

```json
{ "tag_id": 9 }
```

**Response 201:**

```json
{ "task_id": 501, "tag_id": 9 }
```

---

#### `DELETE /tasks/{id}/tags/{tag_id}`

Detach a tag from a task.

**Auth:** `canEditTask`.

**Response 204.**

---

### 5.7 Attachments

The **only** endpoint in v1 that accepts `multipart/form-data`. Everything
else is JSON.

#### `GET /tasks/{id}/attachments`

List attachments on a task.

**Auth:** caller must see the parent project.

**Response 200:**

```json
{
  "items": [
    {
      "id": 12345,
      "entity_type": "task",
      "entity_id": 501,
      "filename": "uploads/2026/06/abc123.png",
      "path": "/uploads/2026/06/abc123.png",
      "original_name": "screenshot.png",
      "mime": "image/png",
      "size": 218411,
      "is_image": true,
      "uploaded_by": 7,
      "created_at": "2026-06-01T12:00:00Z"
    }
  ]
}
```

`path` is a leading-slash variant of `filename`, ready to drop into an `<img>`
or `<a href>` once you've prefixed your instance origin.

---

#### `GET /projects/{id}/attachments`

List attachments at the project level. Same shape as the task variant.

---

#### `POST /attachments`

Upload a file and attach it to a task or project.

**Auth:**

- `canEditTask` if `entity=task`.
- `canEditProject` if `entity=project`.

**Content-Type:** `multipart/form-data`.

**Form fields:**

- `entity` (string) — `"task"` or `"project"`
- `entity_id` (int) — the parent id
- `file` (file) — the file to upload

**Size limits** (server-configurable):

- Images: `UPLOAD_MAX_IMAGE` (default 5 MB)
- Other files: `UPLOAD_MAX_FILE` (default 50 MB)

**Allowed MIME types:**

- Images: `image/jpeg`, `image/png`, `image/gif`, `image/webp`
- Documents: `application/pdf`, MS Office, OpenXML Office, `text/plain`,
  `text/csv`, `application/json`, `application/xml`, `text/xml`
- Archives: `application/zip`, `application/x-7z-compressed`,
  `application/x-rar-compressed`
- Media: `video/mp4`, `audio/mpeg`

`image/svg+xml` is **rejected** (XSS-on-the-canvas surface). Anything else
returns `422 validation_failed`.

**Response 201:** the attachment shape.

**Example:**

```bash
curl -sS -X POST \
  -H "Authorization: Bearer $OTACK_API_TOKEN" \
  -F "entity=task" \
  -F "entity_id=501" \
  -F "file=@./screenshot.png" \
  "$OTACK_API_URL/attachments"
```

---

#### `DELETE /attachments/{id}`

Delete an attachment (also unlinks the file from disk, best-effort).

**Auth:** admin, **or** the original uploader. Not gated by
`canEditProject` / `canEditTask` — this mirrors the web UI: any uploader can
clean up their own files.

**Response 204.**

---

### 5.8 Forms (read-only)

The public forms feature stays public-only for *submission*. The API gives a
read-only management surface — list forms you own, fetch their definitions,
and download submissions.

#### `GET /forms`

List forms.

**Auth:** admin or manager (`canViewFormsData`). Managers see only forms they
created; admins see everything.

**Query parameters:**

- `q` (string) — substring search on title.

**Response 200:** `{items: [Form]}`.

**Form list shape:**

```json
{
  "id": 17,
  "hash": "f_abc123",
  "title": "Contact us",
  "description": null,
  "status": "published",
  "locale": "en",
  "project_id": 42,
  "created_by": 7,
  "created_at": "2026-06-01T12:00:00Z",
  "updated_at": "2026-06-01T12:00:00Z"
}
```

---

#### `GET /forms/{id}`

Full form detail with parsed field definitions.

**Auth:** admin or owner (`canViewFormsData` + creator).

**Response 200:** form list shape plus:

```json
{
  "fields": [ /* JSON-decoded field defs */ ],
  "footer": { /* JSON-decoded footer block */ },
  "auto_create_task": true,
  "task_title_template": "New lead: {{name}}",
  "success_message": "Thanks!"
}
```

---

#### `GET /forms/{id}/submissions`

List submissions to a form.

**Auth:** admin or form owner.

**Query parameters:** `limit`, `after` (id cursor), `status` (string).

**Response 200:**

```json
{
  "items": [
    {
      "id": 9876,
      "form_id": 17,
      "status": "new",
      "created_at": "2026-06-01T12:00:00Z",
      "updated_at": "2026-06-01T12:00:00Z",
      "form_title": "Contact us"
    }
  ],
  "next_cursor": null
}
```

The list shape is light. Use `GET /submissions/{id}` for the parsed answers.

---

#### `GET /submissions/{id}`

Fetch a single submission with its parsed answers and a form snapshot.

**Auth:** admin or form owner.

**Response 200:**

```json
{
  "id": 9876,
  "form_id": 17,
  "status": "new",
  "answers": { "name": "Ada", "email": "ada@example.com" },
  "footer": { "honeypot_ms": 12345 },
  "remote_ip": "203.0.113.4",
  "converted_task_id": 9001,
  "converted_project_id": null,
  "created_at": "2026-06-01T12:00:00Z",
  "updated_at": "2026-06-01T12:00:00Z",
  "form": {
    "id": 17,
    "title": "Contact us",
    "fields": [ /* … */ ]
  }
}
```

---

### 5.9 Polls (read-only)

Same read-only philosophy as Forms.

#### `GET /polls`

List polls.

**Auth:** admin or manager (`canManagePolls`). Managers see only their own
polls.

**Query parameters:**

- `status` (`draft|active|closed`)
- `q` (string) — substring on title.

**Response 200:**

```json
{
  "items": [
    {
      "id": 5,
      "hash": "p_xyz789",
      "title": "Pizza Friday menu",
      "description": null,
      "status": "active",
      "locale": "en",
      "project_id": null,
      "created_by": 7,
      "created_at": "2026-06-01T12:00:00Z",
      "updated_at": "2026-06-01T12:00:00Z",
      "vote_count": 23
    }
  ]
}
```

---

#### `GET /polls/{id}`

Full poll with stats.

**Auth:** admin or owner.

**Response 200:** poll list shape plus:

```json
{
  "fields": [ /* poll field defs */ ],
  "footer": { /* footer block */ },
  "contact_field": "email",
  "success_message": "Thanks for voting!",
  "activated_at": "2026-06-01T12:00:00Z",
  "closed_at": null,
  "summary_task_id": null,
  "stats": {
    "total": 23,
    "options": [
      { "key": "margherita", "label": "Margherita", "count": 12, "pct": 52.2 },
      { "key": "pepperoni",  "label": "Pepperoni",  "count": 11, "pct": 47.8 }
    ]
  }
}
```

`stats.options` always lists every option, including zero-vote ones — useful
for rendering full bars.

---

#### `GET /polls/{id}/voters`

List individual votes for a poll.

**Auth:** admin or owner.

**Query parameters:**

- `limit` (int, default 50, max 200)
- `after` (int) — cursor-by-id, same convention as every other list endpoint.
  Returns rows with id strictly less than `after` (newest-first / id DESC).

**Response 200:**

```json
{
  "items": [
    {
      "id": 33,
      "contact": "ada@example.com",
      "choice_key": "margherita",
      "choice_label": "Margherita",
      "remote_ip": "203.0.113.4",
      "created_at": "2026-06-01T12:00:00Z"
    }
  ],
  "next_cursor": 33
}
```

When `next_cursor` is non-null, pass it back as `?after=...`. When it's null,
you've reached the end.

---

## 6. End-to-end recipes

### 6.1 Sync tasks from an external issue tracker into an Otack project

**Goal:** every new issue created in your external tracker becomes an Otack
task with the right tag.

Discover the target project and its "To Do" column:

```bash
# 1. Find the project
curl -sS -H "Authorization: Bearer $OTACK_API_TOKEN" \
  "$OTACK_API_URL/projects?limit=100" | jq '.items[] | select(.name == "Inbound issues")'
#   → grab .id, say 42

# 2. Find the right column
curl -sS -H "Authorization: Bearer $OTACK_API_TOKEN" \
  "$OTACK_API_URL/projects/42" | jq '.columns[] | select(.name == "To Do")'
#   → grab .id, say 101
```

For each new issue, create a task and attach a tag:

```bash
# 3. Create the task
TASK_JSON=$(curl -sS -X POST \
  -H "Authorization: Bearer $OTACK_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
        "title":       "[EX-1234] Login broken on Safari",
        "description": "Reported by Ada. Repro: …",
        "column_id":   101,
        "priority":    "high"
      }' \
  "$OTACK_API_URL/projects/42/tasks")

TASK_ID=$(echo "$TASK_JSON" | jq '.id')

# 4. Tag it
curl -sS -X POST \
  -H "Authorization: Bearer $OTACK_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"tag_id": 9}' \
  "$OTACK_API_URL/tasks/$TASK_ID/tags"
```

For deduplication, store the external issue id in a comment (or in the task
title prefix `[EX-1234]`) and use `GET /projects/42/tasks?search=EX-1234`
before creating.

### 6.2 Notify chat when a task moves to Done

**Goal:** poll the API and post to Slack/Telegram every time a task lands in
the Done column.

```python
import time, requests, os

URL   = os.environ["OTACK_API_URL"]
TOKEN = os.environ["OTACK_API_TOKEN"]
H     = {"Authorization": f"Bearer {TOKEN}"}

PROJECT_ID = 42
DONE_ID    = 103

cursor = load_cursor() or 0   # persist between runs

while True:
    r = requests.get(
        f"{URL}/projects/{PROJECT_ID}/tasks",
        params={"column_id": DONE_ID, "after": cursor, "limit": 100},
        headers=H,
        timeout=10,
    )
    if r.status_code == 429:
        time.sleep(int(r.headers.get("Retry-After", "30")))
        continue
    r.raise_for_status()
    body = r.json()
    for t in body["items"]:
        notify_slack(f"✅ Task done: {t['title']} (#{t['id']})")
        cursor = max(cursor, t["id"])
    save_cursor(cursor)

    time.sleep(60)
```

Rate-limit math: this poller is one request per minute (well under the
60/min/token budget). You can run ~50 such pollers on a single token before
hitting the cap.

`cursor` here only catches tasks whose id is monotonically increasing — i.e.
brand-new tasks landing in Done. To catch *moves* (existing tasks getting
moved into Done), drive off `updated_at` and the Activity Log instead. The
current API doesn't expose Activity Log directly in v1; check the
[design spec](superpowers/specs/2026-06-03-external-api-design.md) for the
phase-2 plan.

### 6.3 Upload a file attachment

```bash
curl -sS -X POST \
  -H "Authorization: Bearer $OTACK_API_TOKEN" \
  -F "entity=task" \
  -F "entity_id=501" \
  -F "file=@./design-mockup.png" \
  "$OTACK_API_URL/attachments"
```

Response:

```json
{
  "id": 12345,
  "entity_type": "task",
  "entity_id": 501,
  "filename": "uploads/2026/06/abc123.png",
  "path": "/uploads/2026/06/abc123.png",
  "original_name": "design-mockup.png",
  "mime": "image/png",
  "size": 218411,
  "is_image": true,
  "uploaded_by": 7,
  "created_at": "2026-06-03T14:00:00Z"
}
```

The file is now reachable at `https://your-otack-host.example.com/uploads/2026/06/abc123.png`
(prepend the instance origin to `path`).

Size & MIME limits live in the FileUploader service. Defaults:

- `UPLOAD_MAX_IMAGE` — 5 MB
- `UPLOAD_MAX_FILE` — 50 MB

Override per-instance via `.env`. See [`§5.7`](#57-attachments) for the
allowed MIME list.

---

## 7. Rate limiting

### 7.1 Algorithm

Sliding-window counter keyed on `api_tokens.id`:

- **60 requests per 60 seconds**.
- 61st request inside a window returns `429 rate_limited`.
- `Retry-After` header carries the seconds until the window resets.
- Counter is **per token**, not per user — independent integrations on
  independent tokens have independent budgets even when they belong to the
  same user.

### 7.2 Burst intuition

- 60 requests at second 0 → all succeed.
- 61st request at second 0.1 → `429`, `Retry-After: 60`.
- After 60 seconds the window rolls over; first request inside the new
  window resets the count to 1.

### 7.3 Backoff pseudocode

```python
def call(url, **kw):
    while True:
        r = http.request(url, **kw)
        if r.status_code != 429:
            return r
        sleep_s = int(r.headers.get("Retry-After", "30"))
        time.sleep(sleep_s)
```

A naive `time.sleep(Retry-After)` is fine — the limiter resets cleanly, no
jitter is necessary for a single-client poller. For many parallel workers on
the same token, add jitter (e.g. `sleep(retry + random.uniform(0, 5))`) so
they don't all hit the API at the same instant when the window rolls.

### 7.4 Sizing your token

If your integration needs > 60 req/min sustained:

- Split it into multiple service-account users with their own tokens (each
  gets its own 60/min budget), **or**
- Batch reads with `limit=100` to fetch more rows per request, **or**
- Cache reads client-side — `GET /projects` doesn't change every minute.

---

## 8. Security & best practices

### 8.1 Service accounts over personal accounts

A token is bearer-equivalent to the user's session. Issue tokens to *bots*,
not *people*:

- If a person leaves, their account gets disabled and their tokens die with
  it. Your integration breaks.
- If a service account is dedicated to one integration, you can revoke its
  token without disrupting other integrations.
- Membership scope on a service account is also easier to audit ("the
  CI bot only sees `projects/42` and `projects/43`").

### 8.2 No scopes in v1

V1 tokens are user-equivalent — they cannot be narrowed to "read-only" or
"only Project X". Mitigations:

- **Use service accounts** (above).
- **Limit project membership** of the service-account user.
- **Set `role='employee'`** on the service-account user unless you genuinely
  need manager powers (managers can create projects, promote tasks, view
  forms/polls; employees cannot).

Token scopes are on the v2 wishlist.

### 8.3 Rotate tokens on ownership changes

When a person leaves the team:

- Revoke all of their tokens at `/profile/tokens` (or admin's
  `/users/{id}` page).
- If those tokens powered integrations, reissue from a service account.

Rotate proactively (e.g. annually) on long-lived service-account tokens.

### 8.4 HTTPS only in production

The API does not enforce TLS — that's the front proxy's job — but you should:

- Run Otack behind HTTPS in any non-dev environment.
- Set HSTS at the proxy.
- Never use `curl -k` outside local dev.

### 8.5 Don't log tokens

Treat the entire `Authorization` header as a secret. In your client logger:

```python
# Bad
logger.info("request", headers=dict(r.headers))

# Good
safe_headers = {k: v for k, v in r.headers.items() if k.lower() != "authorization"}
logger.info("request", headers=safe_headers)
```

### 8.6 Audit trail

Every authenticated API call is recorded in the `activity_log` table with
`event = "api.<handler>.<action>"`. Admins can inspect the trail at
`/users/{id}` (per-user activity tab) or directly in the database. If a token
gets compromised, the trail is your forensic source.

---

## 9. Versioning & deprecation

### 9.1 Stability promises (v1)

- The URL prefix `/api/v1/` is stable.
- Response field types are stable.
- `error` codes are stable (machine-stable strings).
- Existing fields are never removed inside v1.

### 9.2 What can change inside v1 without notice

- **New endpoints** added under `/api/v1/`.
- **New optional fields** added to existing responses (clients must tolerate
  unknown keys).
- **New optional query parameters** added to existing endpoints.
- **`message` field wording** — translation tweaks, clarifications.

### 9.3 Replacements

If we ever need to replace a field with a differently-named one, we dual-emit
both fields for the rest of v1's life:

```json
{ "old_field": "x", "new_field": "x" }
```

The old field stays until v2.

### 9.4 v2

Breaking changes ship under `/api/v2/`. The OpenAPI spec for v2 will be
published before any v2 endpoint goes live. We will keep v1 running for a
documented sunset window (TBD, expected ≥ 6 months) so consumers have a
migration runway.

---

## 10. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `401` on first call | Token wrong, revoked, or expired | Re-issue via `/profile/tokens` |
| `401` after some time | Token expired | Check `expires_at` in `/profile/tokens` |
| `403` on routes that worked yesterday | User role demoted | Ask admin to restore role |
| `404` on a project you created | Admin removed you from membership | Ask admin to re-add you |
| `422` with `{"fields":{"X":"required"}}` | Missing required field `X` | Add it to the request body |
| `422 column_id does not belong to project` | Cross-project column id | Re-fetch `GET /projects/{id}` columns |
| `409 conflict` deleting a column | Column still has tasks | Pass `?force=true` or move the tasks first |
| `429` once a minute regularly | Polling above 60/min/token | Increase poll interval or split tokens |
| `Connection refused` | Wrong URL or instance down | `curl -I "$OTACK_API_URL"` |
| TLS errors | Self-signed dev cert | `curl -k` in dev only — never in prod |
| Empty `next_cursor` but more data exists | You may be reading a stale snapshot | Re-poll with the latest cursor |
| Comment `parent_id` rejected as invalid | Parent belongs to a different entity | Use a parent comment on the same task/project |

---

## 11. Reference

### 11.1 Machine-readable contract

- **OpenAPI**: [`docs/openapi.yaml`](openapi.yaml). Also served live (no auth)
  at `GET /api/v1/openapi.yaml` — useful when targeting a specific instance.

Load it into your tool of choice:

- **Postman**: *Import → File → openapi.yaml*. Set the collection variable
  `base_url` to your instance origin.
- **Insomnia**: *Create → Import From File → openapi.yaml*.
- **Swagger UI** locally: `npx @apidevtools/swagger-cli serve docs/openapi.yaml`
  or use the [redoc-cli](https://github.com/Redocly/redoc) variant.

### 11.2 Design notes

- [`docs/superpowers/specs/2026-06-03-external-api-design.md`](superpowers/specs/2026-06-03-external-api-design.md)
  — full design spec (token model, dispatch architecture, decisions log).

### 11.3 Companion docs

- [`docs/INTEGRATION-CHECKLIST.md`](INTEGRATION-CHECKLIST.md) — one-page
  checklist for integrators. Bring this to your code review.

### 11.4 MCP bridge (phase 2 placeholder)

A native MCP (Model Context Protocol) server that re-exposes this REST API as
typed MCP tools is on the roadmap but **not in v1**. Until it lands, drive the
API directly via the patterns above.
