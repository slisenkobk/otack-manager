# Install Wizard + Platform Settings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a browser-driven first-run wizard plus an admin Platform Settings tab that together cover all install-time configuration without `.env` edits.

**Architecture:** A new `ConfigStore` service overlays `data/config.json` on top of `$_ENV` at boot — wizard writes to that file, no `.env` mutation. `InstallGate` predicate (no admin + no `installed_at`) gates `/install/*` redirect. Six wizard steps with immediate-write per step. Same ConfigStore underpins the Platform Settings tab in Compass.

**Tech Stack:** PHP 8.2+ / hand-rolled DI / SQLite + MySQL 8 / vanilla JS / Playwright. No new build tooling.

**Spec parent:** [2026-06-04-install-wizard-design.md](../specs/2026-06-04-install-wizard-design.md).

**Branch:** `feat/install-wizard` off `main` (currently at the v1.4.0 follow-up commits).

**Prerequisites:**
- TODO #9 closed (`v1.4.0` shipped).
- All 4 CI jobs green on `main`.
- Working tree clean.

**Tag at wave close:** `v1.5.0` (minor — user-facing feature addition, no breaking changes).

---

## Conventions

- Each task ends in **one commit**. Test additions go in the same commit
  as the code they cover.
- Use TDD wherever the task adds logic (Tasks 1, 3, 4 helpers — write
  the failing test first).
- Each task should leave `main` (or the branch) in a working state. Tests
  green after every commit.
- Feature-flag the gate until Task 7 (`INSTALL_GATE_ENABLED` default
  false) so existing installs are unaffected during incremental
  rollout.

---

## File structure

**Created:**
- `system/Service/ConfigStore.php` (Task 1)
- `system/Service/InstallGate.php` (Task 3)
- `system/Controller/InstallController.php` (Task 4)
- `tests/unit/test_config_store.php` (Task 1)
- `tests/unit/test_install_gate.php` (Task 3)
- `tests/unit/test_user_repository_count_admins.php` (Task 3 — small)
- `tests/e2e/install.spec.ts` (Task 4 + Task 7)
- `tests/e2e/platform-settings.spec.ts` (Task 5)
- `views/install/welcome.php` (Task 4)
- `views/install/db.php` (Task 4)
- `views/install/admin.php` (Task 4)
- `views/install/security.php` (Task 4)
- `views/install/integrations.php` (Task 4)
- `views/install/done.php` (Task 4)
- `views/layouts/install.php` (Task 4)
- `views/admin/compass/platform.php` (Task 5)

**Modified:**
- `system/Bootstrap/Container.php` (Task 1) — load ConfigStore overlay
- `system/Database/Connection.php` (Task 4) — add `reset()` public static
- `system/Repository/UserRepository.php` (Task 3) — add `countApprovedAdmins()`
- `system/Controller/CompassController.php` (Task 5) — add `platform()` + `updatePlatform()`
- `system/Bootstrap/Routes.php` (Tasks 4, 5) — wire `/install/*` + `/admin/compass/platform`
- `public/index.php` (Task 3 + 7) — InstallGate request middleware
- `views/partials/compass-tabs.php` (Task 5) — new "Platform" tab
- `.gitignore` (Task 2) — `data/config.json*`
- `docs/DEPLOYMENT.md` (Task 2) — config.json operator notes
- `system/i18n/{en,pl,uk}.php` (Tasks 4, 5) — `install.*` / `platform.*` keys
- `system/version.php` (Task 7) — bump to 1.5.0
- `TODO.md` (Task 7) — mark #10 done

---

## Task 1 — `ConfigStore` service + boot integration

**Files:**
- Create: `system/Service/ConfigStore.php`
- Create: `tests/unit/test_config_store.php`
- Modify: `system/Bootstrap/Container.php` (load overlay early)

- [ ] **Step 1: Write the failing tests for ConfigStore.**

```php
<?php
// tests/unit/test_config_store.php
declare(strict_types=1);

use App\Service\ConfigStore;

function cs_with_tmp_path(): array {
    $path = sys_get_temp_dir() . '/cs-test-' . bin2hex(random_bytes(4)) . '.json';
    register_shutdown_function(fn() => @unlink($path));
    $cs = new ConfigStore($path);
    return [$cs, $path];
}

it('load() on absent file returns empty array', function () {
    [$cs] = cs_with_tmp_path();
    assert_eq([], $cs->load());
    assert_true(!$cs->exists());
});

it('set + load round-trip', function () {
    [$cs, $path] = cs_with_tmp_path();
    $cs->set(['APP_URL' => 'https://example.com', 'TG_CHAT_ID' => '-100123']);
    $loaded = $cs->load();
    assert_eq('https://example.com', $loaded['APP_URL']);
    assert_eq('-100123', $loaded['TG_CHAT_ID']);
    assert_true($cs->exists());
});

it('set rejects non-whitelist key', function () {
    [$cs] = cs_with_tmp_path();
    $threw = false;
    try { $cs->set(['EVIL_KEY' => 'x']); }
    catch (\InvalidArgumentException $e) {
        $threw = str_contains($e->getMessage(), 'EVIL_KEY');
    }
    assert_true($threw);
});

it('set rejects malformed DB_DSN', function () {
    [$cs] = cs_with_tmp_path();
    foreach (['file:///etc/passwd', 'no-scheme', 'http://foo'] as $bad) {
        $threw = false;
        try { $cs->set(['DB_DSN' => $bad]); }
        catch (\InvalidArgumentException $e) { $threw = true; }
        assert_true($threw, "expected reject of $bad");
    }
    // Sqlite and mysql schemes accepted.
    $cs->set(['DB_DSN' => 'sqlite::memory:']);
    $cs->set(['DB_DSN' => 'mysql:host=127.0.0.1;dbname=x']);
});

it('set rejects invalid APP_URL', function () {
    [$cs] = cs_with_tmp_path();
    $threw = false;
    try { $cs->set(['APP_URL' => 'javascript:alert(1)']); }
    catch (\InvalidArgumentException $e) { $threw = true; }
    assert_true($threw);
});

it('set casts bool/int to string', function () {
    [$cs, $path] = cs_with_tmp_path();
    $cs->set(['UPDATE_ENABLED' => true, 'UPDATE_CHECK_INTERVAL' => 3600]);
    $loaded = $cs->load();
    assert_eq('true', $loaded['UPDATE_ENABLED']);
    assert_eq('3600', $loaded['UPDATE_CHECK_INTERVAL']);
});

it('unset removes listed keys, preserves others', function () {
    [$cs] = cs_with_tmp_path();
    $cs->set(['APP_URL' => 'https://x', 'TG_CHAT_ID' => '1']);
    $cs->unset(['APP_URL']);
    $loaded = $cs->load();
    assert_true(!isset($loaded['APP_URL']));
    assert_eq('1', $loaded['TG_CHAT_ID']);
});

it('write sets file mode 0600', function () {
    [$cs, $path] = cs_with_tmp_path();
    $cs->set(['APP_URL' => 'https://x']);
    $mode = fileperms($path) & 0777;
    assert_eq(0600, $mode);
});

it('get returns null for absent key, string for present key', function () {
    [$cs] = cs_with_tmp_path();
    assert_eq(null, $cs->get('APP_URL'));
    $cs->set(['APP_URL' => 'https://x']);
    assert_eq('https://x', $cs->get('APP_URL'));
});
```

- [ ] **Step 2: Run the new tests to verify they fail.**

Run: `php tests/run.php tests/unit 2>&1 | tail -3`
Expected: `309 passed, N failed` where N matches the new test count (class not found).

- [ ] **Step 3: Implement ConfigStore.**

