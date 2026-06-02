# Otack Manager

A small, self-hosted PHP project & task manager with kanban, comments, attachments, public forms / polls / short links, and Telegram notifications.

Server-rendered, no SPA, no Composer dependencies, SQLite for storage.

## Features

- Projects + kanban board with drag-drop, backlog, and per-project tags
- Tasks with Markdown comments, file attachments (image lightbox), links between tasks
- Roles: **admin**, **manager**, **employee** — scoped permissions across projects
- Public **Forms** (public URL, anti-bot honeypot + HMAC time-trap, optional auto-create-task)
- Public **Polls** (contact gate, one-vote-per-contact dedup, stats + voters tabs, post-close summary task)
- **Short links** with click stats (total + unique by hashed IP)
- **Compass** admin panel — migrations runner, cache clear, DB stats, logs viewer
- i18n: English (default), Polish, Ukrainian
- Mobile-responsive
- Telegram notifications for events (registrations, task changes, form submissions, …)

## Requirements

- PHP **8.2+** with the `pdo_sqlite`, `dom`, and `fileinfo` extensions
- Node.js 18+ (only for running Playwright E2E)

## Setup

```bash
cp .env.example .env
# Edit .env — at minimum set APP_URL and (optionally) the Telegram bot vars.
php -S localhost:8000 -t public public/index.php
```

Open <http://localhost:8000>. The first user to register becomes the admin automatically. Subsequent self-registrations land in `/pending` until approved at `/users`.

A default admin can also be seeded via `SEED_DEFAULT_ADMIN_EMAIL` / `SEED_DEFAULT_ADMIN_PASSWORD_HASH` in `.env`.

## Database migrations

Per-file migrations in `system/Database/migrations/` apply automatically on the first HTTP hit. To run explicitly:

```bash
make migrate           # or: php bin/migrate.php
```

Tracked in the `schema_migrations` table. Filenames are permanent once shipped — renaming an applied migration would re-execute it. See [docs/MIGRATIONS.md](docs/MIGRATIONS.md).

## Telegram notifications

1. Create a bot via `@BotFather`, copy the token.
2. Get your chat/channel ID (`@userinfobot`).
3. Put both into `.env`:
   ```
   TG_BOT_TOKEN=123456:ABC-DEF…
   TG_CHAT_ID=-1001234567890
   ```

Empty token / chat-id disables notifications (logged as `skipped` in `notifications_log`).

## Tests

```bash
make unit              # 105 PHP unit tests, hand-rolled runner, <1s
make e2e               # 17 Playwright specs (Chromium), serial mode
```

## Production (Apache / nginx)

Front controller is `public/index.php`. The bundled `.htaccess` files handle routing for Apache; for nginx, route everything that isn't a static file in `public/` to `public/index.php`.

Make sure:

- `data/` is writable (SQLite, sessions, error log)
- `public/uploads/` is writable
- `.env` and `data/` are **not** web-accessible

## Documentation

- [docs/DESIGN.md](docs/DESIGN.md) — full design system (palette → semantic → component specs); source of truth for UI
- [docs/MIGRATIONS.md](docs/MIGRATIONS.md) — schema migration format, naming rules, **data preservation rule**
- [docs/UPDATES.md](docs/UPDATES.md) — design of the in-app GitHub-driven updater (versions, backups, restore)
- [docs/QA-CHECKLIST.md](docs/QA-CHECKLIST.md) — manual QA walkthrough

## Stack

PHP 8.2 + SQLite (PDO) + vanilla JS (ES modules) + Quill (WYSIWYG) + SortableJS + Playwright (E2E). No Composer, no bundler, no framework.

## License

MIT — see [LICENSE](LICENSE).
