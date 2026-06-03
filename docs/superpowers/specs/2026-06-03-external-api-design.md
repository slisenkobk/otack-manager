# External REST API + Token Auth — Design

**Status:** Draft for implementation
**Date:** 2026-06-03
**Tracks:** TODO #2
**Related:** TODO #1 (MySQL support — migrations must be portable), Project Review 2026-06-02 (login throttle + session regen items, RateLimiter is reusable for both)

---

## 1. Goal

Expose a third-party HTTP+JSON API that mirrors what manager and employee roles can do in the web UI, authenticated by per-user API tokens. The API is the single contract for all programmatic integrations (CI, automation, MCP bridges). It is **not** Claude-specific — the MCP-for-Claude angle is a separate consumer described in §11.

## 2. Non-goals (v1)

- User management (create / approve / block / role change) — admin-only, stays web-only.
- Settings, Compass, Updater endpoints.
- Public form submission (already has its own honeypot + HMAC path under `/f/{hash}`).
- Webhooks, server-sent events, WebSockets.
- OAuth / third-party SSO.
- Per-token permission scopes (token = owner's full role; see §3).

## 3. Token model

| Property | Decision |
|---|---|
| Cardinality | **Multi-token per user.** Each token has a user-supplied label ("Claude MCP", "GitHub Action"). |
| Identity | Token authorizes **as the owner**, inheriting the owner's current role at request time (no caching — role demotion takes effect on next call). |
| Format | `otk_` prefix + 32 random bytes encoded as base62 (≈43 chars total). |
| Storage | SHA-256 hash of the full token, stored hex. Plus a display prefix (`otk_` + first 8 chars of the random part) for the UI list. The full token is shown to the user **exactly once** on creation and never recoverable. |
| Expiry | Optional, user-set on creation (`expires_at`, nullable). No default expiry. |
| Revocation | Soft delete: `revoked_at` set, row kept for audit. A revoked token returns 401. |
| Scopes | **None in v1.** Token = full role. Users wanting limited integrations create a dedicated employee-role user with limited project membership, then issue a token bound to that user. Documented as the recommended pattern. |

### TODO-text interpretation

The TODO says "вместе с юзером должен создаваться апи токен". Literal interpretation conflicts with the one-time-reveal rule (a token created at registration would never be seen by the user). Adopted interpretation: the **capability** to create tokens is available from the moment the user exists, and a banner on `/profile` invites first-time users to create one. No token is auto-created.

## 4. Transport & contract

- **Base path:** `/api/v1/`. Version is in the URL. Future versions get their own prefix.
- **Auth header:** `Authorization: Bearer otk_…`. No query-param tokens (leak via logs / Referer). Missing or malformed header → 401.
- **Request body:** `Content-Type: application/json` for POST/PATCH/DELETE-with-body. Form-encoded uploads accepted **only** on attachment endpoints (multipart/form-data).
- **Response body:** always JSON, even for errors.
- **CSRF:** the global `_csrf` check in `public/index.php` skips `/api/v1/*`. Bearer auth replaces CSRF for this surface.
- **CORS:** off by default. Add an env flag `API_CORS_ORIGINS` (comma-separated) if needed later; not in v1 scope.
- **Method semantics:**
  - `GET` — list / read.
  - `POST` — create or perform action.
  - `PATCH` — partial update.
  - `DELETE` — destroy.
- **HTTP codes:**
  - 200 — success with body. 201 — created. 204 — success, no body.
  - 400 — malformed JSON or missing required input. 401 — auth. 403 — RolePolicy denies. 404 — not found / not visible to caller. 409 — conflict (e.g., position collision). 422 — validation. 429 — rate limited. 5xx — server error (logged).

### Error envelope (uniform)

```json
{
  "error": "validation_failed",
  "message": "Title is required",
  "fields": { "title": "required" }
}
```

`error` is a stable machine code; `message` is human-readable English; `fields` is present only on 422.

### Pagination

Cursor-based on `id`:

```
GET /api/v1/tasks?project_id=12&after=1234&limit=50
→ { "items": [...], "next_cursor": 1290 }   // null when exhausted
```

`limit` defaults to 50, max 100. Cursor is opaque to the client (currently the id of the last item, but document it as opaque to leave room).

### Timestamps

ISO-8601 UTC strings, e.g. `2026-06-03T12:34:56Z`. Inputs accept the same.

## 5. Endpoint inventory (v1)

All paths are under `/api/v1/`. RolePolicy enforced on every call.

### `/me`
- `GET /me` — `{ id, name, email, role, locale }`.

### Projects
- `GET /projects` — list visible to caller. Filter: `?pinned=true`.
- `POST /projects` — create. Body: `{ name, color?, description? }`. Manager+admin only.
- `GET /projects/{id}` — single project with columns + member list.
- `PATCH /projects/{id}` — update fields. Manager+admin (or owner per RolePolicy).
- `DELETE /projects/{id}` — admin only.
- `POST /projects/{id}/pin` — set pin state explicitly. Body: `{ pinned: bool }`. (The web UI uses a toggle endpoint; the API takes the explicit state so retries are idempotent.)
- `POST /projects/{id}/members` — add member. Body: `{ user_id }`. Admin/manager.
- `DELETE /projects/{id}/members/{user_id}` — remove member.

### Columns
- `GET /projects/{id}/columns` — list (in project payload too, this is for refresh).
- `POST /projects/{id}/columns` — create. Body: `{ name, position? }`.
- `PATCH /columns/{id}` — rename, change position.
- `DELETE /columns/{id}` — delete (rejected if non-empty unless `?force=true`).
- `POST /projects/{id}/columns/reorder` — bulk reorder. Body: `{ order: [id, id, ...] }`.

### Tasks
- `GET /tasks/{id}` — single task with comments count, attachments count, tags, links.
- `GET /projects/{id}/tasks` — list, paginated. Filters: `column_id`, `assignee_id`, `tag_id`, `status`, `priority`, `search` (LIKE on title).
- `POST /projects/{id}/tasks` — create. Body: `{ title, description?, column_id?, assignee_id?, priority?, sub_status?, tag_ids? }`.
- `PATCH /tasks/{id}` — update fields.
- `POST /tasks/{id}/move` — move between columns / reorder. Body: `{ column_id, position }`.
- `DELETE /tasks/{id}` — delete.
- `POST /tasks/{id}/promote-to-project` — same semantics as web UI.
- `POST /tasks/{id}/links` — link to another task. Body: `{ other_id }`.
- `DELETE /tasks/{id}/links/{other_id}` — unlink.

### Comments
- `GET /tasks/{id}/comments` — list, paginated.
- `GET /projects/{id}/comments` — list, paginated (project-level comments).
- `POST /comments` — create. Body: `{ entity: "task"|"project", entity_id, body, parent_id? }`. Stored Markdown; rendered server-side wherever the web UI renders it.
- `DELETE /comments/{id}` — delete (author or admin per RolePolicy).

### Tags
- `GET /projects/{id}/tags` — list project tags.
- `GET /tags` — list global tag catalogue (admin only).
- `POST /tags` — create global tag. Admin only.
- `POST /projects/{id}/tags` — attach existing tag to project. Body: `{ tag_id }`.
- `DELETE /projects/{id}/tags/{tag_id}` — detach.
- `POST /tasks/{id}/tags` — attach to task. Body: `{ tag_id }`.
- `DELETE /tasks/{id}/tags/{tag_id}` — detach.

### Attachments
- `GET /tasks/{id}/attachments` — list.
- `GET /projects/{id}/attachments` — list.
- `POST /attachments` — upload. **multipart/form-data**: `entity`, `entity_id`, `file`. Server enforces MIME + size via existing `FileUploader`.
- `DELETE /attachments/{id}` — delete (also unlinks file from disk).

### Forms (read-only)
- `GET /forms` — list.
- `GET /forms/{id}` — definition + schema.
- `GET /forms/{id}/submissions` — paginated submissions. Filter: `status`, `after`, `limit`.
- `GET /submissions/{id}` — single submission with answers.

### Polls (read-only)
- `GET /polls` — list.
- `GET /polls/{id}` — definition + stats (vote counts per option).
- `GET /polls/{id}/voters` — paginated voter list (admin/manager only).

### Schema discovery
- `GET /openapi.yaml` — serves `docs/openapi.yaml` as `application/yaml`. **Public, no auth** (schema is not data; documents how to authenticate).
- `GET /api/v1/openapi.yaml` — alias for the same.

## 6. Architecture

New top-level `Api/V1` namespace, parallel to the existing web controllers. **No tunneling through `App\Controller\*`** — those are HTML/session-bound.

```
system/
  Api/
    V1/
      ApiKernel.php           # router for /api/v1/*, dispatches to handlers
      TokenAuthenticator.php  # Bearer header → User row (or 401)
      ApiResponse.php         # json(), error(), paginated() helpers
      RateLimiter.php         # 60 req/min/token, sliding 60s buckets
      JsonRequest.php         # parse + validate JSON body
      Handlers/
        MeHandler.php
        ProjectsHandler.php
        ColumnsHandler.php
        TasksHandler.php
        CommentsHandler.php
        TagsHandler.php
        AttachmentsHandler.php
        FormsHandler.php
        PollsHandler.php
        SchemaHandler.php     # serves openapi.yaml
  Repository/
    ApiTokenRepository.php    # new
```

Handlers are thin: parse JSON → call existing `Repository` / `Service` → format response with `ApiResponse`. Business logic stays in repos and `RolePolicy`. **No duplication of authorization rules** — every handler calls `RolePolicy` exactly like web controllers do.

**RolePolicy consolidation as part of this work:** a few authorization checks today live inline in web controllers (e.g., "comment author or admin can delete" in `CommentController`). Those are extracted into `RolePolicy` methods before the corresponding API handler is built, so both surfaces share one source of truth. This is scoped tightly — only the checks the new handlers need, not a blanket sweep.

### Dispatch hand-off in `public/index.php`

Inserted **before** the existing Router match:

```php
if (str_starts_with($req->path, '/api/v1/')) {
    (new \App\Api\V1\ApiKernel(/* deps */))->handle($req);
    exit;
}
```

`ApiKernel` has its own route table (declarative array → handler@method) and runs its own middleware chain: rate limit → auth → handler. Web Router is untouched. Existing internal `/api/*` SPA routes (e.g. `/api/projects/{id}/columns/{cid}/tasks`) continue to use session auth + CSRF.

### Dependencies wired in bootstrap

New `App::singleton('api_tokens', ...)`. `ApiKernel` is constructed per-request (not a singleton) because it holds request state.

## 7. Data model — migrations

Two new migrations, applied via the existing `Migrations` runner.

Filenames pick the next free slots for today's date (existing `20260603_010_polls.php`, `_020_poll_votes.php`, `_030_updater.php` are present — per `docs/MIGRATIONS.md` filenames are permanent once shipped):

```sql
-- 20260603_040_api_tokens.php
CREATE TABLE api_tokens (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  name          TEXT NOT NULL,
  token_hash    TEXT NOT NULL UNIQUE,    -- sha256 hex (64 chars)
  prefix        TEXT NOT NULL,           -- 'otk_AbCdEfGh' (12 chars) for UI
  last_used_at  INTEGER NULL,
  last_used_ip  TEXT NULL,
  created_at    INTEGER NOT NULL,
  expires_at    INTEGER NULL,
  revoked_at    INTEGER NULL
);
CREATE INDEX idx_api_tokens_user_active ON api_tokens(user_id, revoked_at);
CREATE INDEX idx_api_tokens_hash ON api_tokens(token_hash);
```

```sql
-- 20260603_050_api_rate_limits.php
CREATE TABLE api_rate_limits (
  token_id     INTEGER PRIMARY KEY REFERENCES api_tokens(id) ON DELETE CASCADE,
  window_start INTEGER NOT NULL,
  count        INTEGER NOT NULL
);
```

**MySQL portability (per TODO #1):** `INTEGER PRIMARY KEY AUTOINCREMENT` is SQLite-only and will need `INT AUTO_INCREMENT PRIMARY KEY` on MySQL. The existing migrations have the same constraint, so cross-dialect work is owned by TODO #1, not duplicated here. We follow existing migration style verbatim.

**Data preservation rule** (per `docs/MIGRATIONS.md`): both tables are new, no `DROP` or destructive change to existing tables.

## 8. Rate limiting

- **60 requests / minute / token**, sliding 60-second windows.
- Implementation: on each call, `UPSERT api_rate_limits` keyed by `token_id`. If `window_start + 60 <= now()` → reset (`window_start=now, count=1`); else `count++`. If `count > 60` → 429 with `Retry-After: <seconds_until_window_end>`.
- One extra `UPDATE` per request; acceptable for the expected single-tenant traffic.
- **`RateLimiter` is general-purpose** — it takes a key (token id, IP, email, …), a max, and a window. The login-throttle gap I flagged in the 2026-06-02 review will reuse this same service in a follow-up. The follow-up is **out of scope** for this spec but the API is designed to enable it.
- Errors counted: every API request counts toward the limit, including 4xx (prevents brute-force probing). 5xx counts too.

## 9. UI surface

### User self-service: `/profile` → "API tokens" panel (new section)

- Table columns: name · prefix (e.g. `otk_AbCdEfGh…`) · created · last used (relative, e.g. "2h ago") · expires · status (active/revoked/expired) · [Revoke] button.
- "Create token" button opens a modal: `name` (required), `expires_at` (optional date picker).
- On submit, server returns the full token **once**. UI shows a one-time-reveal screen with copy-to-clipboard + "I've saved it, continue" confirmation. Closing without confirming still works; the token is created either way.
- Revoke confirms with `CRM.confirm` (no native dialog).
- Empty-state copy invites first-time users to create one and links to `docs/API.md`.

### Admin: `/users/{id}` page

- Add a "API tokens" sub-section listing the user's tokens (read-only — admins **cannot** see token values, only metadata).
- "Revoke all" button for emergency lockout (one click → all of that user's tokens set `revoked_at=now()`).

### i18n

New `api_tokens.*` keys added to `system/i18n/en.php`, `pl.php`, `uk.php` with full parity. (Audit already flagged the `forms_data.brand_tag` gap; do not add new gaps.)

## 10. Audit, logging, observability

- Every successful API call appends to `activity_log` with `actor_id=user_id`, `action='api.<resource>.<verb>'` (e.g. `api.tasks.create`), `meta={ token_id, route, status, ip, ms }`.
- On every successful auth, `api_tokens.last_used_at = now()` and `last_used_ip = visitor_ip()` (single UPDATE).
- 4xx/5xx responses go to `data/errors.log` via the existing structured error path — no new logger introduced here (a dedicated `ErrorLogger` is a separate audit item).
- Failed auth attempts (bad/revoked/expired token) log token *prefix only*, never the full value.

## 11. MCP bridge for Claude — out of scope for v1, recorded for context

Not built as part of this spec's implementation plan. The API is designed so an MCP bridge is a thin downstream consumer:

- New top-level `mcp/` directory in the repo (or separate repo — deferred).
- Node.js stdio MCP server, ~300 LOC. Reads `docs/openapi.yaml` at startup and exposes a curated subset as MCP tools (`list_projects`, `get_task`, `create_task`, `move_task`, `add_comment` — not all 50 endpoints).
- Token + base URL via env (`OTACK_API_TOKEN`, `OTACK_API_URL`).
- A separate spec + plan will be written when this is picked up.

**The only design constraint this places on the API:** the OpenAPI document must be complete and machine-readable, which is already a goal in §13.

## 12. Testing

### Unit (extends `make unit`)

- `test_api_token_repo.php`: create, find by hash, list active, revoke, expire check, prefix derivation.
- `test_token_authenticator.php`: valid, malformed prefix, unknown hash, revoked, expired, missing header.
- `test_rate_limiter.php`: under limit, at limit, over limit, window rollover, multiple tokens isolated.
- `test_api_response.php`: error envelope shape, pagination envelope, status code mapping.

### Integration

New `tests/api/` directory + a new `make api` target (or fold into `make unit` if cheap). Spins up `php -S` in-process the same way Playwright does, points at `app.test.sqlite`, hits every endpoint with curl-equivalent (PHP's `file_get_contents` with stream context, or a hand-rolled mini HTTP client — no Guzzle, no Composer).

Coverage required:
- One happy path per handler verb (≈40 cases).
- One RolePolicy denial per handler (employee tries manager action → 403).
- Negative auth: missing header, bad prefix, unknown hash, revoked, expired → 401.
- Rate limit: 61st call within a window → 429 with `Retry-After`.

### E2E (Playwright)

- `api-tokens.spec.ts`: create token, copy from reveal screen, list shows new token, revoke removes it from active list, admin can see metadata but not value.

### OpenAPI drift check

A unit test boots the `ApiKernel` route table, parses `docs/openapi.yaml`, and asserts every route in code appears in the spec and vice versa. Fails CI on drift.

## 13. Documentation deliverables

- **`docs/openapi.yaml`** — OpenAPI 3.1.0, complete spec including auth scheme, all schemas, error envelope, examples for each verb. Hand-written; the drift check (§12) keeps it honest.
- **`docs/API.md`** — human-oriented quickstart: auth flow, curl examples, pagination, rate limits, error codes, recommended patterns (service-account user for integrations).
- README addition: one paragraph + link to `docs/API.md`.

## 14. Build order — feeds the implementation plan

1. **Migrations + repo:** `api_tokens`, `api_rate_limits` migrations; `ApiTokenRepository` with full CRUD + helpers; unit tests.
2. **Kernel skeleton:** `ApiKernel`, `TokenAuthenticator`, `ApiResponse`, `RateLimiter`, `JsonRequest`. Wire `/api/v1/ping` (returns `{ok: true, user_id}`); integration test scaffolding (`tests/api/run.php`).
3. **MeHandler** — smallest real endpoint; validates full auth + error pipeline end-to-end.
4. **Projects + Columns + Tasks** — the meat. Each handler ships with its integration tests.
5. **Comments + Tags + Attachments.**
6. **Forms + Polls** (read-only).
7. **UI:** `/profile` API tokens panel (modal, reveal, revoke); admin view on `/users/{id}`.
8. **OpenAPI spec + `docs/API.md`** + `SchemaHandler` route + drift check.
9. **i18n:** add `api_tokens.*` keys to en/pl/uk; verify parity.
10. **README update + final pass:** error log review, audit log entries inspected, last_used_at confirmed updating.

(Phase 2 — separate spec and plan: MCP bridge.)

## 15. Trade-offs explicitly accepted

- **No per-token scopes.** A compromised "Claude MCP" token can do everything the owning user can. Mitigation documented: create a dedicated employee-role service-account user with limited project membership; bind tokens to that user. Revisit if real integrations need narrower scopes.
- **Rate limit in SQLite/MySQL, not Redis.** One extra row write per request. Negligible at single-tenant scale; revisit if traffic grows past a few req/sec sustained.
- **OpenAPI hand-maintained.** Drift is a real risk; mitigated by the route-table drift test.
- **No streaming / SSE endpoints.** Polling is sufficient for the planned consumers.

## 16. Open questions for after first implementation

These are deferred — they don't block v1 and will be answered by usage:

- Do we need per-token IP allowlisting? (Probably yes for high-value tokens; cheap to add later via a `allowed_ips` TEXT column.)
- Do we need bulk endpoints (`POST /tasks/batch`)? Wait for a real use case.
- Should `last_used_at` writes be deferred / batched if traffic ever justifies it? Not yet.
- MCP bridge distribution model (in-repo npm package vs. separate repo)? Decided when picked up.

---

**Spec ends.** Implementation plan to be written next via `superpowers:writing-plans` against §14.