```php
<?php
// system/Service/ConfigStore.php
declare(strict_types=1);
namespace App\Service;

/**
 * Persistent overlay over $_ENV / .env. Lives at data/config.json (mode
 * 0600). Read on every boot through Container::register(); written by
 * the install wizard and Platform Settings tab.
 *
 * Precedence: ConfigStore values win over .env. Keys must be on
 * ALLOWED_KEYS — anything else is rejected on set(), so the surface
 * cannot be expanded via a tampered config.json file (re-validated on
 * load() too, see below).
 */
final class ConfigStore
{
    public const ALLOWED_KEYS = [
        'DB_DSN', 'DB_USER', 'DB_PASSWORD', 'DB_CHARSET', 'DB_COLLATION',
        'APP_URL', 'APP_SECRET', 'LOGIN_HASH',
        'TG_BOT_TOKEN', 'TG_CHAT_ID',
        'TRUSTED_PROXIES',
        'UPDATE_ENABLED', 'UPDATE_CHECK_INTERVAL', 'UPDATE_BACKUP_KEEP',
    ];

    public function __construct(private string $path = '')
    {
        if ($this->path === '') {
            $this->path = APP_ROOT . '/data/config.json';
        }
    }

    public function exists(): bool { return is_file($this->path); }

    /** @return array<string, string> */
    public function load(): array
    {
        if (!is_file($this->path)) return [];
        $raw = @file_get_contents($this->path);
        if ($raw === false || $raw === '') return [];
        $data = json_decode($raw, true);
        if (!is_array($data)) return [];
        $out = [];
        foreach ($data as $k => $v) {
            if (!in_array($k, self::ALLOWED_KEYS, true)) continue;
            $out[$k] = (string)$v;
        }
        return $out;
    }

    public function get(string $key): ?string
    {
        $all = $this->load();
        return $all[$key] ?? null;
    }

    /** @param array<string, string|int|bool> $kv */
    public function set(array $kv): void
    {
        $current = $this->load();
        foreach ($kv as $k => $v) {
            if (!in_array($k, self::ALLOWED_KEYS, true)) {
                throw new \InvalidArgumentException("ConfigStore: key not in allow-list: $k");
            }
            $current[$k] = self::validate($k, $v);
        }
        $this->write($current);
    }

    public function unset(array $keys): void
    {
        $current = $this->load();
        foreach ($keys as $k) {
            unset($current[$k]);
        }
        $this->write($current);
    }

    private static function validate(string $key, mixed $value): string
    {
        if (is_bool($value)) $value = $value ? 'true' : 'false';
        elseif (is_int($value) || is_float($value)) $value = (string)$value;
        elseif (!is_string($value)) {
            throw new \InvalidArgumentException("ConfigStore: $key must be scalar");
        }
        $value = (string)$value;
        switch ($key) {
            case 'DB_DSN':
                if (!preg_match('/^(sqlite|mysql):/', $value)) {
                    throw new \InvalidArgumentException("ConfigStore: DB_DSN must start with sqlite: or mysql:");
                }
                break;
            case 'APP_URL':
                $ok = filter_var($value, FILTER_VALIDATE_URL) !== false;
                $ok = $ok && preg_match('#^https?://#', $value) === 1;
                if (!$ok) throw new \InvalidArgumentException("ConfigStore: APP_URL must be a valid http(s) URL");
                break;
            case 'TG_CHAT_ID':
                if ($value !== '' && !preg_match('/^-?\d+$/', $value)) {
                    throw new \InvalidArgumentException("ConfigStore: TG_CHAT_ID must be a numeric Telegram chat id");
                }
                break;
            case 'TRUSTED_PROXIES':
                if ($value !== '') {
                    foreach (explode(',', $value) as $hop) {
                        $hop = trim($hop);
                        if ($hop === '') continue;
                        if (!preg_match('#^[0-9a-fA-F:.]+(/\d{1,3})?$#', $hop)) {
                            throw new \InvalidArgumentException("ConfigStore: TRUSTED_PROXIES entry malformed: $hop");
                        }
                    }
                }
                break;
            case 'UPDATE_CHECK_INTERVAL':
            case 'UPDATE_BACKUP_KEEP':
                if (!preg_match('/^\d+$/', $value)) {
                    throw new \InvalidArgumentException("ConfigStore: $key must be a non-negative integer");
                }
                break;
            case 'UPDATE_ENABLED':
                if (!in_array($value, ['true', 'false'], true)) {
                    throw new \InvalidArgumentException("ConfigStore: UPDATE_ENABLED must be 'true' or 'false'");
                }
                break;
        }
        return $value;
    }

    private function write(array $data): void
    {
        $dir = dirname($this->path);
        if (!is_writable($dir)) {
            throw new \RuntimeException("ConfigStore: $dir is not writable");
        }
        $tmp = $this->path . '.tmp.' . bin2hex(random_bytes(8));
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException("ConfigStore: failed to encode config");
        }
        if (file_put_contents($tmp, $json) === false) {
            throw new \RuntimeException("ConfigStore: failed to write $tmp");
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new \RuntimeException("ConfigStore: failed to rename $tmp to {$this->path}");
        }
    }
}
```

- [ ] **Step 4: Wire ConfigStore into boot.**

Modify `system/Bootstrap/Container.php` `register()` — add at the very top of the method body, before any singleton registration:

```php
// Overlay config.json on top of .env so App::env() picks up wizard
// values. ConfigStore re-validates against ALLOWED_KEYS on read, so
// a tampered file cannot inject arbitrary env vars.
$overlay = (new \App\Service\ConfigStore())->load();
foreach ($overlay as $k => $v) {
    $_ENV[$k] = $v;
    putenv("$k=$v");
}
```

- [ ] **Step 5: Run all unit tests.**

Run: `php tests/run.php tests/unit 2>&1 | tail -3`
Expected: `N passed, 0 failed` where N is the pre-task count + 8 (the new tests).

- [ ] **Step 6: Commit.**

```bash
git add system/Service/ConfigStore.php tests/unit/test_config_store.php system/Bootstrap/Container.php
git commit -m "feat(config): ConfigStore — data/config.json overlay over .env"
```

---

## Task 2 — `.gitignore` + DEPLOYMENT.md operator notes

**Files:**
- Modify: `.gitignore`
- Modify: `docs/DEPLOYMENT.md`

- [ ] **Step 1: Append to `.gitignore`.**

```
# Wizard-managed env overlay (Task #10). Contains secrets — never commit.
data/config.json
data/config.json.tmp.*
data/config.json.bak.*
```

Append at the end of the file.

- [ ] **Step 2: Add a section to `docs/DEPLOYMENT.md` after the PHP-requirements section.**

```markdown
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
```

- [ ] **Step 3: Commit.**

```bash
git add .gitignore docs/DEPLOYMENT.md
git commit -m "docs+ignore: data/config.json + operator notes for install wizard"
```

---

## Task 3 — `InstallGate` predicate + `UserRepository::countApprovedAdmins`

**Files:**
- Create: `system/Service/InstallGate.php`
- Create: `tests/unit/test_install_gate.php`
- Modify: `system/Repository/UserRepository.php` (add `countApprovedAdmins()`)
- Modify: `public/index.php` (wire the gate behind the feature flag)

- [ ] **Step 1: Write the failing tests for InstallGate + countApprovedAdmins.**

```php
<?php
// tests/unit/test_install_gate.php
declare(strict_types=1);

use App\Database\Connection;
use App\Service\InstallGate;
use App\Repository\UserRepository;
use App\Repository\SettingsRepository;

function install_gate_fresh_pdo(): \PDO {
    $pdo = Connection::open('sqlite::memory:');
    $pdo->exec("CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        email TEXT NOT NULL,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL,
        status TEXT NOT NULL,
        locale TEXT,
        avatar TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    return $pdo;
}

it('isInstallRequired true on empty DB', function () {
    $pdo = install_gate_fresh_pdo();
    assert_true(InstallGate::isInstallRequired($pdo));
});

it('isInstallRequired false once an approved admin exists', function () {
    $pdo = install_gate_fresh_pdo();
    $pdo->exec("INSERT INTO users (name, email, password_hash, role, status) "
             . "VALUES ('Admin', 'a@b.com', 'hash', 'admin', 'approved')");
    assert_true(!InstallGate::isInstallRequired($pdo));
});

it('isInstallRequired false once installed_at is set, even with no admin', function () {
    $pdo = install_gate_fresh_pdo();
    $pdo->exec("INSERT INTO settings (key, value) VALUES ('installed_at', '2026-06-04T12:00:00Z')");
    assert_true(!InstallGate::isInstallRequired($pdo));
});

it('isInstallRequired true with pending non-admin only', function () {
    $pdo = install_gate_fresh_pdo();
    $pdo->exec("INSERT INTO users (name, email, password_hash, role, status) "
             . "VALUES ('P', 'p@x', 'h', 'employee', 'pending')");
    assert_true(InstallGate::isInstallRequired($pdo));
});

it('UserRepository::countApprovedAdmins counts only approved admins', function () {
    $pdo = install_gate_fresh_pdo();
    $pdo->exec("INSERT INTO users (name, email, password_hash, role, status) VALUES
        ('A', 'a1@x', 'h', 'admin', 'approved'),
        ('B', 'a2@x', 'h', 'admin', 'pending'),
        ('C', 'm@x',  'h', 'manager', 'approved'),
        ('D', 'a3@x', 'h', 'admin', 'approved')");
    $repo = new UserRepository($pdo);
    assert_eq(2, $repo->countApprovedAdmins());
});
```

