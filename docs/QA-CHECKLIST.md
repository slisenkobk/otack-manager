# QA Checklist — Otack Manager

Manual smoke test before each release. Tick each item.

## Authentication

- [ ] Fresh DB: register first user → becomes admin → redirected to `/`
- [ ] Register second user → goes to `/pending`; cannot log in until approved
- [ ] Admin approves second user via `/users` → second user can log in
- [ ] Wrong password 5 times within 15 min → `Throttled` flash message
- [ ] Logout clears session and regenerates CSRF token
- [ ] Visiting `/` while logged out → redirects to `/login`

## Projects

- [ ] Admin creates a project from `/projects/new` → 3 default columns visible: `To Do`, `In Progress`, `Done`
- [ ] Project owner adds a second user as member → that user sees the project in their `/projects` list
- [ ] Non-member visiting `/projects/{id}` → 403

## Kanban

- [ ] Quick-add task from a column footer → card appears
- [ ] Drag a card from one column to another → position persists after reload
- [ ] Network/server error during drag → card rolls back to original column + toast
- [ ] Middle-click a card → opens `/tasks/{id}` in a new tab
- [ ] Normal click on a card → opens `/tasks/{id}` in a new tab
- [ ] Column settings: rename, change color, save → persists
- [ ] Delete a column with tasks → modal asks where to move them → tasks reassigned

## Tasks (standalone page)

- [ ] Edit title (contenteditable) → blur saves
- [ ] Edit description (markdown) → Save renders `**bold**`, `` `code` ``, fenced blocks, lists
- [ ] Change column / assignee / due date → saves immediately via AJAX
- [ ] `*single asterisks*` rendered literally (no italic)
- [ ] `[link](javascript:alert(1))` rendered as literal text (not anchor)
- [ ] Delete task → confirm modal → task removed → redirected to project

## Comments

- [ ] Post a comment on a project Overview tab → appears in thread
- [ ] Post a comment with `**bold**` → renders as `<strong>`
- [ ] Author can delete their own comment
- [ ] Admin can delete any comment
- [ ] `<script>` in comment body → escaped, never executed

## Comment attachments

- [ ] "Attach files" label in comment composer opens file picker
- [ ] Selecting files shows pending chip(s) with × remove button
- [ ] Clicking × on a pending chip removes it from the list
- [ ] Submitting comment with attachment(s) uploads files and shows chip(s) in the new comment
- [ ] Reloading the page preserves attachment chips on existing comments
- [ ] Clicking an attachment chip opens the file in a new tab
- [ ] Attachment chip shows file icon (paperclip for files, image for images) + name + size

## Attachments

- [ ] Upload a 4 MB JPEG → thumbnail in `.attach-grid`
- [ ] Click image thumbnail → lightbox opens, arrow keys navigate, Esc closes
- [ ] Upload a 6 MB JPEG → toast error "Image exceeds 5 MB"
- [ ] Upload a 30 MB PDF → file row with FA icon + filename
- [ ] Upload a 60 MB PDF → toast error "File exceeds 50 MB"
- [ ] Upload an SVG → toast error "SVG uploads are not allowed"
- [ ] Uploader can delete their own attachment; admin can delete any

## Tags

- [ ] Add an existing project tag via the picker → chip appears
- [ ] Create a new tag inline ("+ Create '{input}'") → chip appears
- [ ] Remove a tag chip → tag detaches

## Admin tag management (/admin/tags)

- [ ] Sidebar shows "Tags" nav item (admin only)
- [ ] `/admin/tags` lists all tags grouped by scope (Project / Task)
- [ ] Inline rename (contenteditable + blur) persists after reload
- [ ] Color picker change persists after reload
- [ ] Delete with confirm modal removes tag from list and from any project/task it was attached to
- [ ] Usage counts (P:N T:N) shown per tag row

## Users admin (admin only)

- [ ] `/users` list shows all users with status pills
- [ ] Approve / Block / Make admin / Make member actions work
- [ ] Delete a user who has projects or comments → blocked with "Block instead" toast
- [ ] Cannot delete or block yourself

## Profile

- [ ] Change name → persists, success toast
- [ ] Change password with correct current → success
- [ ] Wrong current password → error
- [ ] Mismatched new/confirm → error

## Dashboard

- [ ] `/` shows stats (open projects · my tasks · activity)
- [ ] "My tasks" grid shows up to 6 cards linking to task pages
- [ ] "Recent projects" grid shows up to 3 projects
- [ ] "Recent activity" shows recent comments (up to 10)
- [ ] "Load more" button appears when there are more than 10 activity items
- [ ] Clicking "Load more" appends next 10 items without full reload
- [ ] "Load more" button disappears when no further items remain

## Telegram (only if `.env` configured)

