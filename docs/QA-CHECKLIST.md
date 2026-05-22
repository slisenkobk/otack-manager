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