- [ ] **Step 2: Run the new tests to verify they fail.**

Run: `php tests/run.php tests/unit 2>&1 | tail -3`
Expected: failing on `InstallGate` not found and `countApprovedAdmins` not found.

- [ ] **Step 3: Implement `UserRepository::countApprovedAdmins()`.**

Add the method to `system/Repository/UserRepository.php` near the existing `countAll()` method (around line 85):

```php
/**
 * Count users whose role is 'admin' AND status is 'approved'.
 * Used by InstallGate to decide whether the wizard should fire.
 */
public function countApprovedAdmins(): int
{
    $stmt = $this->pdo->prepare(
        "SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'approved'"
    );
    $stmt->execute();
    return (int)$stmt->fetchColumn();
}
```

- [ ] **Step 4: Implement InstallGate.**

```php
<?php
// system/Service/InstallGate.php
declare(strict_types=1);
namespace App\Service;

use App\Repository\SettingsRepository;
use App\Repository\UserRepository;

/**
 * Single-predicate gate: should the install wizard fire?
 *
 * Both conditions must be true for the wizard to fire:
 *  - settings.installed_at is empty
 *  - users table has 0 approved admins
 *
 * Strict because either signal alone is recoverable to "fresh state":
 *  - Admin deleted by hand → installed_at still set → no wizard
 *    re-run (operator must clear installed_at manually if they really
 *    want the anon-accessible wizard back).
 *  - Tarball cloned into a fresh DB → no admin yet, no installed_at
 *    yet → wizard fires.
 */
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

- [ ] **Step 5: Wire the gate behind a feature flag in `public/index.php`.**

Find the section right after CSP headers + DB bootstrap. Add this block before router dispatch (the exact location is after `Container::register(...)` and `$pdo = App::make('db')`):

```php
// Install wizard gate (TODO #10). Behind a feature flag during the
// rollout so existing .env installs aren't disturbed mid-deploy.
// Default flips to true in step 7 of the implementation plan.
$gateEnabled = filter_var(\App\App::env('INSTALL_GATE_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
if ($gateEnabled) {
    $reqPath  = $_SERVER['REQUEST_URI'] ?? '/';
    $isStatic = str_starts_with($reqPath, '/assets/');
    $isInstall = str_starts_with($reqPath, '/install');
    if (!$isStatic) {
        if (\App\Service\InstallGate::isInstallRequired(\App\App::make('db'))) {
            if (!$isInstall) {
                header('Location: /install');
                exit;
            }
        } elseif ($isInstall) {
            http_response_code(404);
            exit;
        }
    }
}
```

- [ ] **Step 6: Add `INSTALL_GATE_ENABLED` to `.env.example`.**

Append after the `UPDATE_*` block:

```
# Install wizard (TODO #10). When true and there is no approved admin yet,
# all non-static requests are redirected to /install. Default false during
# the v1.4 → v1.5 rollout; flipped to true when v1.5.0 ships. Set false
# explicitly if you prefer .env-only operator workflow.
INSTALL_GATE_ENABLED=false
```

- [ ] **Step 7: Run all unit tests.**

Run: `php tests/run.php tests/unit 2>&1 | tail -3`
Expected: all green.

- [ ] **Step 8: Commit.**

```bash
git add system/Service/InstallGate.php tests/unit/test_install_gate.php \
        system/Repository/UserRepository.php public/index.php .env.example
git commit -m "feat(install): InstallGate predicate + countApprovedAdmins + feature-flag wire"
```

---

## Task 4 — `InstallController` + 6 wizard views + `Connection::reset()`

**Files:**
- Create: `system/Controller/InstallController.php`
- Modify: `system/Database/Connection.php` (add `reset()`)
- Modify: `system/Bootstrap/Routes.php`
- Create: `views/layouts/install.php`
- Create: `views/install/{welcome,db,admin,security,integrations,done}.php`
- Modify: `system/i18n/{en,pl,uk}.php` (add `install.*` keys)
- Create: `tests/e2e/install.spec.ts`

- [ ] **Step 1: Add `Connection::reset()` as a public static.**

In `system/Database/Connection.php`, add after the existing `driverFor()` method (around line 80):

```php
/**
 * Clear the static driver map and any cached PDO held by this class.
 * Used after the install wizard mutates DB_DSN so the next
 * openFromEnv() call returns a fresh connection bound to the new
 * driver. Tests also call this between fixtures to keep instances
 * isolated.
 */
public static function reset(): void
{
    self::$driverFor = null;
}
```

(Check if there is also a cached PDO singleton in the same class — if
yes, null it here too. Otherwise the SplObjectStorage reset is enough,
since `openFromEnv()` builds a fresh PDO every call.)

- [ ] **Step 2: Write the e2e spec FIRST (happy-path skeleton).**

```ts
// tests/e2e/install.spec.ts
import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';

const ROOT = path.resolve(__dirname, '../..');
const CONFIG_PATH = path.join(ROOT, 'data/config.test.json');

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  fs.rmSync(path.join(ROOT, 'data/app.test.sqlite'), { force: true });
  fs.rmSync(path.join(ROOT, 'data/.schema.test'), { recursive: true, force: true });
  fs.rmSync(CONFIG_PATH, { force: true });
  // Feature flag for the e2e server is enabled via the playwright config env block.
});

test.afterAll(() => {
  fs.rmSync(CONFIG_PATH, { force: true });
});

test('redirects to /install on first hit when no admin exists', async ({ page }) => {
  const r = await page.goto('/');
  expect(r?.url()).toMatch(/\/install$/);
});

test('walks all 6 steps with SQLite default', async ({ page }) => {
  await page.goto('/install');
  await page.click('[data-action="install-start"]');
  await expect(page).toHaveURL('/install/db');

  // SQLite is the default; submit without choosing MySQL.
  await page.click('button[type=submit][data-driver=sqlite]');
  await expect(page).toHaveURL('/install/admin');

  await page.fill('input[name=name]',     'Alice Admin');
  await page.fill('input[name=email]',    'alice@example.com');
  await page.fill('input[name=password]', 'password123');
  await page.click('button[type=submit]');
  await expect(page).toHaveURL('/install/security');

  await page.check('input[name=enable_login_hash]');
  await page.click('button[type=submit]');
  await expect(page).toHaveURL('/install/integrations');

  await page.click('button[data-action="skip-integrations"]');
  await expect(page).toHaveURL('/install/done');

  // After done, /install/* is gone.
  const r = await page.goto('/install/db');
  expect(r?.status()).toBe(404);

  // Normal app accessible.
  await page.goto('/');
  expect(page.url()).toMatch(/\/login/);
});

test('resume after dropping off at step 3', async ({ page }) => {
  // (Skipped on a fresh suite — exercised by setting installed_at half-way.
  // Filled in once basic path passes.)
  test.skip();
});