- [ ] Registering a user posts `[NEW] Registration request: …`
- [ ] Admin approving a user posts `[USER] … approved by …`
- [ ] Creating a project posts `[PROJECT] … created '…'` + link
- [ ] Creating a task posts `[TASK] … added '…' to …` + link
- [ ] Moving a task across columns posts `[TASK] … moved '…' → {column}` + link
- [ ] Changing assignee posts `[TASK] … assigned '…' to …` + link
- [ ] Posting a comment posts `[COMMENT] … on … '…': {first 200 chars}` + link

## Security

- [ ] Direct GET to `/system/...`, `/data/...`, `/.env` → 403 (via .htaccess; only works under Apache, not cli-server)
- [ ] All POST forms have `_csrf` hidden field; all AJAX has `X-CSRF-Token` header
- [ ] Wrong/missing CSRF token → 419
- [ ] All UI confirms are custom `UI.confirm` modals — no native `alert/confirm/prompt`
- [ ] Image uploads served with `Content-Disposition: inline`; other files forced to `attachment` via `public/uploads/.htaccess`

## Forms (public)

- [ ] Admin creates a form via Integrations → Forms → builder with at least 2 fields and saves
- [ ] Public form URL (`/f/{hash}`) loads with the right locale
- [ ] Submitting without the honeypot field set → 200 + thanks page; submission row appears in `/forms-data`
- [ ] Submitting with the honeypot filled → silent 200 (no submission recorded)
- [ ] Submitting within the HMAC time-trap window (too fast or expired) → rejected
- [ ] When the form has "auto-create task" enabled and a target project, a new task is created in that project's first non-backlog column
- [ ] Admin opens `/forms-data` → submissions visible with field values and timestamp
- [ ] Deleting a form deletes its submissions
- [ ] `/admin/settings → Brand` change reflects in the public form footer tag

## Polls (public)

- [ ] Admin creates a poll via Integrations → Polls with at least 2 options and saves as draft
- [ ] Draft poll page in admin shows the Builder tab; Voters tab disabled
- [ ] Activating the poll locks editing and shows the Statistics tab as primary
- [ ] Public poll URL (`/p/{hash}`) requires contact field before voting
- [ ] Submitting same contact twice → second attempt rejected (one-vote-per-contact dedup)
- [ ] After closing the poll, the "Create task with results" action drops a summary task in the linked project
- [ ] Voters tab shows the contact values + chosen option for each vote
- [ ] Reactivating a closed poll is blocked (no time-travel)

## Short links

- [ ] Admin creates a short link via Integrations → Links with an allowed scheme (https://, http://, mailto:)
- [ ] Disallowed scheme (`javascript:`, `data:`) → 422
- [ ] Public `/s/{hash}` redirects to the target URL with HTTP 302
- [ ] Stats page shows total clicks + unique visitors (by hashed IP)
- [ ] Disabling the link → public URL returns 410
- [ ] Copy-to-clipboard button on the link row reports success toast
- [ ] When behind a trusted proxy, the XFF first-hop is the IP recorded for unique-visitor counting; otherwise REMOTE_ADDR

## Updates (in-app updater)

- [ ] Dashboard topbar shows the version pill matching `system/version.php`
- [ ] Settings → Updates tab lists the current version and recent backups
- [ ] When a newer GitHub release exists, "Update now" button appears and triggers the pipeline
- [ ] Update pipeline snapshots `data/backups/{timestamp}/code` and `…/db` before swapping files
- [ ] After update, schema migrations apply automatically (`schema_migrations` reflects them)
- [ ] One-click Restore from the Backups table rolls back code + DB
- [ ] `UPDATE_BACKUP_KEEP` retention prunes older snapshots when exceeded
- [ ] `UPDATE_ENABLED=false` hides the Updates UI and disables the check entirely

## MySQL migration (Compass → Migrate to MySQL)

- [ ] Default SQLite install — Compass shows "Migrate to MySQL" entry
- [ ] Test-connection succeeds with valid DSN/user/pass; clear error on bad creds
- [ ] Plan step lists every table with row counts source→target = 0
- [ ] Sync step copies in batches and reports per-table progress
- [ ] After completion, AUTO_INCREMENT is reset to MAX(id)+1 per table
- [ ] Sanity check compares counts source vs target — must match
- [ ] Operator pastes new env vars into `.env`, reloads, hits `/admin/compass/verify`
- [ ] SQLite file untouched on disk — rollback path is "revert .env"

## External API

- [ ] `/profile/tokens` lists active tokens, masked to prefix only
- [ ] Creating a new token shows the full secret ONCE; refreshing the page hides it
- [ ] `curl -H "Authorization: Bearer otk_…" /api/v1/me` returns the owner user JSON
- [ ] Revoking a token returns 401 on the next request that uses it
- [ ] Rate limit: 61st request within 60 s returns 429
- [ ] `last_used_at` updates on each successful request
- [ ] An activity_log entry is recorded for each token write (per `docs/API.md`)
- [ ] OpenAPI spec at `docs/openapi.yaml` validates against the live route set (the convention test guards this)
