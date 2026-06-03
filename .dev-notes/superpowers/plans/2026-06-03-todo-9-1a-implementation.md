# TODO #9.1a Implementation Plan — Ship-blocker fixes before 1.x

> **For agentic workers:** Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Address the 17 must-fix-before-ship items from the 2026-06-03 audit so the product can be honestly tagged as production-ready (1.2.0). Security holes closed, accessibility minimums met, CI runs full suite, packaging produces clean tarballs, and the critical docs (SECURITY/DEPLOYMENT/ARCHITECTURE scaffolds) exist.

**Architecture:** Mostly drop-in fixes — no new frameworks, no new dependencies. The biggest structural change is moving the auth throttle from in-session storage to a DB-backed `RateLimiter` (reusing the same service that powers API rate limiting). One new env (`TRUSTED_PROXIES`) and one new helper (`Request::clientIp()`).

**Tech Stack:** PHP 8.2+ / SQLite + MySQL / vanilla JS ES modules / Playwright. Composer-free.

**Spec:** [docs/superpowers/specs/2026-06-03-todo-9-audit-and-cleanup-plan.md](../specs/2026-06-03-todo-9-audit-and-cleanup-plan.md)

**Branch:** `fix/9-1a-ship-blockers` (single feature branch; merge to main + tag v1.2.0 at the end).

---

## Conventions used throughout

- **TDD where it pays off.** Repository/service changes get unit tests first. Pure UI/view edits don't need a new test if existing e2e cover the path.
- **Tests:** `make unit` (~1s, expect 189 → 220+), `make api` (~3s, expect 84 → 86+), `make e2e` (~3.5m, expect 114 → 122+).
- **Commits:** one logical change per commit. The 17 items collapse to ~14 commits.
- **i18n discipline:** any new user-visible string lands in `en.php`, `pl.php`, `uk.php` simultaneously. The convention test added in T-K2 enforces this.
- **No new dependencies.** This wave is all in-repo refactors and additions.

## File touch map (preview)

```
NEW
  docs/SECURITY.md
  docs/DEPLOYMENT.md
  docs/ARCHITECTURE.md
  system/Auth/LoginThrottle.php
  system/Database/migrations/20260604_000_login_attempts.php
  system/Http/ClientIp.php                       (or extend Request)
  tests/unit/test_html_sanitizer.php
  tests/unit/test_role_policy.php (extended)
  tests/unit/test_login_throttle.php
  tests/unit/test_client_ip.php

MODIFIED
  system/Auth/AuthManager.php                    (S-1)
  system/Controller/AuthController.php           (S-2)
  system/Controller/PublicFormController.php     (S-4)
  system/Controller/PublicLinkController.php     (S-4)
  system/Controller/PublicPollController.php     (S-4)
  system/Repository/ShortLinkVisitRepository.php (S-4 — IP arg now passed in)
  system/Service/RolePolicy.php                  (T-3 + extra coverage)
  system/i18n/en.php / pl.php / uk.php           (V-6, J-7/I-3 channel)
  system/View/helpers.php                        (V-2 toast root)
  views/partials/toast-root.php                  (V-2)
  views/partials/activity-row.php                (V-6)
  views/auth/*.php                               (V-1 label for)
  views/users/*.php, views/admin/*.php           (V-1 label for, sample)
  views/forms/builder.php, polls/builder.php     (V-1)
  views/projects/form.php                        (V-1)
  views/layouts/main.php                         (J-7 i18n channel emit)
  public/assets/js/ui.js                         (J-3 logSilent, J-7 t() helper, V-2 toast role)
  public/assets/js/kanban.js                     (J-3 + J-7 migrations)
  public/assets/js/task-page.js                  (J-3 + J-7 + J-8)
  public/assets/js/comments.js, form-builder.js, poll-builder.js  (J-7)
  public/assets/js/*.js                          (J-3 sweep)
  Makefile                                       (O-1 package exclusions, package-check)
  .github/workflows/unit-tests.yml               (O-2 → add api + e2e jobs)
  tests/unit/test_i18n.php                       (K-2 convention)
  README.md                                      (link new docs)

DELETED
  data.backup-pre-migrate-refactor/              (K-1 — local cleanup, never was in git)
```

---

## Task 1 — Delete the legacy backup directory (K-1) [trivial]

**Files:**
- Delete: `data.backup-pre-migrate-refactor/` (544 KB, gitignored but on dev disk)

- [ ] **Step 1: Confirm not in git**

```bash
git ls-files data.backup-pre-migrate-refactor/ | wc -l
```

Expected: `0`. (If non-zero, escalate — different problem.)

- [ ] **Step 2: Remove from disk**

```bash
rm -rf data.backup-pre-migrate-refactor/
ls -ld data.backup-pre-migrate-refactor/ 2>&1 | head -1
```

Expected: "No such file or directory".

- [ ] **Step 3: Verify .gitignore covers any re-creation**

```bash
grep "data.backup" .gitignore
```

Expected: `/data.backup-*/` line is present (it already is).

- [ ] **Step 4: No commit needed for this step alone.** The cleanup is local-disk only; the Makefile exclusion in Task 9 covers tarball protection.

---

## Task 2 — Migration: `login_attempts` table (S-1 setup) [TDD]

**Files:**
- Create: `system/Database/migrations/20260604_000_login_attempts.php`
- Create: `tests/unit/test_login_attempts_schema.php`

- [ ] **Step 1: Write the failing test**

Create `tests/unit/test_login_attempts_schema.php`:

```php
<?php
function _laSchemaPdo(): PDO {
    $pdo = \App\Database\Connection::open('sqlite::memory:');
    apply_migration($pdo, dirname(__DIR__, 2) . '/system/Database/migrations/20260604_000_login_attempts.php');
    return $pdo;
}

it('login_attempts table has expected columns', function () {
    $pdo = _laSchemaPdo();
    $cols = array_column($pdo->query("PRAGMA table_info('login_attempts')")->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['key_hash', 'window_start', 'count'] as $c) {
        assert_true(in_array($c, $cols, true), "missing column $c");
    }
});

it('login_attempts.key_hash is the primary key', function () {
    $pdo = _laSchemaPdo();
    $stmt = $pdo->prepare('INSERT INTO login_attempts (key_hash, window_start, count) VALUES (?, ?, ?)');
    $stmt->execute([str_repeat('a', 64), time(), 1]);
    $threw = false;
    try { $stmt->execute([str_repeat('a', 64), time(), 2]); }
    catch (\PDOException $_) { $threw = true; }
    assert_true($threw, 'duplicate key_hash should throw');
});
```

- [ ] **Step 2: Run, expect FAIL**

```bash
make unit 2>&1 | grep -A1 "login_attempts"
```

- [ ] **Step 3: Write the migration**

Create `system/Database/migrations/20260604_000_login_attempts.php`:

```php
<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    // Mirrors api_rate_limits but keyed by sha256(lowercased email) instead of
    // a token id. Reused for any sliding-window throttle that doesn't fit the
    // api_tokens FK (login attempts, future per-IP throttles).
    $schema->createTableIfNotExists('login_attempts', function (Blueprint $t) {
        $t->string('key_hash', 64)->primary();       // sha256 hex
        $t->bigInteger('window_start');               // Unix epoch
        $t->integer('count');
    });
};
```

- [ ] **Step 4: Run, expect PASS**

```bash
make unit 2>&1 | tail -3
```

Expected: 189 → 191 passed.

- [ ] **Step 5: Commit**