test('install routes 404 after installed_at is set', async ({ page }) => {
  // Subsequent runs in the same suite: already installed.
  const r = await page.goto('/install');
  expect(r?.status()).toBe(404);
});
```

Update `playwright.config.ts` `webServer.env` block to set
`INSTALL_GATE_ENABLED=true` and `CONFIG_STORE_PATH=data/config.test.json`
for the e2e run, so the wizard exercises an isolated config file. (If
the config path override needs a new env var, expose it on
`ConfigStore::__construct` — already supported.)

Run: `make test-clean && npx playwright test --project=chromium tests/e2e/install.spec.ts 2>&1 | tail -10`
Expected: every test fails (routes don't exist yet).

- [ ] **Step 3: Add i18n keys (en first, pl/uk parity comes from the existing
  i18n parity convention test).**

In `system/i18n/en.php`, append:

```php
    // Install wizard (Task #10)
    'install.title'              => 'Welcome to Otack',
    'install.subtitle'           => 'Let\'s set up your installation.',
    'install.start'              => 'Start setup →',
    'install.step.db'            => 'Database',
    'install.step.admin'         => 'Admin user',
    'install.step.security'      => 'Security',
    'install.step.integrations'  => 'Integrations',
    'install.step.done'          => 'Done',
    'install.db.heading'         => 'Choose your database',
    'install.db.sqlite'          => 'SQLite (zero-config, file-based)',
    'install.db.mysql'           => 'MySQL 8',
    'install.db.host'            => 'Host',
    'install.db.port'            => 'Port',
    'install.db.dbname'          => 'Database name',
    'install.db.user'            => 'User',
    'install.db.password'        => 'Password',
    'install.db.test'            => 'Test connection',
    'install.db.next'            => 'Continue →',
    'install.admin.heading'      => 'Create the admin account',
    'install.admin.name'         => 'Name',
    'install.admin.email'        => 'Email',
    'install.admin.password'     => 'Password',
    'install.admin.submit'       => 'Create admin →',
    'install.security.heading'   => 'Security settings',
    'install.security.login_hash_label' => 'Protect /login with a URL hash',
    'install.security.login_hash_hint'  => 'Anyone with the hashed URL can reach the login page. Useful when you don\'t want strangers seeing the form.',
    'install.security.app_url'   => 'Application URL',
    'install.security.next'      => 'Continue →',
    'install.integrations.heading'    => 'Optional integrations',
    'install.integrations.tg_token'   => 'Telegram bot token',
    'install.integrations.tg_chat_id' => 'Telegram chat ID',
    'install.integrations.test'  => 'Send test message',
    'install.integrations.skip'  => 'Skip — set up later',
    'install.integrations.save'  => 'Save & continue →',
    'install.done.heading'       => 'Setup complete!',
    'install.done.summary'       => 'Otack is ready. Sign in to start.',
    'install.done.sign_in'       => 'Sign in →',
    'install.error.db_test'      => 'Connection failed: :msg',
    'install.error.config_write' => 'Could not write data/config.json: :msg',
    'install.error.email_taken'  => 'A user with that email already exists.',
```

Add stub PL + UK entries (same keys, English values copied over so the
parity convention test passes; translation tightened later).

- [ ] **Step 4: Create `views/layouts/install.php`.**

```php
<?php
// $title, $currentStep ∈ {welcome, db, admin, security, integrations, done}
$steps = ['db', 'admin', 'security', 'integrations', 'done'];
$active = array_search($currentStep, $steps, true);
?><!DOCTYPE html>
<html lang="<?= e(user_locale()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> — <?= e(app_name()) ?></title>
  <link rel="stylesheet" href="<?= e(asset_url('/assets/css/tokens.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset_url('/assets/css/base.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset_url('/assets/css/forms.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset_url('/assets/css/utilities.css')) ?>">
  <?= app_brand_style_tag() ?>
  <script type="module" src="<?= e(asset_url('/assets/js/theme.js')) ?>"></script>
</head>
<body class="auth-page">
<main class="auth-page__main">
  <header class="install-header">
    <h1 class="display"><?= e($title) ?></h1>
    <?php if ($currentStep !== 'welcome'): ?>
    <ol class="install-steps">
      <?php foreach ($steps as $i => $key): ?>
        <li class="install-steps__item<?= $i <= $active ? ' is-done' : '' ?><?= $i === $active ? ' is-current' : '' ?>">
          <?= e(t('install.step.' . str_replace('-', '_', $key))) ?>
        </li>
      <?php endforeach; ?>
    </ol>
    <?php endif; ?>
  </header>
  <?= $content ?? '' ?>
</main>
</body>
</html>
```

Add minimal CSS to `utilities.css` (or a new `install.css` — choose what
keeps the diff small):

```css
.install-header { margin-bottom: var(--space-12); }
.install-steps { list-style: none; padding: 0; margin: var(--space-8) 0; display: flex; gap: var(--space-4); font-family: var(--font-mono); font-size: var(--fz-xs); letter-spacing: var(--ls-mono); color: var(--text-muted); }
.install-steps__item.is-done    { color: var(--text-2); }
.install-steps__item.is-current { color: var(--accent); font-weight: 600; }
```

- [ ] **Step 5: Create the 6 install views.**

`views/install/welcome.php`:

```php
<?php
$title = t('install.title');
$currentStep = 'welcome';
ob_start();
?>
<p class="lede"><?= e(t('install.subtitle')) ?></p>
<form method="post" action="/install/start" class="install-form">
  <?= csrf_field($csrfToken) ?>
  <button type="submit" class="btn btn--primary" data-action="install-start">
    <?= e(t('install.start')) ?>
  </button>
</form>
<?php $content = ob_get_clean();
require __DIR__ . '/../layouts/install.php';
```

`views/install/db.php`:

```php
<?php
$title = t('install.title');
$currentStep = 'db';
ob_start();
?>
<h2 class="page-section-title"><?= e(t('install.db.heading')) ?></h2>

<?php if (!empty($flash)): ?>
  <div class="alert alert--danger"><?= e($flash) ?></div>
<?php endif; ?>

<form method="post" action="/install/db" class="install-form">
  <?= csrf_field($csrfToken) ?>
  <fieldset class="form-fieldset">
    <legend><?= e(t('install.db.sqlite')) ?></legend>
    <button type="submit" name="driver" value="sqlite" data-driver="sqlite" class="btn btn--primary">
      <?= e(t('install.db.next')) ?>
    </button>
  </fieldset>
  <fieldset class="form-fieldset">
    <legend><?= e(t('install.db.mysql')) ?></legend>
    <label><?= e(t('install.db.host')) ?> <input class="input" name="host" value="127.0.0.1"></label>
    <label><?= e(t('install.db.port')) ?> <input class="input" name="port" type="number" value="3306"></label>
    <label><?= e(t('install.db.dbname')) ?> <input class="input" name="dbname" required></label>
    <label><?= e(t('install.db.user')) ?> <input class="input" name="user" required></label>
    <label><?= e(t('install.db.password')) ?> <input class="input" type="password" name="password"></label>
    <button type="submit" name="driver" value="mysql" data-driver="mysql" class="btn btn--secondary">
      <?= e(t('install.db.test')) ?> + <?= e(t('install.db.next')) ?>
    </button>
  </fieldset>
</form>
<?php $content = ob_get_clean();
require __DIR__ . '/../layouts/install.php';
```

`views/install/admin.php`:

```php
<?php
$title = t('install.title');
$currentStep = 'admin';
ob_start();
?>
<h2 class="page-section-title"><?= e(t('install.admin.heading')) ?></h2>

<?php if (!empty($flash)): ?>
  <div class="alert alert--danger"><?= e($flash) ?></div>
<?php endif; ?>

<form method="post" action="/install/admin" class="install-form">
  <?= csrf_field($csrfToken) ?>
  <label><?= e(t('install.admin.name')) ?>
    <input class="input" name="name" required minlength="2" value="<?= e($form['name'] ?? '') ?>">
  </label>
  <label><?= e(t('install.admin.email')) ?>
    <input class="input" name="email" type="email" required value="<?= e($form['email'] ?? '') ?>">
  </label>
  <label><?= e(t('install.admin.password')) ?>
    <input class="input" name="password" type="password" required minlength="8">
  </label>
  <button type="submit" class="btn btn--primary"><?= e(t('install.admin.submit')) ?></button>
