# Deployment

How to put Otack Manager on a real server. Aimed at operators standing up a
single-tenant instance behind Apache or nginx; not a multi-tenant cloud guide.

## 1. PHP requirements

- **PHP 8.2 or newer.** The codebase uses readonly promoted properties,
  `str_starts_with`, first-class callables, and `match`. 8.1 will fail at parse
  time on at least one file.
- Required extensions (the app will not start without these):
  - `pdo`
  - `pdo_sqlite` (default driver) **and / or** `pdo_mysql`
  - `dom` — required by [`HtmlSanitizer`](../system/Service/HtmlSanitizer.php).
    Without it the sanitizer silently degrades to `strip_tags`, which loses
    attribute-level enforcement. This is a security-relevant requirement.
  - `fileinfo` — finfo MIME sniffing on uploads.
  - `mbstring` — used by Markdown rendering, Telegram escaping, i18n.
  - `curl` — used by the in-app updater (downloads the release tarball from
    GitHub) and by `TelegramNotifier`.
  - `json` — always present in modern PHP builds but mentioned for
    completeness.

Verify on the box:

```bash
php -v
php -m | egrep -i 'pdo|dom|fileinfo|mbstring|curl'
```

## 1.5. `data/config.json` (wizard-managed overlay)

From v1.5.0 onwards, install-time and operator-tweakable configuration
can live in `data/config.json` instead of `.env`. The setup wizard
(/install on a fresh box) writes this file; the Compass → Platform
Settings tab edits it post-install. Precedence:

    data/config.json  >  $_ENV (from .env or shell)  >  defaults

The file is JSON, mode 0600, owned by the web user. Allowed keys are
a strict allow-list (see [`system/Service/ConfigStore.php`](../system/Service/ConfigStore.php) ALLOWED_KEYS).
Operators may edit it by hand, but values are re-validated on read —
malformed entries silently fall back to `.env` / defaults rather than
being trusted.

Required filesystem permissions on a fresh box:
- `data/` must be writable by the web user (already required for
  SQLite and uploads).
- After the wizard runs, `data/config.json` will be mode 0600. If
  shared-filesystem requirements force a different mode, the boot
  log will warn but the app will still work.

To opt out of the wizard (advanced operators preferring `.env`):
- Leave `data/config.json` absent.
- Set `LOGIN_HASH`, `APP_SECRET`, etc. in `.env` as before.
- Pre-seed an admin via `SEED_DEFAULT_ADMIN_EMAIL` /
  `SEED_DEFAULT_ADMIN_PASSWORD_HASH`. The wizard gate skips when an
  admin already exists.

## 2. Filesystem layout

The shipped tarball expands to a top-level directory containing:

```
public/             # web root — point Apache/nginx here
public/uploads/     # writable; UUID-named files served via .htaccess rules
public/.htaccess    # ships with the tarball
data/               # writable; SQLite DB, sessions, error log, update backups
system/             # PHP source — must NOT be web-accessible
views/              # templates — must NOT be web-accessible
bin/                # CLI scripts (migrate, self-update)
.env                # secrets and config — must NOT be web-accessible
.htaccess           # top-level guard, denies system/data/docs/.env
```

The two `.htaccess` files at the repo root and inside `public/` already deny
direct access to `system/`, `data/`, `docs/`, `tests/`, and `.env` when running
under Apache with `mod_rewrite`. For nginx, see §4.

Required permissions:

```bash
chown -R www-data:www-data data public/uploads
chmod 0700 data data/sessions
chmod 0750 public/uploads
```

`data/sessions` is created on first boot with mode 0700 by
[`SessionManager`](../system/Auth/SessionManager.php). Do not relax this — other
local users on the box would otherwise be able to read session files.

## 3. MySQL setup (optional)

SQLite is the zero-config default. For MySQL 8.0+:

```ini
# .env
DB_DSN=mysql:host=127.0.0.1;port=3306;dbname=otack;charset=utf8mb4
DB_USER=otack
DB_PASSWORD=…
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_0900_ai_ci
```

The application user needs `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER,
INDEX, DROP, REFERENCES` on the database — `DROP` and `ALTER` are used by
migrations. If you would rather pre-create the schema and disallow DDL at
runtime, run `php bin/migrate.php` with a privileged user, then drop the user's
DDL grants.

Migrations apply automatically on the first HTTP hit. To run them out-of-band:

```bash
php bin/migrate.php
```

Schema is `utf8mb4` with `utf8mb4_0900_ai_ci` collation. The migrations are
strict-mode safe — running under `STRICT_TRANS_TABLES,STRICT_ALL_TABLES` is
expected.

## 4. Apache vs nginx

**Apache** — the bundled `.htaccess` files handle everything. Make sure
`mod_rewrite` is loaded and the vhost allows `AllowOverride All` on the
document root.

**nginx** — minimal sample (adapt to your TLS / php-fpm setup):

```nginx
server {
    listen 443 ssl http2;
    server_name tasks.example.com;
    root /var/www/otack-manager/public;
    index index.php;

    # Block PHP execution inside uploads (defence in depth, on top of
    # the MIME sniffer).
    location ~* /uploads/.*\.(php|phtml|phar)$ { deny all; }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Defence in depth — top-level guards. The DocumentRoot already points
    # at public/, but this catches /system/… if anyone misconfigures the root.
    location ~ ^/(system|data|docs|tests|bin)(/|$) { deny all; }
    location ~ /\.env$ { deny all; }
}
```