```bash
git add system/Database/migrations/20260604_000_login_attempts.php tests/unit/test_login_attempts_schema.php
git commit -m "feat(auth): add login_attempts migration for DB-backed throttle"
```

---

## Task 3 — `LoginThrottle` service (S-1) [TDD]

**Files:**
- Create: `system/Auth/LoginThrottle.php`
- Create: `tests/unit/test_login_throttle.php`
- Modify: `system/Auth/AuthManager.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/unit/test_login_throttle.php`:

```php
<?php
use App\Auth\LoginThrottle;

function _ltPdo(): PDO {
    $pdo = \App\Database\Connection::open('sqlite::memory:');
    apply_migration($pdo, dirname(__DIR__, 2) . '/system/Database/migrations/20260604_000_login_attempts.php');
    return $pdo;
}

it('isThrottled returns false on fresh state', function () {
    $t = new LoginThrottle(_ltPdo(), max: 5, windowSeconds: 900);
    assert_true(!$t->isThrottled('a@b.com'));
});

it('throttles at the configured max', function () {
    $t = new LoginThrottle(_ltPdo(), max: 3, windowSeconds: 900);
    for ($i = 0; $i < 3; $i++) {
        assert_true(!$t->isThrottled('a@b.com'));
        $t->recordFail('a@b.com');
    }
    assert_true($t->isThrottled('a@b.com'));
});

it('resetFails clears the counter', function () {
    $t = new LoginThrottle(_ltPdo(), max: 3, windowSeconds: 900);
    $t->recordFail('a@b.com'); $t->recordFail('a@b.com'); $t->recordFail('a@b.com');
    assert_true($t->isThrottled('a@b.com'));
    $t->resetFails('a@b.com');
    assert_true(!$t->isThrottled('a@b.com'));
});

it('window expires reset the counter', function () {
    $pdo = _ltPdo();
    $t = new LoginThrottle($pdo, max: 3, windowSeconds: 60);
    for ($i = 0; $i < 3; $i++) $t->recordFail('a@b.com');
    assert_true($t->isThrottled('a@b.com'));
    // Force the window stale via direct DB tweak
    $pdo->prepare('UPDATE login_attempts SET window_start = ? WHERE key_hash = ?')
        ->execute([time() - 61, hash('sha256', strtolower('a@b.com'))]);
    assert_true(!$t->isThrottled('a@b.com'));
});

it('isolates different emails (key collisions)', function () {
    $t = new LoginThrottle(_ltPdo(), max: 2, windowSeconds: 900);
    $t->recordFail('a@b.com'); $t->recordFail('a@b.com');
    assert_true($t->isThrottled('a@b.com'));
    assert_true(!$t->isThrottled('c@d.com'));
});

it('email is lowercased + trimmed before hashing', function () {
    $pdo = _ltPdo();
    $t = new LoginThrottle($pdo, max: 100, windowSeconds: 900);
    $t->recordFail('  A@B.com  ');
    $row = $pdo->query('SELECT key_hash FROM login_attempts')->fetch();
    assert_eq(hash('sha256', 'a@b.com'), $row['key_hash']);
});
```

- [ ] **Step 2: Run, expect FAIL** (class not found).

- [ ] **Step 3: Implement the service**

Create `system/Auth/LoginThrottle.php`:

```php
<?php
declare(strict_types=1);
namespace App\Auth;

final class LoginThrottle
{
    public function __construct(
        private \PDO $pdo,
        public readonly int $max = 5,
        public readonly int $windowSeconds = 900,
    ) {}

    public function isThrottled(string $email): bool
    {
        $key = $this->key($email);
        $row = $this->pdo->prepare('SELECT window_start, count FROM login_attempts WHERE key_hash = ?');
        $row->execute([$key]);
        $r = $row->fetch();
        if (!$r) return false;
        if ((time() - (int)$r['window_start']) >= $this->windowSeconds) return false;
        return (int)$r['count'] >= $this->max;
    }

    public function recordFail(string $email): void
    {
        $key = $this->key($email);
        $now = time();
        // Atomic conditional UPSERT — reuses the RateLimiter pattern.
        $driver = method_exists(\App\Database\Connection::class, 'driverFor')
            ? (\App\Database\Connection::driverFor($this->pdo)?->name() ?? 'sqlite')
            : 'sqlite';
        if ($driver === 'mysql') {
            $sql = 'INSERT INTO login_attempts (key_hash, window_start, count) VALUES (?, ?, 1)
                    ON DUPLICATE KEY UPDATE
                      window_start = IF(window_start + ? <= VALUES(window_start), VALUES(window_start), window_start),
                      count        = IF(window_start + ? <= VALUES(window_start), 1, count + 1)';
            $this->pdo->prepare($sql)->execute([$key, $now, $this->windowSeconds, $this->windowSeconds]);
        } else {
            $sql = 'INSERT INTO login_attempts (key_hash, window_start, count) VALUES (?, ?, 1)
                    ON CONFLICT(key_hash) DO UPDATE SET
                      window_start = CASE WHEN window_start + ? <= excluded.window_start THEN excluded.window_start ELSE window_start END,
                      count        = CASE WHEN window_start + ? <= excluded.window_start THEN 1 ELSE count + 1 END';
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(1, $key);
            $stmt->bindValue(2, $now, \PDO::PARAM_INT);
            $stmt->bindValue(3, $this->windowSeconds, \PDO::PARAM_INT);
            $stmt->bindValue(4, $this->windowSeconds, \PDO::PARAM_INT);
            $stmt->execute();
        }
    }

    public function resetFails(string $email): void
    {
        $this->pdo->prepare('DELETE FROM login_attempts WHERE key_hash = ?')->execute([$this->key($email)]);
    }

    private function key(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }
}
```

- [ ] **Step 4: Run, expect PASS**

```bash
make unit 2>&1 | tail -3
```

Expected: 191 → 197 passed.

- [ ] **Step 5: Wire into `AuthManager`**

Modify `system/Auth/AuthManager.php`:

```php
<?php
declare(strict_types=1);
namespace App\Auth;

use App\Repository\UserRepository;

final class AuthManager {
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $hasher,
        private array &$session,
        private LoginThrottle $throttle,
    ) {}

    public function login(string $email, string $plain): array|string|null {
        if ($this->throttle->isThrottled($email)) return 'throttled';
        $user = $this->users->findByEmail($email);
        if (!$user || !$this->hasher->verify($plain, $user['password_hash'])) {
            $this->throttle->recordFail($email);
            return null;
        }
        if ($user['status'] === 'pending') return 'pending';
        if ($user['status'] === 'blocked') return 'blocked';
        $this->users->touchLastLogin((int)$user['id']);
        $this->session['user_id'] = (int)$user['id'];
        $this->throttle->resetFails($email);
        return $user;
    }

    public function logout(): void { unset($this->session['user_id'], $this->session['__remember']); }
}
```