</form>
<?php $content = ob_get_clean();
require __DIR__ . '/../layouts/install.php';
```

`views/install/security.php`:

```php
<?php
$title = t('install.title');
$currentStep = 'security';
$autoUrl = $detectedAppUrl ?? '';
ob_start();
?>
<h2 class="page-section-title"><?= e(t('install.security.heading')) ?></h2>
<form method="post" action="/install/security" class="install-form">
  <?= csrf_field($csrfToken) ?>
  <label class="checkbox-row">
    <input type="checkbox" name="enable_login_hash" value="1">
    <span>
      <strong><?= e(t('install.security.login_hash_label')) ?></strong>
      <small class="muted"><?= e(t('install.security.login_hash_hint')) ?></small>
    </span>
  </label>
  <label><?= e(t('install.security.app_url')) ?>
    <input class="input" name="app_url" type="url" required value="<?= e($autoUrl) ?>">
  </label>
  <button type="submit" class="btn btn--primary"><?= e(t('install.security.next')) ?></button>
</form>
<?php $content = ob_get_clean();
require __DIR__ . '/../layouts/install.php';
```

`views/install/integrations.php`:

```php
<?php
$title = t('install.title');
$currentStep = 'integrations';
ob_start();
?>
<h2 class="page-section-title"><?= e(t('install.integrations.heading')) ?></h2>
<form method="post" action="/install/integrations" class="install-form">
  <?= csrf_field($csrfToken) ?>
  <label><?= e(t('install.integrations.tg_token')) ?>
    <input class="input" type="password" name="tg_token" autocomplete="off">
  </label>
  <label><?= e(t('install.integrations.tg_chat_id')) ?>
    <input class="input" name="tg_chat_id" pattern="-?\d+">
  </label>
  <div class="actions">
    <button type="submit" name="action" value="save" class="btn btn--primary">
      <?= e(t('install.integrations.save')) ?>
    </button>
    <button type="submit" name="action" value="skip" data-action="skip-integrations" class="btn btn--ghost">
      <?= e(t('install.integrations.skip')) ?>
    </button>
  </div>
</form>
<?php $content = ob_get_clean();
require __DIR__ . '/../layouts/install.php';
```

`views/install/done.php`:

```php
<?php
$title = t('install.title');
$currentStep = 'done';
ob_start();
?>
<h2 class="page-section-title"><?= e(t('install.done.heading')) ?></h2>
<p class="lede"><?= e(t('install.done.summary')) ?></p>
<ul class="install-summary">
  <li>DB: <strong><?= e($summary['db']) ?></strong></li>
  <li>Admin: <strong><?= e($summary['admin_email']) ?></strong></li>
  <li>LOGIN_HASH: <strong><?= e($summary['login_hash'] ? 'on' : 'off') ?></strong></li>
  <li>Telegram: <strong><?= e($summary['telegram'] ? 'configured' : 'skipped') ?></strong></li>
</ul>
<a href="<?= e($summary['sign_in_url']) ?>" class="btn btn--primary"><?= e(t('install.done.sign_in')) ?></a>
<?php $content = ob_get_clean();
require __DIR__ . '/../layouts/install.php';
```

- [ ] **Step 6: Implement InstallController.**

```php
<?php
// system/Controller/InstallController.php
declare(strict_types=1);
namespace App\Controller;

use App\Http\{Request, Response, Csrf};
use App\Service\{ConfigStore, InstallGate};
use App\Database\{Connection, Migrations, SchemaBootstrap, DbMigrator};
use App\Repository\{UserRepository, SettingsRepository};

final class InstallController
{
    public function __construct(
        private \PDO $pdo,
        private Csrf $csrf,
        private ConfigStore $config,
        private UserRepository $users,
        private SettingsRepository $settings,
    ) {}

    private function guardGet(): void
    {
        if (!InstallGate::isInstallRequired($this->pdo)) {
            http_response_code(404);
            exit;
        }
    }

    private function guardPost(Request $req): void
    {
        $this->guardGet();
        if (!$this->csrf->verify($req->post['_csrf'] ?? '')) {
            http_response_code(419);
            echo 'CSRF token mismatch';
            exit;
        }
    }

    /** Resume helper — redirect to the earliest unfinished step. */
    private function resumeTo(string $expected, Request $req): ?string
    {
        $hasAdmin     = $this->users->countApprovedAdmins() > 0;
        $hasAppSecret = $this->config->get('APP_SECRET') !== null;
        $order = ['welcome' => 0, 'db' => 1, 'admin' => 2, 'security' => 3, 'integrations' => 4, 'done' => 5];
        $rank  = $order[$expected] ?? 99;

        if (!$hasAdmin && $rank > $order['admin']) return '/install/admin';
        if (!$hasAppSecret && $rank > $order['security']) return '/install/security';
        return null;
    }

    public function welcome(Request $req): Response
    {
        $this->guardGet();
        return Response::view('install/welcome', ['csrfToken' => $this->csrf->token()]);
    }

    public function start(Request $req): Response
    {
        $this->guardPost($req);
        return Response::redirect('/install/db');
    }

    public function db(Request $req): Response
    {
        $this->guardGet();
        $redirect = $this->resumeTo('db', $req);
        if ($redirect) return Response::redirect($redirect);
        return Response::view('install/db', ['csrfToken' => $this->csrf->token(), 'flash' => $req->get['flash'] ?? null]);
    }

