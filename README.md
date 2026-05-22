# Otack Manager

Minimal multi-user PHP project & task manager with kanban, comments, attachments and Telegram notifications.

## Documentation

See [DESIGN.md](docs/DESIGN.md) for the full design system specification: token graph, component specs, kanban patterns, and UX rules. The design spec is the source of truth — if code conflicts with it, fix the code.

Server-rendered, no SPA, no composer dependencies.

## Requirements

- PHP 8.2+
- SQLite (bundled with PHP via PDO sqlite extension)
- (For tests) Node.js 18+ and `@playwright/test`

## Setup

```bash
cp .env.example .env
# Edit .env if needed:
#   APP_URL              public base URL (used for Telegram links)
#   APP_DEBUG            true to show stack traces; set to false in production
#   DB_PATH              SQLite file path (default: data/app.sqlite)
#   UPLOAD_MAX_IMAGE     bytes (default: 5 MB)
#   UPLOAD_MAX_FILE      bytes (default: 50 MB)
#   TG_BOT_TOKEN         optional — leave empty to disable notifications
#   TG_CHAT_ID           optional — leave empty to disable notifications

# Start the dev server
php -S localhost:8000 -t public public/index.php

# Open http://localhost:8000
# Register the first user — they become admin/approved automatically.
# Subsequent users land in /pending until admin approves them at /users.
```

## Telegram notifications

To enable notifications:

1. Create a bot via `@BotFather` on Telegram, copy the token.
2. Get your group/channel chat ID (e.g. via `@userinfobot` or `@getidsbot`).
3. Put them in `.env`:
   ```
   TG_BOT_TOKEN=123456:ABC-DEF...
   TG_CHAT_ID=-1001234567890
   ```
4. All future events (registrations, project/task creates, comments, status changes, …) post to that single channel.

If the env vars are empty, notifications are silently skipped (logged with `error='skipped'` in `notifications_log` for auditing).

## Production (Apache)

The included `.htaccess` files handle the routing. Point Apache at the project root; the front controller is at `public/index.php`.

Make sure:
- `data/` is writable (SQLite + sessions + schema markers + error log)
- `public/uploads/` is writable (file storage)
- `.env` and `data/` are NOT web-accessible (blocked by the root `.htaccess`).

## Tests

```bash
# Unit tests (PHP) — runs ~45 tests in under a second
php tests/run.php

# E2E tests (Playwright) — runs ~14 browser tests
npx playwright test --config tests/e2e/playwright.config.ts
```

## File structure

```
otack-manager/
├── public/                  Web root
│   ├── index.php            Front controller (~150 LOC)
│   ├── .htaccess            Rewrite + static-file pass-through
│   ├── assets/
│   │   ├── css/app.css      Full design system (~1600 LOC)
│   │   ├── js/              ES modules: ui.js, kanban.js, comments.js, wysiwyg.js …
│   │   ├── fonts/           Manrope + JetBrains Mono woff2
│   │   ├── img/             logo.svg (kanban+check icon)
│   │   └── vendor/          FontAwesome 6 Free, SortableJS, Quill WYSIWYG
│   └── uploads/             User uploads (YYYY/MM/{uuid}.{ext})
├── system/
│   ├── bootstrap.php        Autoloader + .env loader
│   ├── App.php              Static service container
│   ├── Auth/                AuthManager, PasswordHasher, SessionManager
│   ├── Controller/          BaseController + per-resource controllers
│   ├── Database/            Connection, SchemaBootstrap, Migrations
│   ├── Http/                Request, Response, Csrf, AuthGuard
│   ├── Repository/          User, Project, Task, Comment, Attachment, Tag, …
│   ├── Routing/Router.php
│   ├── Service/             EventBus, FileUploader, Markdown, TelegramNotifier, NotificationLogger
│   └── View/                Renderer + helpers (e, fmt_date, fmt_size, icon, …)
├── views/
│   ├── layouts/             main.php (full shell) and auth.php (centered card)
│   ├── partials/            sidebar, topbar, modal-root, toast-root,
│   │                        lightbox-root, members, comment-thread,
│   │                        attachment-list, tag-picker
│   ├── auth/                login.php, register.php, pending.php
│   ├── dashboard/index.php
│   ├── errors/              403.php, 404.php, 500.php
│   ├── projects/            index.php, form.php, show.php
│   ├── tasks/show.php
│   ├── tags/index.php       Admin tag management
│   ├── users/index.php
│   └── profile/show.php
├── data/                    SQLite + sessions + schema markers + error log
├── tests/
│   ├── run.php              Hand-rolled PHP test runner
│   ├── unit/                ~45 unit tests
│   └── e2e/                 Playwright specs + config
└── docs/superpowers/        Design spec + implementation plan
```

## License

Private project — do not redistribute.