## 5. Cron and scheduled tasks

None are required. Specifically:

- The update check runs lazily on a dashboard view at most every
  `UPDATE_CHECK_INTERVAL` seconds (default 1h). No cron entry is needed.
- Sessions GC runs via PHP's default probability.
- The rate-limit / login-throttle rows are pruned implicitly by their UPSERT
  semantics (window resets after expiry); a periodic
  `DELETE FROM api_rate_limits WHERE window_start < UNIX_TIMESTAMP() - 86400`
  is a nice-to-have but not load-bearing.

## 6. Backups

The application snapshots its own code + DB into `data/backups/{timestamp}/`
before every self-update — this is what powers the 1-click rollback in
Settings → Updates. These snapshots are not a substitute for off-host backups.

Recommended cron, run as the web user on the box:

```bash
# Daily snapshot of data/ to off-host storage. Adjust to your backup tool.
0 3 * * * tar czf /backups/otack-$(date +\%F).tgz -C /var/www/otack-manager data
```

For SQLite, `tar` is safe enough (the DB checkpoint is atomic for cold reads).
For MySQL, use `mysqldump` against the DSN — the in-app SQLite→MySQL migrator
already shells out to it, so the binary is expected to be on PATH for that
flow.

`make package` builds a deploy tarball when you want to ship from CI rather
than `git pull` on the box.

## 7. In-app updates

[`Updater`](../system/Service/Updater.php) reads:

- `UPDATE_ENABLED` (default `true`) — set to `false` to hide the Updates tab
  and skip background checks. Useful when the host owns updates via an OS
  package.
- `UPDATE_CHECK_INTERVAL` (seconds, default 3600) — minimum spacing between
  GitHub release-API calls.
- `UPDATE_BACKUP_KEEP` (default 5) — how many `data/backups/{ts}/` snapshots to
  keep. Older ones are pruned after each successful install.
- `UPDATE_REPO_URL` — defaults to the public repo; override to point at a fork.

CLI fallback when the UI is unreachable:

```bash
php bin/self-update.php --latest
php bin/self-update.php 1.0.3
```

The CLI script honours `UPDATE_ENABLED=false` and exits non-zero if it tries
to write outside `APP_ROOT`.

## 8. TLS

Always terminate TLS in front of PHP. The session cookie's `Secure` flag is
toggled by `$_SERVER['HTTPS']`, so without TLS the cookie is sent over
plaintext.

Recommended response headers from your TLS terminator:

- `Strict-Transport-Security: max-age=31536000; includeSubDomains`
- `X-Frame-Options: DENY` (the app's CSP already sets `frame-ancestors 'none'`,
  this is belt-and-braces for older browsers)
- HSTS preload only after you have committed to TLS forever on that hostname.

## 9. Environment variables

The canonical list is in [`.env.example`](../.env.example). Highlights:

- `APP_URL`, `APP_DEBUG`
- `DB_DSN`, `DB_USER`, `DB_PASSWORD`, `DB_PATH`
- `SESSION_LIFETIME` (seconds)
- `UPLOAD_MAX_IMAGE`, `UPLOAD_MAX_FILE`, `UPLOAD_DIR`
- `TG_BOT_TOKEN`, `TG_CHAT_ID`
- `LOGIN_HASH` — the `/login` URL gate, **also** the HMAC secret for public-form
  and poll anti-spam (see [SECURITY.md §9](SECURITY.md))
- `SEED_DEFAULT_ADMIN_*` — first-boot admin seed
- `TRUSTED_PROXIES` — see [SECURITY.md §7](SECURITY.md); leave empty if PHP is
  exposed directly with no proxy in front
- `UPDATE_ENABLED`, `UPDATE_CHECK_INTERVAL`, `UPDATE_BACKUP_KEEP`,
  `UPDATE_REPO_URL`

## 10. Common pitfalls

- **`TRUSTED_PROXIES` too wide.** Set it to your proxy's CIDR exactly. A
  too-broad CIDR lets clients spoof their IP for poll dedup, short-link unique
  counts, and the form submission audit log.
- **Session directory wrong owner.** PHP-FPM must own `data/sessions/`; mode
  must be `0700`. A world-readable session dir is a session-hijack hazard
  shared between local Unix users.
- **`ext-dom` missing.** The HTML sanitizer silently degrades to `strip_tags`.
  Always confirm `php -m | grep -i dom` after deploy.
- **MySQL strict mode quirks.** Our migrations are strict-mode safe. If you see
  `Incorrect string value` errors, your existing schema is not `utf8mb4` —
  drop and re-run, or `CONVERT TO CHARACTER SET utf8mb4`.
- **`UPLOAD_DIR` outside `public/`.** Supported but the front controller will
  not serve files from outside the docroot; you would need a separate
  download handler.
- **Time zone.** PHP and MySQL should agree. The app uses `time()` (Unix
  epoch) internally, so the wall-clock TZ only matters for the HTML
  timestamps displayed to humans.