    public function dbSubmit(Request $req): Response
    {
        $this->guardPost($req);
        $driver = $req->post['driver'] ?? 'sqlite';
        if ($driver === 'sqlite') {
            return Response::redirect('/install/admin');
        }
        // MySQL — test connection then save + re-bootstrap.
        $cfg = [
            'host'     => trim($req->post['host']     ?? '127.0.0.1'),
            'port'     => (int)($req->post['port']    ?? 3306),
            'db'       => trim($req->post['dbname']   ?? ''),
            'user'     => trim($req->post['user']     ?? ''),
            'password' => (string)($req->post['password'] ?? ''),
        ];
        $migrator = new DbMigrator();
        $test = $migrator->testConnection($cfg);
        if (!$test['ok']) {
            return Response::redirect('/install/db?flash=' . urlencode($test['error']));
        }
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['port'], $cfg['db']);
        $this->config->set([
            'DB_DSN'      => $dsn,
            'DB_USER'     => $cfg['user'],
            'DB_PASSWORD' => $cfg['password'],
        ]);
        // Re-bootstrap Connection so subsequent App::make('db') reads the new DSN.
        Connection::reset();
        $newPdo = Connection::openFromEnv();
        try {
            Migrations::run(new SchemaBootstrap($newPdo));
        } catch (\Throwable $e) {
            // Undo on migration failure so the operator can drop the target DB and retry.
            $this->config->unset(['DB_DSN', 'DB_USER', 'DB_PASSWORD']);
            Connection::reset();
            return Response::redirect('/install/db?flash=' . urlencode('Migration failed: ' . $e->getMessage()));
        }
        return Response::redirect('/install/admin');
    }

    public function admin(Request $req): Response
    {
        $this->guardGet();
        return Response::view('install/admin', [
            'csrfToken' => $this->csrf->token(),
            'flash'     => $req->get['flash'] ?? null,
            'form'      => [],
        ]);
    }

    public function adminSubmit(Request $req): Response
    {
        $this->guardPost($req);
        $name     = trim($req->post['name']     ?? '');
        $email    = trim($req->post['email']    ?? '');
        $password = (string)($req->post['password'] ?? '');
        if ($name === '' || $email === '' || strlen($password) < 8) {
            return Response::view('install/admin', [
                'csrfToken' => $this->csrf->token(),
                'flash'     => t('install.error.email_taken'),
                'form'      => ['name' => $name, 'email' => $email],
            ]);
        }
        $hash = password_hash($password, PASSWORD_BCRYPT);
        try {
            $this->users->createAdmin($name, $email, $hash);
        } catch (\Throwable $e) {
            return Response::view('install/admin', [
                'csrfToken' => $this->csrf->token(),
                'flash'     => $e->getMessage(),
                'form'      => ['name' => $name, 'email' => $email],
            ]);
        }
        return Response::redirect('/install/security');
    }

    public function security(Request $req): Response
    {
        $this->guardGet();
        $redirect = $this->resumeTo('security', $req);
        if ($redirect) return Response::redirect($redirect);
        $proto   = ($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http';
        $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $appUrl  = $proto . '://' . $host;
        return Response::view('install/security', [
            'csrfToken'       => $this->csrf->token(),
            'detectedAppUrl'  => $appUrl,
        ]);
    }

    public function securitySubmit(Request $req): Response
    {
        $this->guardPost($req);
        $url     = trim($req->post['app_url'] ?? '');
        $writes  = [
            'APP_SECRET' => bin2hex(random_bytes(32)),
            'APP_URL'    => $url,
        ];
        if (!empty($req->post['enable_login_hash'])) {
            $writes['LOGIN_HASH'] = bin2hex(random_bytes(8));
        }
        try {
            $this->config->set($writes);
        } catch (\InvalidArgumentException $e) {
            return Response::redirect('/install/security?flash=' . urlencode($e->getMessage()));
        }
        return Response::redirect('/install/integrations');
    }

    public function integrations(Request $req): Response
    {
        $this->guardGet();
        return Response::view('install/integrations', ['csrfToken' => $this->csrf->token()]);
    }

    public function integrationsSubmit(Request $req): Response
    {
        $this->guardPost($req);
        $action = $req->post['action'] ?? 'skip';
        if ($action === 'save') {
            $token = trim($req->post['tg_token'] ?? '');
            $chat  = trim($req->post['tg_chat_id'] ?? '');
            if ($token !== '' && $chat !== '') {
                try {
                    $this->config->set(['TG_BOT_TOKEN' => $token, 'TG_CHAT_ID' => $chat]);
                } catch (\InvalidArgumentException $e) {
                    return Response::redirect('/install/integrations?flash=' . urlencode($e->getMessage()));
                }
            }
        }
        // Mark install complete.
        $this->settings->set('installed_at', gmdate('Y-m-d\TH:i:s\Z'));
        return Response::redirect('/install/done');
    }

    public function done(Request $req): Response
    {
        // After installed_at, the gate flips elsewhere returns 404. To render
        // this final summary once, check the gate INVERSE: must NOT be required.
        if (InstallGate::isInstallRequired($this->pdo)) {
            return Response::redirect('/install');
        }
        $loginHash = $this->config->get('LOGIN_HASH');
        $admin     = $this->users->firstApprovedAdmin();
        $signInUrl = '/login' . ($loginHash ? ('?hash=' . urlencode($loginHash)) : '');
        return Response::view('install/done', [
            'summary' => [
                'db'           => $this->config->get('DB_DSN') ? 'MySQL' : 'SQLite',
                'admin_email'  => $admin['email'] ?? '',
                'login_hash'   => $loginHash !== null,
                'telegram'     => $this->config->get('TG_BOT_TOKEN') !== null,
                'sign_in_url'  => $signInUrl,
            ],
        ]);
    }
}
```

This references two repository methods that may not exist yet:
- `UserRepository::createAdmin($name, $email, $hash)`. Check
  `system/Repository/UserRepository.php` — if there's a `create()`
  method, prefer adding a thin `createAdmin()` that calls it with role
  and status pre-set. Otherwise, add a 6-line method that does a single
  prepared INSERT.
- `UserRepository::firstApprovedAdmin()`. Likely doesn't exist. Add as
  a 4-line SELECT with `LIMIT 1`.

Both go in the same commit as InstallController.

- [ ] **Step 7: Wire routes in `system/Bootstrap/Routes.php`.**

Find the auth-routes block. Add an `/install/*` block above it:

```php
// Install wizard (TODO #10). Gated by InstallGate in public/index.php
// when INSTALL_GATE_ENABLED=true and no admin yet.
$router->get ('/install',              'Install@welcome');
$router->post('/install/start',        'Install@start');
$router->get ('/install/db',           'Install@db');
$router->post('/install/db',           'Install@dbSubmit');
$router->get ('/install/admin',        'Install@admin');
$router->post('/install/admin',        'Install@adminSubmit');
$router->get ('/install/security',     'Install@security');
$router->post('/install/security',     'Install@securitySubmit');
$router->get ('/install/integrations', 'Install@integrations');
$router->post('/install/integrations', 'Install@integrationsSubmit');
$router->get ('/install/done',         'Install@done');
```

If routes are registered as factory closures elsewhere, follow that
convention exactly. The handler name `Install` resolves via the
controller-factory naming convention used in
`system/Bootstrap/Routes.php`.

- [ ] **Step 8: Register `InstallController` in `Container.php` if the
  project uses explicit DI registration for controllers.** Otherwise the
  controller-factory pattern (autowired) picks it up.

- [ ] **Step 9: Run unit tests + the e2e spec.**

```bash
php tests/run.php tests/unit 2>&1 | tail -3
# Expect all green.

make test-clean && npx playwright test --project=chromium tests/e2e/install.spec.ts 2>&1 | tail -10
# Expect: redirects-to-install + walks-6-steps + 404-after-done passing.
# The skipped resume test is fleshed out in step 10.
```

- [ ] **Step 10: Unskip the resume test and the route-404 case in
  `tests/e2e/install.spec.ts` — fill them in now that the happy path is
  green.** Use direct DB writes in `beforeAll` to seed a partial-install
  state for the resume test:

```ts
test('resume after dropping off at step 3', async ({ page }) => {
  // Manually pre-seed an admin (simulating crash after /install/admin)
  // by hitting /install/db then /install/admin manually, then closing.
  // Re-opening /install should land us at /install/security.
  // (Implementation: walk first 2 steps explicitly, then GET /install
  //  and expect redirect to /install/security.)
  await page.goto('/install/db');
  await page.click('button[data-driver=sqlite]');
  await page.fill('input[name=name]',     'Bob');
  await page.fill('input[name=email]',    'bob@x.com');
  await page.fill('input[name=password]', 'password123');
  await page.click('button[type=submit]');
  // ...then drop off (close tab) and re-open:
  const r = await page.goto('/install/integrations');
  // Resume kicks us back to security since APP_SECRET isn't set yet.
  expect(r?.url()).toMatch(/\/install\/security$/);
});
```

Run again, all e2e green.

- [ ] **Step 11: Commit.**

```bash
git add system/Controller/InstallController.php system/Database/Connection.php \
        system/Bootstrap/Routes.php system/Repository/UserRepository.php \
        views/install/ views/layouts/install.php \
        system/i18n/en.php system/i18n/pl.php system/i18n/uk.php \
        tests/e2e/install.spec.ts public/assets/css/utilities.css
git commit -m "feat(install): InstallController + 6 wizard steps + e2e coverage"
```

---

## Task 5 — Platform Settings tab in Compass

**Files:**
- Modify: `system/Controller/CompassController.php` (add `platform()` + `updatePlatform()`)
- Modify: `system/Bootstrap/Routes.php` (route `/admin/compass/platform`)
- Modify: `views/partials/compass-tabs.php` (new "Platform" tab)
- Create: `views/admin/compass/platform.php`
- Modify: `system/i18n/{en,pl,uk}.php` (`platform.*` keys)
- Create: `tests/e2e/platform-settings.spec.ts`

- [ ] **Step 1: Write the e2e spec (skeleton, fails initially).**

```ts
// tests/e2e/platform-settings.spec.ts
import { test, expect } from '@playwright/test';

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ request }) => {
  // Test fixture: assume install.spec.ts already ran and an admin exists.
  // If running this spec in isolation, seed an admin via the API or
  // by running the existing register flow.
});

async function loginAdmin(page) {
  await page.goto('/login');
  await page.fill('input[name=email]', 'alice@example.com');
  await page.fill('input[name=password]', 'password123');
  await page.click('button[type=submit]');
  await expect(page).toHaveURL('/');
}

test('admin sees Platform tab in Compass', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/compass');
  await expect(page.locator('a[href="/admin/compass/platform"]')).toBeVisible();
});

test('Platform tab toggles LOGIN_HASH and regenerates it', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/compass/platform');
  // Toggle on:
  await page.check('input[name=enable_login_hash]');
  await page.click('button[data-action=save-auth]');
  // Modal/inline display shows the hash once.
  await expect(page.locator('[data-login-hash-value]')).toBeVisible();
});

test('Platform tab rotates APP_SECRET', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/compass/platform');
  page.on('dialog', d => d.accept()); // confirm dialog
  await page.click('button[data-action=rotate-app-secret]');
  await expect(page.locator('.toast--success')).toBeVisible();
});

test('Platform tab updates Telegram credentials', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/compass/platform');
  await page.fill('input[name=tg_bot_token]', '123:abcdef');
  await page.fill('input[name=tg_chat_id]',   '-100123');
  await page.click('button[data-action=save-telegram]');
  await expect(page.locator('input[name=tg_bot_token]')).toHaveValue(/\*\*\*\*/);
});
```

Run: `make test-clean && npx playwright test --project=chromium tests/e2e/platform-settings.spec.ts 2>&1 | tail -10`
Expected: all tests fail (tab/route don't exist yet).

- [ ] **Step 2: Add the tab to `views/partials/compass-tabs.php`.**

Add to the `$tabs` array (between `db-migrate` and the closing bracket):

```php
'platform'   => ['label' => t('compass.tab.platform'),   'href' => '/admin/compass/platform',   'icon' => 'fa-sliders'],
```

Update the `$currentTab` PHPDoc comment to include `'platform'`.

- [ ] **Step 3: Add `platform()` + `updatePlatform()` to
  `CompassController`.**

Add methods:

```php
public function platform(Request $req): Response
{
    RolePolicy::requireAdmin($this->user());
    $config = new ConfigStore();
    $values = $config->load();
    // Mask secrets — never echo the raw value back to the UI.
    $tgToken = $values['TG_BOT_TOKEN'] ?? '';
    return Response::view('admin/compass/platform', [
        'currentTab' => 'platform',
        'csrfToken'  => $this->csrf->token(),
        'config'     => [
            'APP_URL'                => $values['APP_URL'] ?? '',
            'TRUSTED_PROXIES'        => $values['TRUSTED_PROXIES'] ?? '',
            'LOGIN_HASH_SET'         => isset($values['LOGIN_HASH']),
            'APP_SECRET_SET'         => isset($values['APP_SECRET']),
            'TG_BOT_TOKEN_MASK'      => $tgToken ? ('bot****' . substr($tgToken, -4)) : '',
            'TG_CHAT_ID'             => $values['TG_CHAT_ID'] ?? '',
            'UPDATE_ENABLED'         => ($values['UPDATE_ENABLED'] ?? 'true') === 'true',
            'UPDATE_CHECK_INTERVAL'  => (int)($values['UPDATE_CHECK_INTERVAL'] ?? 3600),
            'UPDATE_BACKUP_KEEP'     => (int)($values['UPDATE_BACKUP_KEEP'] ?? 5),
        ],
        'driver' => Connection::driverFor($this->pdo)?->name() ?? 'sqlite',
    ]);
}

