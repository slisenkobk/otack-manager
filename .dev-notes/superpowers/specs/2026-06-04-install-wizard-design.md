# Setup Wizard + Platform Settings — design spec

**TODO ref:** [#10 — Setup wizard for new installs](../../../TODO.md)
**Date:** 2026-06-04
**Status:** approved (ready for implementation plan)

## Goal

Replace the current ad-hoc install process (edit `.env` by hand → start
PHP → register first user) with a guided, zero-friction first-run
experience that an operator can complete entirely in the browser. After
the wizard, the same configuration surface is editable from a new
**Platform Settings** tab in Compass — so the same UI handles both
bootstrap and ongoing changes (rotate `APP_SECRET`, swap Telegram bot,
toggle `LOGIN_HASH`, etc.).

The goal is **zero file editing on a fresh server.** Drop the tarball,
point a web server at `public/`, open the URL — the wizard does the
rest.

## Non-goals

- Multi-tenant cloud onboarding. This is a single-tenant install.
- Migrating existing `.env`-only installs. They keep working
  unchanged — wizard skips them automatically (admin already exists).
- Editing arbitrary runtime settings (brand color, project palette,
  asset version) — those stay in the DB `settings` table where they
  already live.
- Per-user preferences. Wizard configures the installation, not user
  profiles.

## Architecture

```
data/
  app.sqlite             # existing
  config.json            # NEW — wizard-managed env overlay (0600, gitignored)
  config.json.tmp.<hex>  # transient during atomic writes

system/
  Service/
    ConfigStore.php      # NEW — read/write config.json, schema-validated
    InstallGate.php      # NEW — single isInstallRequired($pdo) predicate
  Controller/
    InstallController.php  # NEW — 6 wizard steps
    CompassController.php  # MODIFIED — add platform() action
  Bootstrap/
    Container.php        # MODIFIED — load ConfigStore overlay early

views/
  install/
    welcome.php          # NEW
    db.php               # NEW
    admin.php            # NEW
    security.php         # NEW
    integrations.php     # NEW
    done.php             # NEW
  admin/compass/
    platform.php         # NEW — Compass Platform Settings tab
  layouts/
    install.php          # NEW — wizard layout shell (no sidebar, step header)
```

**Precedence rule:** `data/config.json` > `$_ENV` (loaded from `.env`)
> hard-coded defaults. ConfigStore writes win the moment they land —
`App::env()` callers read the merged value with no code changes needed
because the overlay is merged into `$_ENV` at boot.

`.env` stays for dev/CI. In a production install it may be empty —
config.json carries everything.

## Components

### ConfigStore (`system/Service/ConfigStore.php`)

```php
final class ConfigStore
{
    public const PATH = APP_ROOT . '/data/config.json';

    public const ALLOWED_KEYS = [
        'DB_DSN', 'DB_USER', 'DB_PASSWORD', 'DB_CHARSET', 'DB_COLLATION',
        'APP_URL', 'APP_SECRET', 'LOGIN_HASH',
        'TG_BOT_TOKEN', 'TG_CHAT_ID',
        'TRUSTED_PROXIES',
        'UPDATE_ENABLED', 'UPDATE_CHECK_INTERVAL', 'UPDATE_BACKUP_KEEP',
    ];

    public function load(): array;              // [] if no file
    public function get(string $key): ?string;
    public function set(array $kv): void;       // validates + atomic write
    public function unset(array $keys): void;   // removes keys, .env fallback re-takes
    public function exists(): bool;
}
```

**Validation on `set()`:**
- Every key must be in `ALLOWED_KEYS` (otherwise `\InvalidArgumentException`).
- Values are coerced to `string`. Booleans → `"true"`/`"false"`; ints →
  decimal string. No arrays / objects accepted.
- `DB_DSN` regex `^(mysql|sqlite):` (rejects `file://`, shell-y stuff).
- `APP_URL` via `filter_var(..., FILTER_VALIDATE_URL)`, scheme must be
  `http` or `https`.
- `TG_CHAT_ID` regex `^-?\d+$`.
- `TRUSTED_PROXIES` CSV-of-CIDR-or-IP via regex.
- `UPDATE_CHECK_INTERVAL`, `UPDATE_BACKUP_KEEP` — non-negative integer.
- `UPDATE_ENABLED` — `"true"` or `"false"` literal.

**Atomic write:**
```php
$tmp = self::PATH . '.tmp.' . bin2hex(random_bytes(8));
file_put_contents($tmp, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
chmod($tmp, 0600);
rename($tmp, self::PATH);  // POSIX atomic
```

**Permissions audit on boot:** if `data/config.json` exists and mode
isn't `0600`, log a warning through `Log::error()` (don't fail — some
operators chmod for shared-FS reasons).

