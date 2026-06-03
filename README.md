# Otack Manager

A small, self-hosted PHP project & task manager with kanban, comments, attachments, public forms / polls / short links, and Telegram notifications.

Server-rendered, no SPA, no Composer dependencies. Runs on SQLite by default, with first-class MySQL 8 support and a self-service migrator.

## Features

- Projects + kanban board with drag-drop, backlog, and per-project tags
- Tasks with Markdown comments, file attachments (image lightbox), links between tasks
- Roles: **admin**, **manager**, **employee** — scoped permissions across projects
- Public **Forms** (public URL, anti-bot honeypot + HMAC time-trap, optional auto-create-task)
- Public **Polls** (contact gate, one-vote-per-contact dedup, stats + voters tabs, post-close summary task)
- **Short links** with click stats (total + unique by hashed IP)
- **Compass** admin panel — migrations runner, cache clear, DB stats, logs viewer
- **In-app updates** — one-click upgrade from GitHub releases, automatic code + DB backups, one-click restore (Settings → Updates)
- **Dual-driver database** — SQLite (zero-config default) or MySQL 8; in-app SQLite → MySQL migrator (Compass → Migrate to MySQL)
- i18n: English (default), Polish, Ukrainian
- Mobile-responsive
- Telegram notifications for events (registrations, task changes, form submissions, …)

## Requirements

- PHP **8.2+** with the `pdo_sqlite` (and/or `pdo_mysql`), `dom`, and `fileinfo` extensions
- For MySQL deployments: MySQL **8.0+**; the in-app migrator uses `mysqldump` / `mysql` from PATH for snapshots
- Node.js 18+ (only for running Playwright E2E)

## Setup

```bash
cp .env.example .env
# Edit .env — at minimum set APP_URL and (optionally) the Telegram bot vars.
php -S localhost:8000 -t public public/index.php
```

Open <http://localhost:8000>. The first user to register becomes the admin automatically. Subsequent self-registrations land in `/pending` until approved at `/users`.

A default admin can also be seeded via `SEED_DEFAULT_ADMIN_EMAIL` / `SEED_DEFAULT_ADMIN_PASSWORD_HASH` in `.env`.

## Database

Default is **SQLite** at `data/app.sqlite` — zero config; the file is created on first boot.

To run on **MySQL 8** instead, set `DB_DSN` in `.env`:

```
DB_DSN=mysql:host=127.0.0.1;port=3306;dbname=otack;charset=utf8mb4
DB_USER=otack
DB_PASSWORD=…
```

Already running on SQLite and want to move? Open **Compass → Migrate to MySQL** for the in-app wizard (table-by-table copy, sanity check, then paste the new env vars and reload). The SQLite file is never touched, so rollback is "revert .env". Full design notes in [docs/DATABASE.md](docs/DATABASE.md).

### Migrations

Per-file migrations in `system/Database/migrations/` use a portable Schema DSL (see [docs/DATABASE.md §3.2](docs/DATABASE.md)). They apply automatically on the first HTTP hit, or explicitly:

```bash
make migrate           # or: php bin/migrate.php
```

Tracked in the `schema_migrations` table. Filenames are permanent once shipped. See [docs/MIGRATIONS.md](docs/MIGRATIONS.md).

## Telegram notifications

1. Create a bot via `@BotFather`, copy the token.
2. Get your chat/channel ID (`@userinfobot`).
3. Put both into `.env`:
   ```
   TG_BOT_TOKEN=123456:ABC-DEF…
   TG_CHAT_ID=-1001234567890
   ```

Empty token / chat-id disables notifications (logged as `skipped` in `notifications_log`).

## Updating

Admin → Settings → **Updates** tab. Otack Manager checks the GitHub releases of [slisenkobk/otack-manager](https://github.com/slisenkobk/otack-manager) hourly (cadence is `UPDATE_CHECK_INTERVAL`). When a newer semver tag (`vX.Y.Z`) is published, a badge appears next to "Dashboard" and the Updates tab offers a one-click "Update now". The pipeline:

1. Snapshots the current code + DB into `data/backups/{timestamp}/`
2. Downloads the target tag tarball from GitHub, extracts it
3. Atomic per-file swap into `APP_ROOT`; files not in the new release move to `removed/`
4. Runs any new migrations
5. Records the install in `app_versions` + the backup in `app_backups`

`data/`, `public/uploads/`, `.env` are never touched. On any failure the pipeline rolls back from the snapshot automatically. Every backup is restorable from the Backups table (1-click rollback) until pruned by retention (`UPDATE_BACKUP_KEEP`, default 5).

For shell-level updates when the UI is unreachable:

```bash
php bin/self-update.php --latest        # check + install if newer
php bin/self-update.php 1.0.3           # install a specific version
```

Disable the feature entirely with `UPDATE_ENABLED=false` in `.env` (useful when the appliance is updated via OS package manager). Full design notes in [docs/UPDATES.md](docs/UPDATES.md).

## Tests

```bash
make unit              # 140 PHP unit tests, hand-rolled runner, <1s
make unit-mysql        # same suite against docker mysql:8.0 (CI also runs this)
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
- [docs/DATABASE.md](docs/DATABASE.md) — dual-driver (SQLite + MySQL) design + in-app migrator plan
- [docs/UPDATES.md](docs/UPDATES.md) — design of the in-app GitHub-driven updater (versions, backups, restore)
- [docs/QA-CHECKLIST.md](docs/QA-CHECKLIST.md) — manual QA walkthrough
- [docs/API.md](docs/API.md) — third-party REST API guide: integration setup, auth, endpoint reference, recipes
- [docs/INTEGRATION-CHECKLIST.md](docs/INTEGRATION-CHECKLIST.md) — one-page checklist for integrators
- [docs/openapi.yaml](docs/openapi.yaml) — OpenAPI 3.1.0 machine-readable contract

## Stack

PHP 8.2 + SQLite or MySQL (PDO) + vanilla JS (ES modules) + Quill (WYSIWYG) + SortableJS + Playwright (E2E). No Composer, no bundler, no framework.

## License

MIT — see [LICENSE](LICENSE).