public function updatePlatform(Request $req): Response
{
    RolePolicy::requireAdmin($this->user());
    $this->csrf->require($req->post['_csrf'] ?? '');
    $config = new ConfigStore();
    $section = $req->post['section'] ?? '';
    try {
        switch ($section) {
            case 'auth':
                $writes = [];
                if (!empty($req->post['enable_login_hash'])) {
                    $writes['LOGIN_HASH'] = bin2hex(random_bytes(8));
                } else {
                    $config->unset(['LOGIN_HASH']);
                }
                if ($writes) $config->set($writes);
                break;
            case 'rotate-app-secret':
                $config->set(['APP_SECRET' => bin2hex(random_bytes(32))]);
                break;
            case 'urls':
                $config->set([
                    'APP_URL'         => trim($req->post['app_url'] ?? ''),
                    'TRUSTED_PROXIES' => trim($req->post['trusted_proxies'] ?? ''),
                ]);
                break;
            case 'telegram':
                $writes = [];
                $token = trim($req->post['tg_bot_token'] ?? '');
                $chat  = trim($req->post['tg_chat_id'] ?? '');
                if ($token !== '') $writes['TG_BOT_TOKEN'] = $token;
                if ($chat !== '')  $writes['TG_CHAT_ID']   = $chat;
                if ($writes) $config->set($writes);
                break;
            case 'updates':
                $config->set([
                    'UPDATE_ENABLED'        => !empty($req->post['update_enabled']),
                    'UPDATE_CHECK_INTERVAL' => (int)($req->post['update_check_interval'] ?? 3600),
                    'UPDATE_BACKUP_KEEP'    => (int)($req->post['update_backup_keep'] ?? 5),
                ]);
                break;
            default:
                http_response_code(400);
                return Response::text('unknown section');
        }
    } catch (\InvalidArgumentException $e) {
        return Response::redirect('/admin/compass/platform?flash=' . urlencode($e->getMessage()));
    }
    return Response::redirect('/admin/compass/platform?saved=' . $section);
}
```

- [ ] **Step 4: Create `views/admin/compass/platform.php`.**

```php
<?php require __DIR__ . '/../../partials/compass-tabs.php'; ?>

<?php if (!empty($_GET['saved'])): ?>
  <div class="alert alert--success"><?= e(t('platform.flash.saved', ['section' => e($_GET['saved'])])) ?></div>
<?php endif; ?>
<?php if (!empty($_GET['flash'])): ?>
  <div class="alert alert--danger"><?= e($_GET['flash']) ?></div>
<?php endif; ?>

<section class="panel">
  <h2><?= e(t('platform.section.database')) ?></h2>
  <p>Current driver: <strong><?= e($driver) ?></strong></p>
  <a class="btn btn--secondary" href="/admin/compass/db-migrate"><?= e(t('platform.action.switch_to_mysql')) ?></a>
</section>

<section class="panel">
  <h2><?= e(t('platform.section.auth')) ?></h2>
  <form method="post" action="/admin/compass/platform">
    <?= csrf_field($csrfToken) ?>
    <input type="hidden" name="section" value="auth">
    <label class="checkbox-row">
      <input type="checkbox" name="enable_login_hash" value="1" <?= $config['LOGIN_HASH_SET'] ? 'checked' : '' ?>>
      <?= e(t('install.security.login_hash_label')) ?>
    </label>
    <button type="submit" class="btn btn--primary" data-action="save-auth"><?= e(t('platform.action.save')) ?></button>
  </form>
  <form method="post" action="/admin/compass/platform" class="mt-12">
    <?= csrf_field($csrfToken) ?>
    <input type="hidden" name="section" value="rotate-app-secret">
    <p class="muted"><?= e(t('platform.warn.app_secret_rotation')) ?></p>
    <button type="submit" class="btn btn--danger"
            data-action="rotate-app-secret"
            onclick="return confirm('<?= e(t('platform.confirm.rotate_secret')) ?>')">
      <?= e(t('platform.action.rotate_app_secret')) ?>
    </button>
  </form>
</section>

<section class="panel">
  <h2><?= e(t('platform.section.urls')) ?></h2>
  <form method="post" action="/admin/compass/platform">
    <?= csrf_field($csrfToken) ?>
    <input type="hidden" name="section" value="urls">
    <label>APP_URL <input class="input" name="app_url" type="url" value="<?= e($config['APP_URL']) ?>"></label>
    <label>TRUSTED_PROXIES <input class="input" name="trusted_proxies" value="<?= e($config['TRUSTED_PROXIES']) ?>"></label>
    <button type="submit" class="btn btn--primary"><?= e(t('platform.action.save')) ?></button>
  </form>
</section>

<section class="panel">
  <h2><?= e(t('platform.section.telegram')) ?></h2>
  <form method="post" action="/admin/compass/platform">
    <?= csrf_field($csrfToken) ?>
    <input type="hidden" name="section" value="telegram">
    <label>Bot token <input class="input" type="password" name="tg_bot_token" value="<?= e($config['TG_BOT_TOKEN_MASK']) ?>" autocomplete="off"></label>
    <label>Chat ID <input class="input" name="tg_chat_id" value="<?= e($config['TG_CHAT_ID']) ?>"></label>
    <button type="submit" class="btn btn--primary" data-action="save-telegram"><?= e(t('platform.action.save')) ?></button>
  </form>