### InstallGate (`system/Service/InstallGate.php`)

Single static predicate:

```php
final class InstallGate
{
    public static function isInstallRequired(\PDO $pdo): bool
    {
        $settings = new SettingsRepository($pdo);
        if ($settings->get('installed_at', '') !== '') return false;
        $users = new UserRepository($pdo);
        return $users->countApprovedAdmins() === 0;
    }
}
```

**Repository additions needed:**
- `UserRepository::countApprovedAdmins(): int` — `SELECT COUNT(*) FROM
  users WHERE role='admin' AND status='approved'`. Currently only
  `countAll()` exists at [UserRepository.php:85](../../../system/Repository/UserRepository.php#L85). Lands as
  part of step 3 (InstallGate) of the implementation plan.
- `SettingsRepository::get($key, '')` already returns empty string when
  missing (not `null`), so the predicate compares against `''` instead
  of `null`. No repo change needed for settings.

Both conditions must be true. Strict because:
- Fresh install (no DB rows, no settings) → wizard fires.
- `SEED_DEFAULT_ADMIN_*` seeded admin → wizard skips (admin exists).
- Admin deleted later → wizard does NOT re-fire (`installed_at` is
  set). Re-init requires manual `DELETE FROM settings WHERE
  key='installed_at'` — protects against anon takeover via
  accidentally-fresh-looking state.

Called once per request from [public/index.php](../../../public/index.php),
right after CSP headers and DB bootstrap, before router dispatch.

### Request gate (in `public/index.php`)

```php
$path = $_SERVER['REQUEST_URI'] ?? '/';
$isStatic  = str_starts_with($path, '/assets/');
$isInstall = str_starts_with($path, '/install');

if (!$isStatic && InstallGate::isInstallRequired($pdo)) {
    if (!$isInstall) {
        header('Location: /install');
        exit;
    }
    // /install/* — fall through to InstallController
} elseif ($isInstall) {
    http_response_code(404);
    exit;
}
```

Static asset requests bypass the gate so the wizard's CSS/JS load even
in the redirect state.

### InstallController

Six routes:

| Method | Path | View | Effect |
|---|---|---|---|
| GET | `/install` | `welcome.php` | Static welcome page |
| GET / POST | `/install/db` | `db.php` | Choose SQLite (default) or MySQL. POST tests connection, writes `DB_DSN`/`DB_USER`/`DB_PASSWORD` to config.json, runs `Migrations::run` on the new target if MySQL |
| GET / POST | `/install/admin` | `admin.php` | Form: name, email, password (with strength meter). POST inserts a `users` row with role=`admin`, status=`approved`, hashed password |
| GET / POST | `/install/security` | `security.php` | LOGIN_HASH toggle + auto-generate; APP_SECRET auto-generate; APP_URL editable (auto-detected from request). POST writes all three to config.json |
| GET / POST | `/install/integrations` | `integrations.php` | Optional Telegram. POST writes TG_BOT_TOKEN/TG_CHAT_ID OR skips |
| GET | `/install/done` | `done.php` | Summary + "Войти →" link. Sets `installed_at` in settings — wizard becomes 404 from now on |

**Resume logic:**

`InstallController::handle()` checks request path against state:
- No admin → must be at `/install/admin` or earlier. Redirect to
  `/install/admin` from later steps.
- No `APP_SECRET` in config.json → must be at `/install/security` or
  earlier. Redirect from later steps.
- All steps done → `installed_at` is set → wizard is 404.

This makes back-button safe and crash-recoverable.

**CSRF:** GET on `/install/*` skips CSRF (no token to verify pre-login).
POST verifies CSRF token. Token is generated and stored in the install
session, persistent across the wizard's tabs.

**Rate-limit:** Re-use `LoginThrottle` keyed `install:<ip-hash>`, 5
attempts/minute. Protects `/install/db` (MySQL credentials) and
`/install/admin` (admin email enumeration is not really a risk on a
fresh install, but the throttle is cheap insurance).

### Platform Settings (`/admin/compass/platform`)

New Compass tab. Sections mirror wizard steps:

1. **Database.** Read-only display of current driver + connection
   status. "Switch to MySQL" button opens the existing in-app
   `DbMigrator` wizard (no duplication).
2. **Authentication.** `LOGIN_HASH` toggle (on/off + regenerate). The
   regenerated value is shown ONCE in a modal with copy button + "Save
   the URL: `/login?hash=…`" instructions; on subsequent loads the
   value is masked. `APP_SECRET` — "Rotate" button only (never
   displayed). Rotating APP_SECRET invalidates all active form/poll
   time-traps — UI warns clearly before confirm.
3. **URLs & Proxies.** `APP_URL` (editable), `TRUSTED_PROXIES`
   (editable CSV).
4. **Telegram.** `TG_BOT_TOKEN` (shown as `bot****1234`, last 4 chars,
   "Replace" button to enter new), `TG_CHAT_ID` (shown plain — it's
   not a secret).
5. **Updates.** `UPDATE_ENABLED` toggle, `UPDATE_CHECK_INTERVAL` int,
   `UPDATE_BACKUP_KEEP` int.

Every section writes via ConfigStore. Auth: admin-only (existing
Compass guard).

## Data flow — fresh install happy path

```
GET /                                            (no admin yet)
  → InstallGate: required=true → 302 /install
GET /install                                     (welcome.php)
  → "Начать настройку →"
GET /install/db                                  (db.php)
  POST { driver: 'sqlite' }
  → no DSN write (default), redirect /install/admin
GET /install/admin                               (admin.php)
  POST { name, email, password }
  → INSERT INTO users (... role=admin, status=approved ...)
  → redirect /install/security
GET /install/security                            (security.php)
  POST { enable_login_hash: '1', app_url: 'https://...' }
  → ConfigStore::set([
       'LOGIN_HASH'  => bin2hex(random_bytes(8)),
       'APP_SECRET'  => bin2hex(random_bytes(32)),
       'APP_URL'     => 'https://...',
     ])
  → redirect /install/integrations
GET /install/integrations                        (integrations.php)
  POST { skip: '1' }   (or with TG vars)
  → redirect /install/done
GET /install/done
  → settings.set('installed_at', now())
  → render summary + "Войти →" link

# From this point:
GET /install        → 404
GET /install/*      → 404
GET /               → normal app, prompts /login
```

## Data flow — fresh install, MySQL chosen

```
GET /install/db
  POST { driver: 'mysql', host, port, db, user, password }
  → DbMigrator::testConnection(['host'=>..., ...])
  → if ok:
      ConfigStore::set([
        'DB_DSN' => 'mysql:host=...;port=...;dbname=...;charset=utf8mb4',
        'DB_USER' => ...,
        'DB_PASSWORD' => ...,
      ])
      # Re-bootstrap Connection from new env overlay:
      \App\Database\Connection::reset();
      $pdo = \App\Database\Connection::openFromEnv();
      Migrations::run(new SchemaBootstrap($pdo));
      redirect /install/admin
  → if fail:
      flash error + re-render /install/db with form values preserved
```

`Connection::reset()` is a new public static method that clears the
static `driverFor` SplObjectStorage map and any cached PDO instance
held by `Connection`. It is called only here (after config.json
mutation that changes the DSN) and in tests that need a fresh driver
binding. Lands as part of step 4 (InstallController) of the plan.

## Error handling

- **DB write failure during step 2 (admin create):** stay on
  `/install/admin`, flash the DB error, re-render with form values
  preserved.
- **MySQL test-connection failure:** render error in the form, no
  config.json mutation.
- **config.json write failure (permissions, disk full):** 500 error
  page with operator instructions (must be writable by web user,
  check `data/` mode). Step is not advanced.
- **Migrations fail on the chosen MySQL target:** UNDO — call
  `ConfigStore::unset(['DB_DSN', 'DB_USER', 'DB_PASSWORD'])`, render
  error on `/install/db`, instruct operator to drop the target DB and
  retry.
- **CSRF mismatch:** standard 419 page.
- **Rate-limit hit:** 429 with retry-after header.

## Testing

### Unit

`tests/unit/test_config_store.php`:
- `load()` on absent file returns `[]`.
- Round-trip: `set` → `load` returns the value.
- `set` rejects non-whitelist key.
- `set` rejects malformed `DB_DSN` (`file://x`, no scheme).
- `set` rejects invalid `APP_URL`.
- `set` casts ints/bools to strings.
- `unset` removes only listed keys, keeps others.
- Atomic write: kill `chmod` mid-write — file is either old or new,
  never partial. Test by simulating failure on the temp file (mock
  fs or shell out to `truncate`).
- File mode 0600 after write.

`tests/unit/test_install_gate.php`:
- `isInstallRequired` true on empty DB.
- `isInstallRequired` false after admin row inserted.
- `isInstallRequired` false after `settings.installed_at` set even
  with admin absent — strict for security.

### E2E

`tests/e2e/install.spec.ts`:
- Fresh DB → GET `/` → redirect `/install`.
- Walk all 6 steps with SQLite default. Assert: redirected away from
  `/install/*` after done; `GET /install` returns 404 after done.
- Walk with MySQL chosen — use the e2e MySQL service from existing
  CI workflow, OR mock test-connection in a CI flag. SQLite-only
  CI runs skip this case.
- Resume: drop off at step 3 (admin created but no `APP_SECRET` yet) →
  `GET /install` lands on `/install/security`.
- Re-run blocked: after `installed_at`, `GET /install/db` → 404.
- Anon takeover: simulate `installed_at` set + admin deleted → wizard
  does NOT re-fire.

`tests/e2e/platform_settings.spec.ts`:
- Admin opens `/admin/compass/platform`.
- Toggle `LOGIN_HASH`, regenerate → modal shows hash once, copy
  works, subsequent loads mask it.
- Rotate `APP_SECRET` → confirm-dialog → success.
- Update `TG_BOT_TOKEN` → masked display.

### Convention

Add to existing `test_controller_conventions.php`:
- Every `/install/*` POST has CSRF verification.
- `InstallController` does NOT call `App::make()` inside method bodies
  (post-9.1b constructor-injection convention).

## Security considerations

- **config.json contains secrets** (DB_PASSWORD, APP_SECRET,
  TG_BOT_TOKEN). MUST be in `.gitignore`. MUST be 0600 (validated on
  write; warned on read).
- **Wizard runs un-authenticated** by definition (no admin yet). The
  InstallGate must be the ONLY way to access `/install/*`. After
  `installed_at`, `/install/*` is hard 404 — no admin override.
- **CSRF** on every POST. Re-uses existing CSRF infra; token issued
  on GET `/install` and shared across steps via session.
- **Rate-limit** on `/install/*` POSTs to thwart bruteforce of MySQL
  credentials and admin enumeration.
- **`APP_SECRET` regeneration** invalidates active form/poll
  HMAC time-traps. Platform Settings UI MUST warn before confirm.
- **`DB_DSN` validation** prevents writing exotic DSN strings (no
  `mysql:host=evil.com` injected via someone tampering with the JSON
  manually + restart — but ConfigStore re-validates on `load()` if a
  strict mode flag is set; for now we only validate on `set()`).

## .gitignore additions

```
data/config.json
data/config.json.tmp.*
data/config.json.bak.*
```

## i18n

Wizard UI in en / pl / uk via existing `t()`. New keys under namespace
`install.*` and `platform.*`. Locale on the install path resolves from
`Accept-Language` (no user.locale yet); a `?locale=pl` query param
allows override. Locale toggle UI in the wizard layout shell.

## Implementation order

The plan should be sequenced so each step lands a complete, testable
slice:

1. **ConfigStore** — service + unit tests. No UI yet. Boot integration
   so `App::env()` reads merged values. Existing tests stay green
   because no config.json exists yet.
2. **`.gitignore`** + early operator docs (`docs/DEPLOYMENT.md`
   addendum on `data/config.json`).
3. **InstallGate** — service + unit test. Wire it into
   `public/index.php` behind a feature flag (`INSTALL_GATE_ENABLED`,
   default false). Existing installs unaffected; new installs gate
   when the flag flips.
4. **InstallController + 6 views** — wizard end-to-end. Manual smoke,
   then e2e spec.
5. **Platform Settings Compass tab** — shares ConfigStore.
6. **Flip default of `INSTALL_GATE_ENABLED` to true.** Document opt-out
   for advanced users who prefer .env-only flows.
7. **TODO #10 closed.** Tag v1.5.0.

## Risks

1. **Operators who already have a `.env`-driven install accidentally
   regress.** Mitigation: InstallGate checks both conditions (no
   admin AND no `installed_at`). Existing installs have an admin
   from day one, so wizard never fires. We also add `installed_at`
   for existing installs in a one-line migration so the
   feature-flag flip is safe even on freshly cloned dev environments
   without an admin.

2. **`data/config.json` permissions wrong on first write.** Operators
   on shared hosting may not have write access to `data/`. Mitigation:
   ConfigStore checks `is_writable(dirname(self::PATH))` at start of
   `set()` and returns a clear error directing operator to chmod
   `data/`. Documented in DEPLOYMENT.md.

3. **`APP_SECRET` rotation breaks active sessions / forms.**
   Mitigation: documented in UI; rotation is admin-initiated only.

4. **MySQL test-connection during install hangs forever.** Mitigation:
   `DbMigrator::testConnection` already sets `PDO::ATTR_TIMEOUT => 5`.

5. **CSRF token storage during install.** Mitigation: session is
   started in `public/index.php` before InstallGate runs; CSRF token
   lives in the same session as everywhere else.

## When done

- Operator on a fresh server can: `tar xzf otack-tasks.tar.gz`, point
  webserver at `public/`, open URL, complete wizard, log in. No file
  editing required.
- Existing operators with .env-only installs are unaffected. They can
  opt into Platform Settings at `/admin/compass/platform` whenever
  convenient.
- TODO #10 closed. Next release tag justified as `v1.5.0` (minor —
  user-facing feature addition, no breaking changes).