Remove the in-session `failsKey` / `isThrottled` / `recordFail` / `resetFails` methods entirely (they're now in `LoginThrottle`).

- [ ] **Step 6: Update DI wiring in `public/index.php`**

Find the `App::singleton('auth', ...)` registration and update:

```php
App::singleton('login_throttle', fn() => new \App\Auth\LoginThrottle(App::make('db')));
App::singleton('auth',    function () use (&$store) {
    return new \App\Auth\AuthManager(
        App::make('users'),
        App::make('hasher'),
        $store,
        App::make('login_throttle'),
    );
});
```

- [ ] **Step 7: Run full suite**

```bash
make unit 2>&1 | tail -3   # 197 passed (added LoginThrottle tests)
make api 2>&1 | tail -3    # 84 passed (unchanged)
```

Existing in-session auth tests in `test_auth.php` may break — they were keyed on session state. Update them to use the new `LoginThrottle` (inject one backed by an in-memory PDO).

- [ ] **Step 8: Run the qa-walk e2e that exercises throttle**

```bash
npx playwright test tests/e2e/qa-walk.spec.ts -g "throttled" --reporter=line 2>&1 | tail -5
```

Expected: passes (test was already updated to broader regex for the throttle message).

- [ ] **Step 9: Commit**

```bash
git add system/Auth/LoginThrottle.php system/Auth/AuthManager.php public/index.php tests/unit/test_login_throttle.php tests/unit/test_auth.php
git commit -m "fix(security): move login throttle from session to DB (S-1 — was attacker-bypassable)"
```

---

## Task 4 — Session regenerate ID on login + register-auto-login (S-2) [tiny + huge security win]

**Files:**
- Modify: `system/Controller/AuthController.php` (2 spots: `login()` success + `register()` auto-login)
- Modify: `system/Auth/SessionManager.php` (optional helper)
- Create: `tests/unit/test_auth.php` additions

- [ ] **Step 1: Locate the login success block**

```bash
grep -n "regenerate\|user_id.*=.*\$user\|csrf->regenerate" system/Controller/AuthController.php | head -10
```

You should see the login flow setting `$this->session()['user_id']` then calling `$this->csrf->regenerate()`. The register flow at the bottom does the same.

- [ ] **Step 2: Add session_regenerate_id after every successful auth**

In `system/Controller/AuthController.php::login()` immediately after `$this->session()['user_id'] = (int)$user['id'];` and BEFORE `$this->csrf->regenerate();`, add:

```php
// Defeat session fixation: rotate the session id after privilege change.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_regenerate_id(true);
}
```

Do the same in `register()` at the auto-login block (look for `Response::redirect('/')` near the end of the action).

- [ ] **Step 3: Add a unit-test smoke (optional but strong signal)**

You can't really unit-test `session_regenerate_id` without a real session — accept that, but assert via e2e that after login the session cookie value DIFFERS from before login.

Add to `tests/e2e/auth.spec.ts`:

```ts
test('session id rotates after login (session-fixation defence)', async ({ page, context }) => {
  await page.goto('/login');
  const before = (await context.cookies()).find(c => c.name === 'OTACK_TASKS')?.value;
  // First, register and become admin
  await page.goto('/register');
  await page.fill('input[name=name]', 'Admin');
  await page.fill('input[name=email]', 'admin@fix.com');
  await page.fill('input[name=password]', 'password123');
  await page.click('button.submit[type=submit]');
  await expect(page).toHaveURL('/');
  const after = (await context.cookies()).find(c => c.name === 'OTACK_TASKS')?.value;
  expect(after).toBeTruthy();
  expect(after).not.toBe(before);
});
```

- [ ] **Step 4: Run all suites**

```bash
make unit 2>&1 | tail -3
make api 2>&1 | tail -3
npx playwright test tests/e2e/auth.spec.ts --reporter=line 2>&1 | tail -3
```

All green.

- [ ] **Step 5: Commit**

```bash
git add system/Controller/AuthController.php tests/e2e/auth.spec.ts
git commit -m "fix(security): rotate session id on login + auto-login (S-2 — session fixation)"
```

---

## Task 5 — `Request::clientIp()` + `TRUSTED_PROXIES` env (S-4) [TDD]

**Files:**
- Create: `tests/unit/test_client_ip.php`
- Modify: `system/Http/Request.php`
- Modify: `system/Controller/PublicFormController.php`
- Modify: `system/Controller/PublicLinkController.php`
- Modify: `system/Controller/PublicPollController.php`
- Modify: `.env.example`

- [ ] **Step 1: Tests first**

Create `tests/unit/test_client_ip.php`:

```php
<?php
use App\Http\Request;

it('clientIp returns REMOTE_ADDR when TRUSTED_PROXIES is unset', function () {
    $_SERVER['REMOTE_ADDR'] = '1.2.3.4';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
    $_ENV['TRUSTED_PROXIES'] = '';
    putenv('TRUSTED_PROXIES=');
    assert_eq('1.2.3.4', Request::clientIp());
});

it('clientIp ignores XFF if REMOTE_ADDR is NOT in TRUSTED_PROXIES', function () {
    $_SERVER['REMOTE_ADDR'] = '8.8.8.8';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
    $_ENV['TRUSTED_PROXIES'] = '10.0.0.0/8';
    putenv('TRUSTED_PROXIES=10.0.0.0/8');
    assert_eq('8.8.8.8', Request::clientIp());
});

it('clientIp returns first XFF hop when REMOTE_ADDR is a trusted proxy', function () {
    $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1, 10.0.0.5';
    $_ENV['TRUSTED_PROXIES'] = '10.0.0.0/8';
    putenv('TRUSTED_PROXIES=10.0.0.0/8');
    assert_eq('198.51.100.1', Request::clientIp());
});

it('clientIp accepts multiple CIDR ranges', function () {
    $_SERVER['REMOTE_ADDR'] = '172.16.5.10';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
    $_ENV['TRUSTED_PROXIES'] = '10.0.0.0/8, 172.16.0.0/12';
    putenv('TRUSTED_PROXIES=10.0.0.0/8, 172.16.0.0/12');
    assert_eq('198.51.100.1', Request::clientIp());
});

it('clientIp rejects malformed XFF (CR/LF/header injection)', function () {
    $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = "198.51.100.1\r\nX-Injected: evil";
    $_ENV['TRUSTED_PROXIES'] = '10.0.0.0/8';
    putenv('TRUSTED_PROXIES=10.0.0.0/8');
    // Either falls back to REMOTE_ADDR or strips CR/LF — both acceptable.
    $ip = Request::clientIp();
    assert_true($ip === '10.0.0.5' || $ip === '198.51.100.1', "got: $ip");
});

it('clientIp accepts single IP (not CIDR) in TRUSTED_PROXIES', function () {
    $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
    $_ENV['TRUSTED_PROXIES'] = '203.0.113.5';
    putenv('TRUSTED_PROXIES=203.0.113.5');
    assert_eq('198.51.100.1', Request::clientIp());
});
```

- [ ] **Step 2: Implement `Request::clientIp()`**

Add a static method to `system/Http/Request.php`:

```php
/**
 * Best-effort client IP. Honours X-Forwarded-For's first hop ONLY when
 * REMOTE_ADDR is in the TRUSTED_PROXIES allowlist (comma-separated CIDR
 * or single-IP entries). When the env is empty, XFF is ignored.
 *
 * This replaces 3 places that previously trusted XFF unconditionally
 * (PublicForm/Poll/Link rate-limit + audit IP) — those were spoofable.
 */
public static function clientIp(): string
{
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $trustedEnv = $_ENV['TRUSTED_PROXIES'] ?? getenv('TRUSTED_PROXIES') ?: '';
    if ($trustedEnv === '' || $remote === '') return $remote;

    $trusted = array_filter(array_map('trim', explode(',', $trustedEnv)));
    $isTrusted = false;
    foreach ($trusted as $cidr) {
        if (self::ipInRange($remote, $cidr)) { $isTrusted = true; break; }
    }
    if (!$isTrusted) return $remote;

    $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($xff === '') return $remote;
    // Reject anything with CR/LF — defence-in-depth against header injection.
    if (preg_match('/[\r\n]/', $xff)) return $remote;
    $first = trim(strtok($xff, ','));
    if (!filter_var($first, FILTER_VALIDATE_IP)) return $remote;
    return $first;
}

private static function ipInRange(string $ip, string $cidr): bool
{
    if (!str_contains($cidr, '/')) return $ip === $cidr;
    [$subnet, $maskLen] = explode('/', $cidr, 2);
    if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $ipL = ip2long($ip); $subL = ip2long($subnet);
        $mask = -1 << (32 - (int)$maskLen);
        return ($ipL & $mask) === ($subL & $mask);
    }
    // IPv6 — fallback to inet_pton bitmask comparison
    $ipBin = @inet_pton($ip); $subBin = @inet_pton($subnet);
    if (!$ipBin || !$subBin || strlen($ipBin) !== strlen($subBin)) return false;
    $maskLen = (int)$maskLen;
    $bytes = intdiv($maskLen, 8); $bits = $maskLen % 8;
    if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subBin, 0, $bytes)) return false;
    if ($bits === 0) return true;
    $mask = chr(0xff << (8 - $bits) & 0xff);
    return (ord($ipBin[$bytes]) & ord($mask)) === (ord($subBin[$bytes]) & ord($mask));
}
```

- [ ] **Step 3: Verify tests pass**

```bash
make unit 2>&1 | tail -3
```

Expected: 197 → 203 passed.

- [ ] **Step 4: Wire `Request::clientIp()` into the three public controllers**

In each of `PublicFormController`, `PublicLinkController`, `PublicPollController`, find the block computing the IP (typically `$xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''; ...`) and replace with:

```php
$ip = \App\Http\Request::clientIp();
```

Use that `$ip` wherever the previous code used the locally computed value.

- [ ] **Step 5: Document the env**

Append to `.env.example`:

```
# Trusted reverse proxies (comma-separated CIDR or single IPs).
# When set, X-Forwarded-For's first hop is honoured for client-IP detection
# (poll rate-limit, short-link unique counter, form audit IP). When empty,
# XFF is ignored and REMOTE_ADDR is used. MUST match your actual proxy
# topology — wrong CIDR is a spoofing window.
# Example: TRUSTED_PROXIES=10.0.0.0/8, 172.16.0.0/12
TRUSTED_PROXIES=
```

- [ ] **Step 6: Run integration tests**

```bash
make api 2>&1 | tail -3
```

84 passed (unchanged — API doesn't use the public form/poll/link paths).

E2E:
```bash
npx playwright test tests/e2e/short-links.spec.ts tests/e2e/forms-auto-task.spec.ts tests/e2e/polls.spec.ts --reporter=line 2>&1 | tail -3
```

All green.

- [ ] **Step 7: Commit**

```bash
git add system/Http/Request.php system/Controller/PublicFormController.php system/Controller/PublicLinkController.php system/Controller/PublicPollController.php tests/unit/test_client_ip.php .env.example
git commit -m "fix(security): TRUSTED_PROXIES env + Request::clientIp() — drops XFF spoofing in 3 controllers (S-4)"
```

---

## Task 6 — Toast aria-live (V-2) [tiny]

**Files:**
- Modify: `views/partials/toast-root.php`
- Modify: `public/assets/js/ui.js`

- [ ] **Step 1: View change**

The file is currently a single `<div id="toast-root"></div>`. Change to:

```php
<div id="toast-root" role="status" aria-live="polite" aria-atomic="true"></div>
```

- [ ] **Step 2: In `ui.js`, set `role="alert"` on error toasts**

Find `UI.toast` (around line 90-106). When the toast variant is `error`, set the inserted `.toast` node's `role="alert"` instead of inheriting `status` from the parent. Approx:

```js
const node = document.createElement('div');
node.className = 'toast toast--' + (variant || 'info');
if (variant === 'error') node.setAttribute('role', 'alert');
node.textContent = msg;
// existing append + auto-remove
```

- [ ] **Step 3: No new test needed** — the e2e `tests/e2e/ui.spec.ts:3` already asserts toast appears. Manual smoke that the DOM emits the attributes is enough.

- [ ] **Step 4: Commit**

```bash
git add views/partials/toast-root.php public/assets/js/ui.js
git commit -m "fix(a11y): aria-live polite on toast root, role=alert on error toasts (V-2)"
```

---

## Task 7 — Label `for` sweep (V-1) [mechanical]

**Files:** every view containing `<label>` (76 elements per audit).

- [ ] **Step 1: Inventory the views**

```bash
grep -rln "<label" views/ | sort -u
```

Roughly: auth/login.php, auth/register.php, projects/form.php, users/edit.php, admin/settings.php, forms/builder.php, polls/builder.php, links/edit.php, profile/show.php, profile/tokens.php — plus partials.

- [ ] **Step 2: Adopt the pattern**

For each `<label>X</label><input name="y" ...>`, change to:

```php
<label for="f-y"><?= e(t('field.y')) ?></label>
<input id="f-y" name="y" ...>
```

Where `f-` is the project's id prefix. If `t('field.y')` doesn't exist, use the literal label and add the i18n key.

- [ ] **Step 3: Smoke via existing e2e**

`make e2e` should still pass — the existing fillers use `input[name=...]` which is unaffected.

- [ ] **Step 4: Commit in 2-3 logical chunks** (auth + profile + admin):

```bash
git add views/auth/*.php views/profile/*.php
git commit -m "a11y(views): wire <label for> on auth + profile forms (V-1 part 1)"
# ... etc
```

---

## Task 8 — `activity-row.php` i18n + i18n catalogue parity (V-6 + K-2)

**Files:**
- Modify: `views/partials/activity-row.php`
- Modify: `system/i18n/en.php`, `pl.php`, `uk.php` (verify `activity.*` keys present)
- Modify: `tests/unit/test_i18n.php` (convention)

- [ ] **Step 1: Confirm keys exist**

```bash
grep -c "'activity\." system/i18n/en.php system/i18n/pl.php system/i18n/uk.php
```

Per the audit, ~18 `activity.*` keys exist in all three catalogues. If counts differ, fix the gap before proceeding.

- [ ] **Step 2: Rewrite the `match` in `activity-row.php`**

Replace the hardcoded English `match`:

```php
$verb = t('activity.' . $a['event']);
```

If the i18n key is missing for some events, add fallback to a humanised event name (e.g., `t('activity.' . $a['event'], default: ucfirst(str_replace('.', ' ', $a['event']))))`.

Also replace literal `'Visitor'` with `t('activity.actor.visitor')` (add the key in all three catalogues if missing).

- [ ] **Step 3: Add parity convention test**

Extend `tests/unit/test_i18n.php`:

```php
it('every locale has every activity.* key', function () {
    $en = include dirname(__DIR__, 2) . '/system/i18n/en.php';
    $pl = include dirname(__DIR__, 2) . '/system/i18n/pl.php';
    $uk = include dirname(__DIR__, 2) . '/system/i18n/uk.php';
    $exempt = ['forms_data.brand_tag' => true];  // Deliberate brand string

    $missing = [];
    foreach (array_keys($en) as $k) {
        if (isset($exempt[$k])) continue;
        if (!array_key_exists($k, $pl)) $missing[] = "pl: $k";
        if (!array_key_exists($k, $uk)) $missing[] = "uk: $k";
    }
    assert_true(empty($missing), 'i18n gaps: ' . implode(', ', array_slice($missing, 0, 5)));
});
```

- [ ] **Step 4: Run unit tests**

```bash
make unit 2>&1 | tail -3
```

Expected: 203 → 204 passed.

- [ ] **Step 5: Smoke the dashboard activity feed**

```bash
make dev &
sleep 2
# Log in as admin, view the dashboard with activity
curl -sS http://localhost:8000/  # — manual visual check
```

Verify activity rows render in the user's locale.

- [ ] **Step 6: Commit**

```bash
git add views/partials/activity-row.php system/i18n/*.php tests/unit/test_i18n.php
git commit -m "i18n(views): activity-row goes through t() + parity test (V-6, K-2)"
```

---

## Task 9 — `make package` exclusions + `make package-check` (O-1)

**Files:**
- Modify: `Makefile`

- [ ] **Step 1: Locate the existing `package:` target**

```bash
grep -n "^package\|^package-check" Makefile
```

- [ ] **Step 2: Update the exclude list**

Inside the `package:` target (typically a `tar czf` command), ensure these exclusions are present:

```make
package:
	@echo "→ Building deploy tarball at /tmp/otack-tasks-deploy.tar.gz"
	@tar czf /tmp/otack-tasks-deploy.tar.gz \
		--exclude='./.git' \
		--exclude='./.github' \
		--exclude='./.gitignore' \
		--exclude='./node_modules' \
		--exclude='./test-results' \
		--exclude='./.playwright' \
		--exclude='./tests' \
		--exclude='./data.backup-*' \
		--exclude='./data/app.sqlite*' \
		--exclude='./data/app.test.sqlite*' \
		--exclude='./data/app.api-test.sqlite*' \
		--exclude='./data/sessions' \
		--exclude='./data/.schema*' \
		--exclude='./data/errors.log' \
		--exclude='./data/backups' \
		--exclude='./public/uploads' \
		--exclude='./public/uploads-test' \
		--exclude='./docs/superpowers' \
		--exclude='./docs/PLAN-next-session.md' \
		--exclude='./docs/NEXT-SESSION-PROMPT.md' \
		--exclude='./package.json' \
		--exclude='./package-lock.json' \
		--exclude='./playwright.config.ts' \
		--exclude='./.env' \
		.
	@ls -lh /tmp/otack-tasks-deploy.tar.gz
```

- [ ] **Step 3: Add a `package-check` target**

```make
package-check: package
	@echo "→ Tarball contents (top 30 paths):"
	@tar tzf /tmp/otack-tasks-deploy.tar.gz | sort | head -30
	@echo "→ Top 5 largest paths:"
	@tar tzvf /tmp/otack-tasks-deploy.tar.gz | sort -k 3 -nr | head -5
	@echo "→ Checking for forbidden paths..."
	@if tar tzf /tmp/otack-tasks-deploy.tar.gz | grep -E '(^|/)(\.git|test|node_modules|superpowers|PLAN-next|NEXT-SESSION|package(-lock)?\.json|playwright\.config|app\.sqlite)' >/dev/null; then \
		echo "  ✗ FORBIDDEN content found in tarball:"; \
		tar tzf /tmp/otack-tasks-deploy.tar.gz | grep -E '(^|/)(\.git|test|node_modules|superpowers|PLAN-next|NEXT-SESSION|package(-lock)?\.json|playwright\.config|app\.sqlite)'; \
		exit 1; \
	fi
	@echo "  ✓ No forbidden paths."
```

- [ ] **Step 4: Add `package-check` to `.PHONY`**

- [ ] **Step 5: Run the smoke**

```bash
make package-check 2>&1 | tail -20
```

Expected: top 30 paths + top 5 sizes printed, "No forbidden paths."

- [ ] **Step 6: Commit**

```bash
git add Makefile
git commit -m "ops(package): tighten make package exclusions + add package-check sanity (O-1)"
```

---

## Task 10 — CI runs `api` + `e2e` (O-2)

**Files:**
- Modify: `.github/workflows/unit-tests.yml`

- [ ] **Step 1: Read the existing workflow**

```bash
cat .github/workflows/unit-tests.yml
```

You should see a single job running `make unit` against PHP + (probably) MySQL.

- [ ] **Step 2: Add an `api` job**

Append after the existing job:

```yaml
  api:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: pdo, pdo_sqlite, dom, fileinfo, mbstring, curl
      - run: php tests/api/run.php
```

- [ ] **Step 3: Add an `e2e` job**

```yaml
  e2e:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: pdo, pdo_sqlite, dom, fileinfo, mbstring, curl
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'
      - run: npm ci
      - uses: actions/cache@v4
        with:
          path: ~/.cache/ms-playwright
          key: ${{ runner.os }}-playwright-${{ hashFiles('package-lock.json') }}
      - run: npx playwright install --with-deps chromium
      - run: npx playwright test
      - if: failure()
        uses: actions/upload-artifact@v4
        with:
          name: playwright-report
          path: playwright-report/
          retention-days: 7
      - if: failure()
        uses: actions/upload-artifact@v4
        with:
          name: test-results
          path: test-results/
          retention-days: 7
```

- [ ] **Step 4: Commit + push to trigger CI**

```bash
git add .github/workflows/unit-tests.yml
git commit -m "ci: add api + e2e jobs to GitHub Actions (O-2)"
```

After pushing, watch the Actions tab. Both new jobs should go green.

---

## Task 11 — `HtmlSanitizer` tests (T-1)

**Files:**
- Create: `tests/unit/test_html_sanitizer.php`

- [ ] **Step 1: Inspect the sanitizer to learn its actual surface**

```bash
sed -n '1,80p' system/Service/HtmlSanitizer.php
```

Note: allowed tags, attribute allowlist, javascript:/data: handling, target="_blank" auto-handling, fallback path.

- [ ] **Step 2: Write ~12 tests**

Create `tests/unit/test_html_sanitizer.php`:

```php
<?php
use App\Service\HtmlSanitizer;

it('allows safe tags', function () {
    $in  = '<p>Hello <strong>world</strong></p>';
    assert_eq($in, trim(HtmlSanitizer::sanitize($in)));
});

it('strips disallowed tags but keeps text', function () {
    $out = HtmlSanitizer::sanitize('<script>alert(1)</script><p>ok</p>');
    assert_true(strpos($out, '<script') === false, "script tag survived: $out");
    assert_true(strpos($out, 'ok') !== false);
});

it('removes inline event handlers (onerror, onload, onclick)', function () {
    $out = HtmlSanitizer::sanitize('<img src="x" onerror="alert(1)" alt="ok">');
    assert_true(strpos($out, 'onerror') === false, "onerror leaked: $out");
});

it('strips javascript: hrefs', function () {
    $out = HtmlSanitizer::sanitize('<a href="javascript:alert(1)">click</a>');
    assert_true(strpos($out, 'javascript:') === false, "javascript: leaked: $out");
});

it('strips data: hrefs', function () {
    $out = HtmlSanitizer::sanitize('<a href="data:text/html,<script>alert(1)</script>">click</a>');
    assert_true(strpos($out, 'data:') === false, "data: leaked: $out");
});

it('allows mailto: hrefs', function () {
    $out = HtmlSanitizer::sanitize('<a href="mailto:user@example.com">email</a>');
    assert_true(strpos($out, 'mailto:user@example.com') !== false, "mailto stripped: $out");
});

it('allows https: hrefs', function () {
    $out = HtmlSanitizer::sanitize('<a href="https://example.com">site</a>');
    assert_true(strpos($out, 'https://example.com') !== false);
});

it('adds rel=noopener to target=_blank links', function () {
    $out = HtmlSanitizer::sanitize('<a href="https://example.com" target="_blank">click</a>');
    assert_true(strpos($out, 'noopener') !== false, "rel noopener missing: $out");
});

it('preserves nested allowed tags', function () {
    $in  = '<p>Hello <strong>bold <em>italic</em></strong></p>';
    $out = HtmlSanitizer::sanitize($in);
    assert_true(strpos($out, '<em>') !== false);
    assert_true(strpos($out, '<strong>') !== false);
});

it('returns empty for empty input', function () {
    assert_eq('', trim(HtmlSanitizer::sanitize('')));
});

it('preserves unicode content', function () {
    $out = HtmlSanitizer::sanitize('<p>Привіт 你好</p>');
    assert_true(strpos($out, 'Привіт') !== false);
    assert_true(strpos($out, '你好') !== false);
});

it('removes HTML comments', function () {
    $out = HtmlSanitizer::sanitize('<p>x</p><!-- secret --><p>y</p>');
    assert_true(strpos($out, 'secret') === false, "comment leaked: $out");
});
```

- [ ] **Step 3: Run**

```bash
make unit 2>&1 | tail -3
```

Expected: 204 → 216 passed. If anything fails, the test exposes a real defect — investigate.

- [ ] **Step 4: Commit**

```bash
git add tests/unit/test_html_sanitizer.php
git commit -m "test(sanitizer): coverage for tag/attr allowlist, schemes, rel-noopener (T-1)"
```

---

## Task 12 — RolePolicy matrix (T-3)

**Files:**
- Modify: `tests/unit/test_role_policy.php`

- [ ] **Step 1: Inspect RolePolicy**

```bash
grep -n "public static function" system/Service/RolePolicy.php
```

11 methods. Existing test file has 3 (`canDeleteComment` only).

- [ ] **Step 2: Add the matrix**

Append to `tests/unit/test_role_policy.php`:

```php
// ─── Role probes ──────────────────────────────────────────────────────────
it('isAdmin/Manager/Employee predicates', function () {
    assert_true( RolePolicy::isAdmin(['role' => 'admin']));
    assert_true(!RolePolicy::isAdmin(['role' => 'manager']));
    assert_true( RolePolicy::isManager(['role' => 'manager']));
    assert_true(!RolePolicy::isManager(['role' => 'admin']));
    assert_true( RolePolicy::isEmployee(['role' => 'employee']));
});

// ─── canCreateProject ────────────────────────────────────────────────────
it('canCreateProject: admin yes', function () {
    assert_true(RolePolicy::canCreateProject(['role' => 'admin']));
});
it('canCreateProject: manager yes', function () {
    assert_true(RolePolicy::canCreateProject(['role' => 'manager']));
});
it('canCreateProject: employee no', function () {
    assert_true(!RolePolicy::canCreateProject(['role' => 'employee']));
});

// ─── canEditProject + canEditTask (membership-aware) ─────────────────────
// These need a ProjectMemberRepository — set up a tiny fixture
function _rpPdo(): \PDO {
    $pdo = \App\Database\Connection::open('sqlite::memory:');
    apply_migration($pdo, dirname(__DIR__, 2) . '/system/Database/migrations/20260522_000_users.php');
    apply_migration($pdo, dirname(__DIR__, 2) . '/system/Database/migrations/20260522_010_projects.php');
    apply_migration($pdo, dirname(__DIR__, 2) . '/system/Database/migrations/20260522_020_project_members.php');
    $pdo->exec("INSERT INTO users (id, name, email, password_hash, role, status, created_at) VALUES (10, 'A', 'a@x', 'x', 'admin', 'approved', '2026-01-01')");
    $pdo->exec("INSERT INTO users (id, name, email, password_hash, role, status, created_at) VALUES (11, 'M', 'm@x', 'x', 'manager', 'approved', '2026-01-01')");
    $pdo->exec("INSERT INTO users (id, name, email, password_hash, role, status, created_at) VALUES (12, 'E', 'e@x', 'x', 'employee', 'approved', '2026-01-01')");
    $pdo->exec("INSERT INTO projects (id, name, slug, color, status, created_by, created_at, updated_at) VALUES (100, 'P', 'p', '#fff', 'active', 11, '2026-01-01', '2026-01-01')");
    return $pdo;
}

it('canEditProject: admin always', function () {
    $pdo = _rpPdo();
    $members = new \App\Repository\ProjectMemberRepository($pdo);
    $project = ['id' => 100, 'created_by' => 11];
    assert_true(RolePolicy::canEditProject(['id'=>10,'role'=>'admin'], $project, $members));
});

it('canEditProject: owner manager yes', function () {
    $pdo = _rpPdo();
    $members = new \App\Repository\ProjectMemberRepository($pdo);
    $project = ['id' => 100, 'created_by' => 11];
    assert_true(RolePolicy::canEditProject(['id'=>11,'role'=>'manager'], $project, $members));
});

it('canEditProject: employee no', function () {
    $pdo = _rpPdo();
    $members = new \App\Repository\ProjectMemberRepository($pdo);
    $project = ['id' => 100, 'created_by' => 11];
    assert_true(!RolePolicy::canEditProject(['id'=>12,'role'=>'employee'], $project, $members));
});

// ─── canManage* family ────────────────────────────────────────────────────
it('canManageForms: admin yes, manager yes, employee no', function () {
    assert_true( RolePolicy::canManageForms(['role'=>'admin']));
    assert_true( RolePolicy::canManageForms(['role'=>'manager']));
    assert_true(!RolePolicy::canManageForms(['role'=>'employee']));
});
it('canManagePolls: admin yes, manager yes, employee no', function () {
    assert_true( RolePolicy::canManagePolls(['role'=>'admin']));
    assert_true( RolePolicy::canManagePolls(['role'=>'manager']));
    assert_true(!RolePolicy::canManagePolls(['role'=>'employee']));
});
it('canManageLinks: same matrix', function () {
    assert_true( RolePolicy::canManageLinks(['role'=>'admin']));
    assert_true( RolePolicy::canManageLinks(['role'=>'manager']));
    assert_true(!RolePolicy::canManageLinks(['role'=>'employee']));
});
it('canViewFormsData: admin yes, manager yes, employee no', function () {
    assert_true( RolePolicy::canViewFormsData(['role'=>'admin']));
    assert_true( RolePolicy::canViewFormsData(['role'=>'manager']));
    assert_true(!RolePolicy::canViewFormsData(['role'=>'employee']));
});
it('canManageSettings: admin only', function () {
    assert_true( RolePolicy::canManageSettings(['role'=>'admin']));
    assert_true(!RolePolicy::canManageSettings(['role'=>'manager']));
    assert_true(!RolePolicy::canManageSettings(['role'=>'employee']));
});
it('canPromoteTaskToProject: admin yes, manager yes, employee no', function () {
    assert_true( RolePolicy::canPromoteTaskToProject(['role'=>'admin']));
    assert_true( RolePolicy::canPromoteTaskToProject(['role'=>'manager']));
    assert_true(!RolePolicy::canPromoteTaskToProject(['role'=>'employee']));
});
```

Verify the actual method signatures match — if `canManageSettings` is admin-only (per the audit's wording) or admin+manager, adjust.

- [ ] **Step 2: Run**

```bash
make unit 2>&1 | tail -3
```

Expected: 216 → ~230 passed.

If any test fails, the test exposes a real policy mistake — investigate before "fixing the test".

- [ ] **Step 3: Commit**

```bash
git add tests/unit/test_role_policy.php
git commit -m "test(policy): full role × method matrix coverage (T-3)"
```

---

## Task 13 — Silent `catch {}` sweep + `logSilent` helper (J-3) [parallel-safe with Task 7]

**Files:**
- Modify: `public/assets/js/utils.js` (add helper)
- Modify: every JS file with `catch {}` blocks (22 files, 49 occurrences)

- [ ] **Step 1: Add helper to `utils.js`**

```js
/**
 * Use in async catch blocks that don't have a user-facing recovery.
 * Logs to console with a `tag` so future regressions are findable,
 * and lets the rejection complete without bubbling to window.onerror.
 *
 * Example:
 *   try { await api(...) } catch (e) { logSilent(e, 'kanban.lazyLoad'); }
 */
export function logSilent(err, tag) {
  // eslint-disable-next-line no-console
  console.warn('[silent]', tag, err);
}
```

- [ ] **Step 2: Sweep**

```bash
grep -rln "catch *{}" public/assets/js/
```

For each file, replace `catch {}` with `catch (e) { logSilent(e, 'module.where'); }`. Pick a meaningful `where` based on the surrounding code (e.g., `'kanban.lazyLoad'`, `'tags.save'`, `'forms-data.delete'`).

Add `import { logSilent } from './utils.js';` at the top of each file that gets a new call.

- [ ] **Step 3: Verify with grep**

```bash
grep -rln "catch *{}" public/assets/js/ | wc -l
```

Expected: `0`.

- [ ] **Step 4: Run e2e to make sure nothing regressed**

```bash
npx playwright test --reporter=line 2>&1 | tail -5
```

114 passed.

- [ ] **Step 5: Commit**

```bash
git add public/assets/js/
git commit -m "fix(js): replace 49 silent catch{} with logSilent(e, tag) for findability (J-3)"
```

---

## Task 14 — JS i18n channel + task-page field labels (J-7 + J-8 + I-3) [parallel-safe with Tasks 7/13]

**Files:**
- Modify: `views/layouts/main.php` (emit `<script>window.__t = {...}</script>` after layout init)
- Modify: `public/assets/js/utils.js` (add `t(key)` helper)
- Modify: `public/assets/js/kanban.js`, `task-page.js`, `comments.js`, `form-builder.js`, `poll-builder.js`, `api-tokens.js`, `attachments.js`, `tags.js`, `polls-index.js`, `users.js`, `compass.js`, `links-show.js` (use `t()` where toast/confirm strings live)
- Modify: `system/i18n/en.php`, `pl.php`, `uk.php` (add toast/confirm/field-label keys)

- [ ] **Step 1: Add the i18n keys**

Identify the 77 + 23 strings. They cluster around verbs (`Saved`, `Updated`, `Created`, `Deleted`, `Failed`, `Copied`, etc.) and object labels (`Task`, `Comment`, `Project`, `Column`, `Form`, `Poll`, `Link`, `Tag`, `Attachment`, `Token`).

Add a `js.*` namespace in i18n:

```php
// system/i18n/en.php — add a block
'js.toast.saved'           => 'Saved',
'js.toast.updated'         => 'Updated',
'js.toast.deleted'         => 'Deleted',
'js.toast.copied'          => 'Copied to clipboard',
'js.toast.copy_failed'     => 'Copy failed — select and copy manually',
'js.toast.task_moved'      => 'Task moved',
'js.toast.column_changed'  => 'Column changed',
'js.toast.assignee_changed'=> 'Assignee changed',
'js.toast.due_changed'     => 'Due date changed',
'js.toast.priority_changed'=> 'Priority changed',
'js.confirm.delete_column' => 'Delete this column?',
'js.confirm.delete_task'   => 'Delete this task?',
'js.confirm.delete_comment'=> 'Delete this comment?',
'js.confirm.delete_attachment' => 'Delete this attachment?',
'js.confirm.delete_form'   => 'Delete this form?',
'js.confirm.delete_poll'   => 'Delete this poll?',
'js.confirm.delete_link'   => 'Delete this link?',
'js.confirm.delete_tag'    => 'Delete this tag?',
'js.confirm.delete_token'  => 'Revoke this token?',
'js.error.api_default'     => 'Something went wrong. Please try again.',
'js.error.validation'      => 'Please check the highlighted fields.',
// ... (collect from the actual codebase)
```

Mirror in `pl.php` and `uk.php`.

- [ ] **Step 2: Emit the JS i18n blob from the layout**

In `views/layouts/main.php`, near the closing `</body>`, before the script imports:

```php
<?php
$jsLocale = [];
$catalogue = i18n_current_catalogue();   // helper that returns the loaded array
foreach ($catalogue as $k => $v) {
    if (str_starts_with($k, 'js.')) $jsLocale[$k] = $v;
}
?>
<script>window.__t = <?= json_encode($jsLocale, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
```

If `i18n_current_catalogue()` doesn't exist, write it in `system/View/helpers.php`. It should return the array currently loaded for the user's locale.

- [ ] **Step 3: Add `t()` to `utils.js`**

```js
export function t(key, fallback) {
  return (window.__t && window.__t[key]) ?? (fallback ?? key);
}
```

- [ ] **Step 4: Migrate the 100 strings**

For each module, replace:
- `UI.toast('Saved', 'success')` → `UI.toast(t('js.toast.saved'), 'success')`
- `UI.confirm('Delete this column?', { danger: true })` → `UI.confirm(t('js.confirm.delete_column'), { danger: true })`

Special-case `task-page.js:123` (J-8): replace the snake-case label builder with an explicit lookup:

```js
const labelKeys = {
  column_id: 'js.toast.column_changed',
  assignee_id: 'js.toast.assignee_changed',
  due_date: 'js.toast.due_changed',
  priority: 'js.toast.priority_changed',
};
UI.toast(t(labelKeys[key] ?? 'js.toast.updated'), 'success');
```

- [ ] **Step 5: Smoke**

```bash
make unit 2>&1 | tail -3      # i18n parity test should pass
make e2e 2>&1 | tail -5       # all 114 still green
```

Manual: open `/profile/tokens` with locale=pl, create a token, verify the toast text is Polish.

- [ ] **Step 6: Commit**

```bash
git add system/i18n/*.php views/layouts/main.php public/assets/js/ system/View/helpers.php tests/unit/test_i18n.php
git commit -m "i18n(js): standardise toasts/confirms via window.__t + t() helper (J-7, J-8, I-3)"
```

---

## Task 15 — Documentation scaffolds: SECURITY / DEPLOYMENT / ARCHITECTURE (D-1)

**Files:**
- Create: `docs/SECURITY.md` (200-300 LOC)
- Create: `docs/DEPLOYMENT.md` (200-300 LOC)
- Create: `docs/ARCHITECTURE.md` (200-400 LOC)
- Modify: `README.md` (link the three)

- [ ] **Step 1: Write SECURITY.md**

Sections required:
1. **Threat model** — what we defend against (XSS via persisted HTML, CSRF, session fixation, brute-force login, IP spoofing, open redirects, SQL injection via PDO, file upload abuse), what we don't (DDoS, OS-level, supply chain).
2. **CSP** — current policy, what `'unsafe-inline'` covers, the migration path to CSP nonces.
3. **CSRF** — global gate, exemptions (`/api/v1/*`, `/f/*`, `/p/*`), token rotation.
4. **Sessions** — cookie attributes, regeneration on auth, 30-day remember-me.
5. **Login throttle** — DB-backed `LoginThrottle`, 5/15min default.
6. **API tokens** — `otk_*` format, SHA-256 storage, one-time reveal, revoke, rate-limit 60/min/token.
7. **Trusted proxies** — `TRUSTED_PROXIES` env, how the IP detection works.
8. **File uploads** — finfo MIME sniffing, UUID filename, max-size envs.
9. **Anti-bot on public forms** — honeypot + HMAC time-trap; `LOGIN_HASH` as the shared secret.
10. **HtmlSanitizer** — DOMDocument requirement; do NOT deploy without `ext-dom`.
11. **Reporting vulnerabilities** — security@your-domain or GitHub Security Advisories.

- [ ] **Step 2: Write DEPLOYMENT.md**

Sections required:
1. **PHP requirements** — version, extensions hard-required (`pdo`, `pdo_sqlite` or `pdo_mysql`, `dom`, `fileinfo`, `mbstring`, `curl`), bin/check-env.php if added.
2. **Filesystem layout** — `data/` writable, `public/uploads/` writable, never expose `data/` or `.env`.
3. **MySQL setup** — DSN format, user permissions, charset/collation, schema migration command.
4. **Apache vs nginx** — `.htaccess` ships with the package; nginx snippet sample.
5. **Cron / scheduled tasks** — none required (update-check on dashboard view; activity-log pruning manual via Compass).
6. **Backups** — `make package` excludes data; instructions for cron-based DB backup.
7. **Updates** — in-app updater config (`UPDATE_*` envs), `bin/self-update.php` CLI fallback.
8. **TLS** — production must terminate TLS; HSTS recommendation.
9. **Environment variables** — link to `.env.example` and list mandatory vs optional.
10. **Common pitfalls** — TRUSTED_PROXIES misconfiguration, session dir permissions, MySQL strict-mode quirks.

- [ ] **Step 3: Write ARCHITECTURE.md**

Sections required:
1. **High-level layout** — directory map with one-line responsibility per directory.
2. **Request flow** — `public/index.php` → session start → DI register → middleware → router → controller → view (or ApiKernel → handler → JSON).
3. **DI container** — `App::singleton()` pattern, factory laziness, why no boot-validation today.
4. **Routing** — web Router vs ApiKernel — and why they differ (deliberately divergent).
5. **Repositories** — pattern, naming conventions, how to add a new one.
6. **Services** — what counts as a service vs a helper.
7. **Migrations** — Blueprint DSL, naming rules, immutability.
8. **Event bus** — events list, listener registration in `system/Events/`.
9. **i18n** — catalogue layout, plural forms, `t()` server, `window.__t` client.
10. **Auth** — session-cookie + CSRF for web, Bearer for API.

For Tier 1 these can be SHORT (200-300 LOC each, scaffolds) — they will be filled in over time. Crucial sections (SECURITY, DEPLOYMENT) must be accurate as-of-shipping.

- [ ] **Step 4: Link from README.md**

In the existing "Documentation" section, add:

```markdown
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — request flow, DI, repositories, services, events
- [docs/SECURITY.md](docs/SECURITY.md) — threat model, CSP, CSRF, sessions, sanitizer, trusted proxies
- [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — PHP requirements, filesystem perms, MySQL setup, TLS, backups
```

- [ ] **Step 5: Commit**

```bash
git add docs/SECURITY.md docs/DEPLOYMENT.md docs/ARCHITECTURE.md README.md
git commit -m "docs: scaffold SECURITY + DEPLOYMENT + ARCHITECTURE (D-1)"
```

---

## Task 16 — QA-CHECKLIST.md refresh (D-2)

**Files:**
- Modify: `docs/QA-CHECKLIST.md`

- [ ] **Step 1: Read current checklist**

```bash
cat docs/QA-CHECKLIST.md
```

- [ ] **Step 2: Add 6 new sections** (one per shipped feature missing from current checklist):

1. **Forms** — public form submission with honeypot, anti-bot HMAC, optional auto-create-task, admin views submissions
2. **Polls** — admin creates → activates → contact gate → vote → dedup → close → summary task
3. **Short Links** — create with allowed URL schemes, redirect public, stats (clicks + unique), disable
4. **Updates** — check-for-update banner, install pipeline, backup snapshot, rollback from Backups
5. **MySQL migration** — Compass "Migrate to MySQL" wizard, plan with row counts, sync, verify, .env swap
6. **External API** — token creation in /profile/tokens, one-time reveal, revoke, curl /me, rate-limit observed

Each section ~6-8 checkboxes.

- [ ] **Step 3: Commit**

```bash
git add docs/QA-CHECKLIST.md
git commit -m "docs(qa): add forms/polls/links/updates/mysql/api sections (D-2)"
```

---

## Task 17 — Final pass: full suite, smoke, version bump, release

**Files:**
- Modify: `system/version.php`
- Modify: `TODO.md` (mark #9.1a as done, link to commit/tag)

- [ ] **Step 1: Run everything**

```bash
make unit 2>&1 | tail -3   # ≥ 230 passed
make api 2>&1 | tail -3    # ≥ 84 passed
make e2e 2>&1 | tail -5    # ≥ 114 passed
```

All green. Any flake → investigate; do not paper over.

- [ ] **Step 2: Manual smoke**

```bash
make dev &
sleep 2
# Browse: /, /login, /register, /, /projects, /projects/{id}, /profile/tokens
# Verify: aria-live announcements (open DevTools accessibility tree)
# Verify: PL locale renders toasts in Polish
# Try: 6 wrong logins → throttled (DB-backed now)
# Try: /api/v1/me with token; observe last_used_at + activity_log entry
make stop || pkill -f "php -S localhost:8000"
```

- [ ] **Step 3: Version bump**

```bash
sed -i '' "s/APP_VERSION = '1.1.0'/APP_VERSION = '1.2.0'/" system/version.php
```

- [ ] **Step 4: Mark TODO.md done**

```
done - #9.1a (Wave A — ship-blocker fixes, 2026-06-XX, tag v1.2.0):
   - S-1/S-2/S-4 closed (login throttle, session fixation, XFF spoofing)
   - V-1/V-2/V-6 a11y baseline
   - J-3/J-7/J-8/I-3 JS i18n + silent-catch sweep
   - K-1/K-2/O-1/O-2 packaging + CI parity
   - T-1/T-3 sanitizer + RolePolicy coverage
   - D-1/D-2 SECURITY/DEPLOYMENT/ARCHITECTURE docs
```

- [ ] **Step 5: Merge to main + tag + push**

```bash
git checkout main
git merge --no-ff fix/9-1a-ship-blockers -m "Merge wave 9.1a — ship-blocker fixes (v1.2.0)"
git tag -a v1.2.0 -m "v1.2.0 — ship-blocker fixes from TODO #9.1a"
git push origin main
git push origin v1.2.0
```

- [ ] **Step 6: Cleanup**

```bash
git branch -d fix/9-1a-ship-blockers
```

---

## Self-review (run after writing all 17 tasks)

**Spec coverage:**
- S-1, S-2, S-4: Tasks 2-5
- V-1, V-2, V-6: Tasks 6, 7, 8
- J-3, J-7, J-8, I-3: Tasks 13, 14
- K-1, K-2: Tasks 1, 8
- O-1, O-2: Tasks 9, 10
- T-1, T-3: Tasks 11, 12
- D-1, D-2: Tasks 15, 16

All 17 audit items accounted for.

**Placeholder scan:** no "TBD" / "fill in" — every step has concrete commands or code.

**Type consistency:** `LoginThrottle` constructor signature in Task 3 (`__construct(\PDO, max=5, windowSeconds=900)`) matches the wiring at Task 3 Step 6.

**Parallelisation hints:**
- Tasks 6, 7, 8, 11, 12, 15, 16 are independent — can be parallel-dispatched if the executing model supports it.
- Tasks 2 → 3 are sequential (migration before service).
- Tasks 13 + 14 touch overlapping JS files; serialise them.
- Task 17 must be last.