</section>

<section class="panel">
  <h2><?= e(t('platform.section.updates')) ?></h2>
  <form method="post" action="/admin/compass/platform">
    <?= csrf_field($csrfToken) ?>
    <input type="hidden" name="section" value="updates">
    <label class="checkbox-row">
      <input type="checkbox" name="update_enabled" value="1" <?= $config['UPDATE_ENABLED'] ? 'checked' : '' ?>>
      <?= e(t('platform.updates.enabled')) ?>
    </label>
    <label>Check interval (s) <input class="input" type="number" name="update_check_interval" value="<?= (int)$config['UPDATE_CHECK_INTERVAL'] ?>"></label>
    <label>Backups to keep <input class="input" type="number" name="update_backup_keep" value="<?= (int)$config['UPDATE_BACKUP_KEEP'] ?>"></label>
    <button type="submit" class="btn btn--primary"><?= e(t('platform.action.save')) ?></button>
  </form>
</section>
```

- [ ] **Step 5: Add i18n keys (`platform.*`) in en/pl/uk catalogs.** Use
  the same shape as the existing `compass.*` keys. PL/UK get English
  copy initially so the parity convention test passes.

- [ ] **Step 6: Wire route in `system/Bootstrap/Routes.php`.**

Below the existing compass routes:

```php
$router->get ('/admin/compass/platform', 'Compass@platform');
$router->post('/admin/compass/platform', 'Compass@updatePlatform');
```

- [ ] **Step 7: Run unit + e2e.**

```bash
php tests/run.php tests/unit 2>&1 | tail -3
make test-clean && npx playwright test --project=chromium tests/e2e/platform-settings.spec.ts 2>&1 | tail -10
```

All green.

- [ ] **Step 8: Commit.**

```bash
git add system/Controller/CompassController.php system/Bootstrap/Routes.php \
        views/partials/compass-tabs.php views/admin/compass/platform.php \
        system/i18n/ tests/e2e/platform-settings.spec.ts
git commit -m "feat(compass): Platform Settings tab — edit config.json post-install"
```

---

## Task 6 — Migrate existing installs (one-line migration)

**Files:**
- Create: `system/Database/migrations/20260604_010_seed_installed_at.php`

- [ ] **Step 1: Add the migration.**

```php
<?php
declare(strict_types=1);

// One-off: for installs that already have an admin (i.e. operators who
// upgraded from pre-1.5.0), seed settings.installed_at so the
// InstallGate stays closed when the feature flag flips in Task 7.
// Brand-new installs will not have an admin yet — for them the gate
// fires and the wizard sets installed_at itself.
return function (\PDO $pdo): void {
    $countAdmins = (int)$pdo->query(
        "SELECT COUNT(*) FROM users WHERE role='admin' AND status='approved'"
    )->fetchColumn();
    if ($countAdmins === 0) return;
    $existing = $pdo->prepare("SELECT value FROM settings WHERE key='installed_at'");
    $existing->execute();
    if ((string)$existing->fetchColumn() !== '') return;
    $ins = $pdo->prepare("INSERT INTO settings (key, value) VALUES ('installed_at', :v)");
    $ins->execute([':v' => gmdate('Y-m-d\TH:i:s\Z')]);
};
```

- [ ] **Step 2: Verify it runs cleanly against a seeded test DB.**

```bash
php tests/run.php tests/unit 2>&1 | tail -3
# All green — the schema_migrations table picks up the new file on next boot.
```

- [ ] **Step 3: Commit.**

```bash
git add system/Database/migrations/20260604_010_seed_installed_at.php
git commit -m "feat(install): seed installed_at for existing v1.4 installs (gate-safe upgrade)"
```

---

## Task 7 — Flip the feature flag default + release

**Files:**
- Modify: `.env.example` (flip `INSTALL_GATE_ENABLED` default to `true`)
- Modify: `system/version.php` (bump to 1.5.0)
- Modify: `TODO.md` (mark #10 done)

- [ ] **Step 1: Flip the default.**

In `.env.example`, change the line:

```
INSTALL_GATE_ENABLED=false
```

to:

```
INSTALL_GATE_ENABLED=true
```

And update the surrounding comment to reflect that this is now the default (mention the opt-out for advanced operators).

- [ ] **Step 2: Bump version.**

In `system/version.php`:

```php
const APP_VERSION = '1.5.0';
```

- [ ] **Step 3: Update TODO.md.**

Move `#10 — Setup wizard for new installs` from `## Open` to `## Done`
with the release tag `v1.5.0`. Brief description (3-5 lines) summarising
what shipped: ConfigStore overlay, 6-step wizard, Platform Settings
Compass tab, feature-flag rollout, gate-safe migration for existing
installs.

After moving #10, `## Open` becomes empty — delete the empty section
header.

- [ ] **Step 4: Run the full local suite.**

```bash
php tests/run.php tests/unit 2>&1 | tail -3                  # all green
php tests/api/run.php 2>&1 | tail -3                         # all green
make test-clean && npx playwright test --project=chromium 2>&1 | tail -10
make test-clean && npx playwright test --project=firefox  2>&1 | tail -10
```

Expected: all green across chromium + firefox.

- [ ] **Step 5: Commit + tag + push.**

```bash
git add .env.example system/version.php TODO.md
git commit -m "release: bump v1.5.0 + close TODO #10 (install wizard shipped)"

# Merge feature branch to main:
git checkout main
git merge --no-ff feat/install-wizard -m "Merge feat/install-wizard — v1.5.0 (TODO #10 closed)"

# Annotated tag:
git tag -a v1.5.0 -m "v1.5.0 — install wizard + Platform Settings (TODO #10)

ConfigStore — data/config.json overlay over .env. Wizard writes
through this; .env stays for dev/CI fallback. Six-step browser
wizard (welcome → db → admin → security → integrations → done)
gated by InstallGate (no admin + no installed_at). Platform Settings
tab in Compass mirrors the wizard surface for ongoing edits —
LOGIN_HASH toggle, APP_SECRET rotate, APP_URL, TRUSTED_PROXIES,
Telegram, updates settings.

Closes TODO #10."

# Push:
git push origin main && git push origin v1.5.0
```

- [ ] **Step 6: Verify CI matrix is green on the new commit.**

```bash
gh run watch
```

---

## Self-review (spec coverage check)

Mapping spec sections → tasks:

| Spec section | Task |
|---|---|
| ConfigStore service | Task 1 |
| Boot integration (Container.php) | Task 1 |
| Validation rules (DB_DSN, APP_URL, TG_CHAT_ID, TRUSTED_PROXIES, ints, bool) | Task 1 |
| Atomic write + mode 0600 | Task 1 |
| InstallGate predicate | Task 3 |
| `UserRepository::countApprovedAdmins` | Task 3 |
| Request gate in public/index.php | Task 3 (+ Task 7 flip) |
| InstallController 6 steps | Task 4 |
| Wizard views (6 + layout) | Task 4 |
| Connection::reset() | Task 4 |
| Platform Settings tab | Task 5 |
| .gitignore + DEPLOYMENT.md | Task 2 |
| Migration for existing installs | Task 6 |
| Feature flag default flip | Task 7 |
| Tag v1.5.0 + close TODO #10 | Task 7 |

All covered. No placeholders. Names consistent across tasks (`countApprovedAdmins`, `Connection::reset`, `ConfigStore::set/load/get/unset`, `InstallGate::isInstallRequired`, the 6 view filenames, the route paths).

---

## When done

- v1.5.0 tagged, pushed.
- TODO.md has `#10` in Done.
- Fresh tarball install on a new server: download, point webserver at
  `public/`, open URL → wizard → done. Zero file edits.
- Existing v1.4.0 installs: unaffected (gate-safe migration seeds
  `installed_at`).
- Both wizard and Platform Settings tab edit the same
  `data/config.json` via ConfigStore.
