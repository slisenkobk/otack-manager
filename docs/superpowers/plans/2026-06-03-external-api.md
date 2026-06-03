# External REST API + Token Auth — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a versioned HTTP+JSON API under `/api/v1/` authenticated by per-user API tokens, mirroring manager/employee capabilities, with rate limiting, audit logging, OpenAPI documentation, and a `/profile` UI to manage tokens.

**Architecture:** New `App\Api\V1` namespace parallel to `App\Controller`. An `ApiKernel` intercepts `/api/v1/*` in `public/index.php` before the web Router. Thin handlers reuse the existing `App\Repository\*` and `App\Service\RolePolicy`. Tokens stored as SHA-256 hashes; rate limiting and audit log persisted to SQLite using the same Migrations/Blueprint stack the rest of the app uses.

**Tech Stack:** PHP 8.2+, PDO/SQLite (MySQL-portable), hand-rolled test runner, Playwright, vanilla JS ES modules. No Composer, no Guzzle.

**Spec:** [docs/superpowers/specs/2026-06-03-external-api-design.md](../specs/2026-06-03-external-api-design.md)

---

## Conventions used throughout this plan

- **TDD always.** Every task: failing test → minimal impl → green → refactor → commit.
- **Run unit tests** with `make unit` (≈1 second). Run a single file with `php tests/run.php tests/unit` (it picks up all `test_*.php` in `tests/unit/`).
- **Test helper** `apply_migration($pdo, $path)` is defined in `tests/run.php` and is available to all unit tests.
- **Repository pattern:** see `system/Repository/ShortLinkRepository.php` for the reference style (constructor-injected `\PDO`, snake_case columns, `findById`/`listFor*` naming).
- **Migration pattern:** see `system/Database/migrations/20260522_080_comments.php` for FK syntax, `20260529_020_forms.php` for index/unique/default syntax.
- **Commit cadence:** one commit per task (every task ends in a commit step). Do not amend across tasks.
- **No `App::make()` inside handlers.** Wire dependencies through `ApiKernel` constructor injection. (Web controllers have a documented debt of inline `App::make()`; the new API surface should not repeat it.)
- **i18n:** any new user-visible string lands in `system/i18n/en.php`, `pl.php`, and `uk.php` simultaneously. EN is the source of truth.

---

## Task 1: Migration — `api_tokens` table

**Files:**
- Create: `system/Database/migrations/20260603_040_api_tokens.php`
- Create: `tests/unit/test_api_tokens_schema.php`

- [ ] **Step 1: Write the failing test**

Create `tests/unit/test_api_tokens_schema.php`:

```php
<?php
function _atSchemaPdo(): PDO {
    $pdo = \App\Database\Connection::open('sqlite::memory:');
    apply_migration($pdo, dirname(__DIR__, 2) . '/system/Database/migrations/20260603_040_api_tokens.php');
    return $pdo;
}

it('api_tokens table has expected columns', function () {
    $pdo = _atSchemaPdo();
    $cols = $pdo->query("PRAGMA table_info('api_tokens')")->fetchAll(PDO::FETCH_ASSOC);
    $names = array_column($cols, 'name');
    foreach (['id','user_id','name','token_hash','prefix','last_used_at','last_used_ip','created_at','expires_at','revoked_at'] as $expected) {
        assert_true(in_array($expected, $names, true), "missing column $expected (got: " . implode(',', $names) . ')');
    }
});

it('api_tokens.token_hash is unique', function () {
    $pdo = _atSchemaPdo();
    $now = time();
    $stmt = $pdo->prepare('INSERT INTO api_tokens (user_id, name, token_hash, prefix, created_at) VALUES (?,?,?,?,?)');
    $stmt->execute([1, 'a', str_repeat('a', 64), 'otk_aaaaaaaa', $now]);
    $threw = false;
    try { $stmt->execute([1, 'b', str_repeat('a', 64), 'otk_aaaaaaaa', $now]); }
    catch (\PDOException $_) { $threw = true; }
    assert_true($threw, 'duplicate token_hash should throw');
});

it('api_tokens cascades on user delete', function () {
    $pdo = _atSchemaPdo();
    apply_migration($pdo, dirname(__DIR__, 2) . '/system/Database/migrations/20260522_000_users.php');
    $pdo->exec("PRAGMA foreign_keys = ON");
    $pdo->exec("INSERT INTO users (name, email, password_hash, role, status, created_at) VALUES ('U','u@x','x','admin','active','2026-01-01')");
    $uid = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO api_tokens (user_id, name, token_hash, prefix, created_at) VALUES (?,?,?,?,?)')
        ->execute([$uid, 'a', str_repeat('a', 64), 'otk_aaaaaaaa', time()]);
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
    $remaining = (int)$pdo->query('SELECT COUNT(*) FROM api_tokens')->fetchColumn();
    assert_eq(0, $remaining);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make unit 2>&1 | grep -A1 "api_tokens"`
Expected: FAIL — "no such table: api_tokens".

- [ ] **Step 3: Write the migration**

Create `system/Database/migrations/20260603_040_api_tokens.php`:

```php
<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->createTableIfNotExists('api_tokens', function (Blueprint $t) {
        $t->id();
        $t->bigInteger('user_id');
        $t->string('name');
        $t->string('token_hash', 64)->unique();
        $t->string('prefix', 16);
        $t->bigInteger('last_used_at')->nullable();
        $t->string('last_used_ip', 64)->nullable();
        $t->bigInteger('created_at');
        $t->bigInteger('expires_at')->nullable();
        $t->bigInteger('revoked_at')->nullable();
        $t->index(['user_id', 'revoked_at'])->name('idx_api_tokens_user_active');
        $t->index(['token_hash'])->name('idx_api_tokens_hash');
        $t->foreign('user_id')->references('id')->on('users');
    });
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `make unit 2>&1 | tail -5`
Expected: all `api_tokens table…` cases pass; full suite still green.

- [ ] **Step 5: Commit**

```bash
git add system/Database/migrations/20260603_040_api_tokens.php tests/unit/test_api_tokens_schema.php
git commit -m "feat(api): add api_tokens migration + schema tests"
```

---

## Task 2: Migration — `api_rate_limits` table

**Files:**
- Create: `system/Database/migrations/20260603_050_api_rate_limits.php`
- Modify: `tests/unit/test_api_tokens_schema.php` (append a small case)

- [ ] **Step 1: Append failing test**

Add to `tests/unit/test_api_tokens_schema.php`:

```php
it('api_rate_limits table has expected columns', function () {
    $pdo = \App\Database\Connection::open('sqlite::memory:');
    apply_migration($pdo, dirname(__DIR__, 2) . '/system/Database/migrations/20260603_050_api_rate_limits.php');
    $cols = array_column($pdo->query("PRAGMA table_info('api_rate_limits')")->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['token_id','window_start','count'] as $c) {
        assert_true(in_array($c, $cols, true), "missing $c");
    }
});
```

- [ ] **Step 2: Run to confirm failure**

Run: `make unit 2>&1 | grep "api_rate_limits"`
Expected: FAIL — "no such table".

- [ ] **Step 3: Write the migration**

Create `system/Database/migrations/20260603_050_api_rate_limits.php`:

```php
<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->createTableIfNotExists('api_rate_limits', function (Blueprint $t) {
        $t->bigInteger('token_id')->primary();
        $t->bigInteger('window_start');
        $t->integer('count');
    });
};
```

(`primary()` on a single column is supported via the `Column::primary(true)` method — see `system/Database/Schema/Column.php:38`. If the Blueprint refuses to chain `primary()` directly on `bigInteger()`, fall back to `$t->primary(['token_id'])` after the column declaration.)

- [ ] **Step 4: Run to verify**

Run: `make unit 2>&1 | tail -3`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add system/Database/migrations/20260603_050_api_rate_limits.php tests/unit/test_api_tokens_schema.php
git commit -m "feat(api): add api_rate_limits migration"
```

---

## Task 3: `ApiTokenRepository`

**Files:**
- Create: `system/Repository/ApiTokenRepository.php`
- Create: `tests/unit/test_api_token_repo.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/unit/test_api_token_repo.php`:

```php
<?php
use App\Repository\ApiTokenRepository;

function _atPdo(): PDO {
    $pdo = \App\Database\Connection::open('sqlite::memory:');
    apply_migration($pdo, dirname(__DIR__, 2) . '/system/Database/migrations/20260522_000_users.php');
    apply_migration($pdo, dirname(__DIR__, 2) . '/system/Database/migrations/20260603_040_api_tokens.php');
    $pdo->exec("INSERT INTO users (name, email, password_hash, role, status, created_at) VALUES ('U','u@x','x','admin','active','2026-01-01')");
    return $pdo;
}

it('generate() yields otk_ + base62, ≥ 40 chars, fresh each call', function () {
    $a = ApiTokenRepository::generate();
    $b = ApiTokenRepository::generate();
    assert_true(str_starts_with($a, 'otk_'));
    assert_true(strlen($a) >= 40);
    assert_true((bool)preg_match('/^otk_[A-Za-z0-9]+$/', $a));
    assert_true($a !== $b);
});

it('hash() is deterministic sha256 hex (64 chars)', function () {
    $h = ApiTokenRepository::hash('otk_test');
    assert_eq(64, strlen($h));
    assert_eq($h, ApiTokenRepository::hash('otk_test'));
});

it('create() stores hashed token, returns id + plaintext only once', function () {
    $repo = new ApiTokenRepository(_atPdo());
    $res = $repo->create(1, 'CI');
    assert_true($res['id'] > 0);
    assert_true(str_starts_with($res['token'], 'otk_'));
    $row = $repo->findById($res['id']);
    assert_eq(ApiTokenRepository::hash($res['token']), $row['token_hash']);
    assert_eq('CI', $row['name']);
});

it('findActiveByToken() returns the row for a valid plaintext token', function () {
    $repo = new ApiTokenRepository(_atPdo());
    $res  = $repo->create(1, 'CI');
    $row  = $repo->findActiveByToken($res['token']);
    assert_true($row !== null);
    assert_eq($res['id'], (int)$row['id']);
});

it('findActiveByToken() returns null for unknown token', function () {
    $repo = new ApiTokenRepository(_atPdo());
    assert_eq(null, $repo->findActiveByToken('otk_nope'));
    assert_eq(null, $repo->findActiveByToken(''));
    assert_eq(null, $repo->findActiveByToken('not-our-prefix'));
});

it('findActiveByToken() returns null when revoked or expired', function () {
    $repo = new ApiTokenRepository(_atPdo());
    $a = $repo->create(1, 'A');
    $b = $repo->create(1, 'B', time() - 10);   // already expired
    $repo->revoke($a['id']);
    assert_eq(null, $repo->findActiveByToken($a['token']));
    assert_eq(null, $repo->findActiveByToken($b['token']));
});

it('listForUser() returns user tokens ordered by created_at DESC', function () {
    $repo = new ApiTokenRepository(_atPdo());
    $repo->create(1, 'old');
    usleep(1100);
    $repo->create(1, 'new');
    $list = $repo->listForUser(1);
    assert_eq(2, count($list));
    assert_eq('new', $list[0]['name']);
});

it('revoke() is idempotent and sets revoked_at', function () {
    $repo = new ApiTokenRepository(_atPdo());
    $t = $repo->create(1, 'x');
    $repo->revoke($t['id']);
    $row = $repo->findById($t['id']);
    assert_true($row['revoked_at'] !== null);
    $first = (int)$row['revoked_at'];
    $repo->revoke($t['id']);   // second call must not overwrite
    assert_eq($first, (int)$repo->findById($t['id'])['revoked_at']);
});

it('revokeAllForUser() revokes only the targeted user', function () {
    $pdo  = _atPdo();
    $pdo->exec("INSERT INTO users (name, email, password_hash, role, status, created_at) VALUES ('V','v@x','x','employee','active','2026-01-01')");
    $repo = new ApiTokenRepository($pdo);
    $repo->create(1, 'a');
    $repo->create(2, 'b');
    $repo->revokeAllForUser(1);
    $active = $repo->listActiveForUser(1);
    assert_eq(0, count($active));
    assert_eq(1, count($repo->listActiveForUser(2)));
});

it('touchUsage() updates last_used_at + last_used_ip', function () {
    $repo = new ApiTokenRepository(_atPdo());
    $t = $repo->create(1, 'x');
    $repo->touchUsage($t['id'], '203.0.113.7');
    $row = $repo->findById($t['id']);
    assert_eq('203.0.113.7', $row['last_used_ip']);
    assert_true((int)$row['last_used_at'] > 0);
});

it('prefix is "otk_" + first 8 chars of random suffix', function () {
    $repo = new ApiTokenRepository(_atPdo());
    $t = $repo->create(1, 'x');
    $row = $repo->findById($t['id']);
    assert_eq(substr($t['token'], 0, 12), $row['prefix']);
});
```

- [ ] **Step 2: Run to confirm failure**

Run: `make unit 2>&1 | tail -5`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the repository**

Create `system/Repository/ApiTokenRepository.php`:

```php
<?php
declare(strict_types=1);
namespace App\Repository;

final class ApiTokenRepository
{
    public function __construct(private \PDO $pdo) {}

    /** Generate a fresh plaintext token: 'otk_' + base62(random_bytes(32)). */
    public static function generate(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $bytes = random_bytes(32);
        $out = '';
        foreach (str_split($bytes) as $b) {
            $out .= $alphabet[ord($b) % 62];
        }
        return 'otk_' . $out;
    }

    /** Hash for storage / lookup. Plaintext is never stored. */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Create a token. Returns ['id' => int, 'token' => string]. Plaintext is shown to the user once. */
    public function create(int $userId, string $name, ?int $expiresAt = null): array
    {
        $token = self::generate();
        $hash  = self::hash($token);
        $stmt  = $this->pdo->prepare(
            'INSERT INTO api_tokens (user_id, name, token_hash, prefix, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $name, $hash, substr($token, 0, 12), time(), $expiresAt]);
        return ['id' => (int)$this->pdo->lastInsertId(), 'token' => $token];
    }

    public function findById(int $id): ?array
    {
        $s = $this->pdo->prepare('SELECT * FROM api_tokens WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    /** Active = not revoked, not expired, prefix-sane. Returns full row or null. */
    public function findActiveByToken(string $token): ?array
    {
        if (!str_starts_with($token, 'otk_') || strlen($token) < 40) return null;
        $s = $this->pdo->prepare(
            'SELECT * FROM api_tokens
              WHERE token_hash = ? AND revoked_at IS NULL
                AND (expires_at IS NULL OR expires_at > ?)'
        );
        $s->execute([self::hash($token), time()]);
        return $s->fetch() ?: null;
    }

    public function listForUser(int $userId): array
    {
        $s = $this->pdo->prepare('SELECT * FROM api_tokens WHERE user_id = ? ORDER BY created_at DESC, id DESC');
        $s->execute([$userId]);
        return $s->fetchAll();
    }

    public function listActiveForUser(int $userId): array
    {
        $s = $this->pdo->prepare(
            'SELECT * FROM api_tokens
              WHERE user_id = ? AND revoked_at IS NULL
                AND (expires_at IS NULL OR expires_at > ?)
              ORDER BY created_at DESC, id DESC'
        );
        $s->execute([$userId, time()]);
        return $s->fetchAll();
    }

    public function revoke(int $id): void
    {
        $s = $this->pdo->prepare('UPDATE api_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL');
        $s->execute([time(), $id]);
    }

    public function revokeAllForUser(int $userId): void
    {
        $s = $this->pdo->prepare('UPDATE api_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL');
        $s->execute([time(), $userId]);
    }

    public function touchUsage(int $id, string $ip): void
    {
        $s = $this->pdo->prepare('UPDATE api_tokens SET last_used_at = ?, last_used_ip = ? WHERE id = ?');
        $s->execute([time(), $ip, $id]);
    }
}
```

- [ ] **Step 4: Run tests**

Run: `make unit 2>&1 | tail -5`
Expected: all `ApiTokenRepository` cases pass, full suite green.

- [ ] **Step 5: Commit**

```bash
git add system/Repository/ApiTokenRepository.php tests/unit/test_api_token_repo.php
git commit -m "feat(api): ApiTokenRepository with create/find/revoke + tests"
```

---

## Task 4: `RateLimiter` service

**Files:**
- Create: `system/Api/V1/RateLimiter.php`
- Create: `tests/unit/test_api_rate_limiter.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/unit/test_api_rate_limiter.php`:

```php
<?php
use App\Api\V1\RateLimiter;

function _rlPdo(): PDO {
    $pdo = \App\Database\Connection::open('sqlite::memory:');
    apply_migration($pdo, dirname(__DIR__, 2) . '/system/Database/migrations/20260603_050_api_rate_limits.php');
    return $pdo;
}

it('first hit returns allowed with retryAfter=0', function () {
    $rl = new RateLimiter(_rlPdo(), max: 3, windowSeconds: 60);
    $r = $rl->check(42);
    assert_true($r['allowed']);
    assert_eq(0, $r['retry_after']);
    assert_eq(1, $r['count']);
});

it('counts increment within the same window', function () {
    $rl = new RateLimiter(_rlPdo(), max: 3, windowSeconds: 60);
    $rl->check(7); $rl->check(7);
    $r = $rl->check(7);
    assert_true($r['allowed']);
    assert_eq(3, $r['count']);
});

it('returns retry_after with allowed=false on overflow', function () {
    $rl = new RateLimiter(_rlPdo(), max: 2, windowSeconds: 60);
    $rl->check(1); $rl->check(1);
    $r = $rl->check(1);
    assert_true(!$r['allowed']);
    assert_true($r['retry_after'] > 0 && $r['retry_after'] <= 60);
});

it('resets after the window rolls', function () {
    $pdo = _rlPdo();
    $rl = new RateLimiter($pdo, max: 1, windowSeconds: 60);
    $rl->check(5);
    // Force the window to look stale
    $pdo->prepare('UPDATE api_rate_limits SET window_start = ? WHERE token_id = ?')
        ->execute([time() - 61, 5]);
    $r = $rl->check(5);
    assert_true($r['allowed']);
    assert_eq(1, $r['count']);
});

it('isolates tokens', function () {
    $rl = new RateLimiter(_rlPdo(), max: 1, windowSeconds: 60);
    $rl->check(1);
    $r = $rl->check(2);
    assert_true($r['allowed']);
});
```

- [ ] **Step 2: Run to confirm failure**

Run: `make unit 2>&1 | tail -5`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the limiter**

Create `system/Api/V1/RateLimiter.php`:

```php
<?php
declare(strict_types=1);
namespace App\Api\V1;

final class RateLimiter
{
    public function __construct(
        private \PDO $pdo,
        public readonly int $max = 60,
        public readonly int $windowSeconds = 60,
    ) {}

    /**
     * Check + increment for a token id. Returns:
     *   ['allowed' => bool, 'count' => int, 'retry_after' => int]
     * retry_after is seconds until the window resets when allowed=false, 0 otherwise.
     */
    public function check(int $tokenId): array
    {
        $now = time();
        $row = $this->pdo->prepare('SELECT window_start, count FROM api_rate_limits WHERE token_id = ?');
        $row->execute([$tokenId]);
        $r = $row->fetch();

        if (!$r || ($now - (int)$r['window_start']) >= $this->windowSeconds) {
            // Fresh window — UPSERT to 1.
            $this->pdo->prepare(
                'INSERT INTO api_rate_limits (token_id, window_start, count) VALUES (?, ?, 1)
                 ON CONFLICT(token_id) DO UPDATE SET window_start = excluded.window_start, count = 1'
            )->execute([$tokenId, $now]);
            return ['allowed' => true, 'count' => 1, 'retry_after' => 0];
        }

        $count = (int)$r['count'] + 1;
        $this->pdo->prepare('UPDATE api_rate_limits SET count = ? WHERE token_id = ?')
            ->execute([$count, $tokenId]);

        if ($count > $this->max) {
            $retry = $this->windowSeconds - ($now - (int)$r['window_start']);
            return ['allowed' => false, 'count' => $count, 'retry_after' => max(1, $retry)];
        }
        return ['allowed' => true, 'count' => $count, 'retry_after' => 0];
    }
}
```

- [ ] **Step 4: Run tests**

Run: `make unit 2>&1 | tail -5`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add system/Api/V1/RateLimiter.php tests/unit/test_api_rate_limiter.php
git commit -m "feat(api): RateLimiter with sliding window + tests"
```

---

## Task 5: `ApiResponse` + `JsonRequest` helpers

**Files:**
- Create: `system/Api/V1/ApiResponse.php`
- Create: `system/Api/V1/JsonRequest.php`
- Create: `tests/unit/test_api_response.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/unit/test_api_response.php`:

```php
<?php
use App\Api\V1\ApiResponse;
use App\Api\V1\JsonRequest;

it('ApiResponse::ok returns serialised array + status', function () {
    $r = ApiResponse::ok(['x' => 1]);
    assert_eq(200, $r['status']);
    assert_eq('{"x":1}', $r['body']);
    assert_eq('application/json; charset=utf-8', $r['headers']['Content-Type']);
});

it('ApiResponse::created sets 201', function () {
    assert_eq(201, ApiResponse::created(['id' => 9])['status']);
});

it('ApiResponse::noContent sets 204 and empty body', function () {
    $r = ApiResponse::noContent();
    assert_eq(204, $r['status']);
    assert_eq('', $r['body']);
});

it('ApiResponse::error envelope shape', function () {
    $r = ApiResponse::error(422, 'validation_failed', 'Title is required', ['title' => 'required']);
    assert_eq(422, $r['status']);
    $body = json_decode($r['body'], true);
    assert_eq('validation_failed', $body['error']);
    assert_eq('Title is required', $body['message']);
    assert_eq(['title' => 'required'], $body['fields']);
});

it('ApiResponse::paginated wraps items + next_cursor', function () {
    $r = ApiResponse::paginated([['id' => 1], ['id' => 2]], 2);
    $body = json_decode($r['body'], true);
    assert_eq([['id' => 1], ['id' => 2]], $body['items']);
    assert_eq(2, $body['next_cursor']);
});

it('JsonRequest::parse returns array on valid JSON', function () {
    $req = JsonRequest::parse('{"a":1,"b":"x"}');
    assert_eq(['a' => 1, 'b' => 'x'], $req);
});

it('JsonRequest::parse throws on malformed JSON', function () {
    $threw = false;
    try { JsonRequest::parse('{bad'); }
    catch (\InvalidArgumentException $_) { $threw = true; }
    assert_true($threw);
});

it('JsonRequest::parse returns empty array for empty body', function () {
    assert_eq([], JsonRequest::parse(''));
});

it('JsonRequest::require pulls required fields, throws on missing', function () {
    $body = ['title' => 'X'];
    assert_eq('X', JsonRequest::requireString($body, 'title'));
    $threw = false;
    try { JsonRequest::requireString($body, 'missing'); }
    catch (\InvalidArgumentException $e) { $threw = true; assert_eq('missing', $e->getMessage()); }
    assert_true($threw);
});
```

- [ ] **Step 2: Run to confirm failure**

Run: `make unit 2>&1 | tail -5`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement both helpers**

Create `system/Api/V1/ApiResponse.php`:

```php
<?php
declare(strict_types=1);
namespace App\Api\V1;

final class ApiResponse
{
    private const HEADERS = ['Content-Type' => 'application/json; charset=utf-8'];

    public static function ok(array $data, int $status = 200): array
    {
        return ['status' => $status, 'body' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'headers' => self::HEADERS];
    }

    public static function created(array $data): array { return self::ok($data, 201); }

    public static function noContent(): array
    {
        return ['status' => 204, 'body' => '', 'headers' => []];
    }

    public static function error(int $status, string $code, string $message, array $fields = []): array
    {
        $body = ['error' => $code, 'message' => $message];
        if ($fields) $body['fields'] = $fields;
        return ['status' => $status, 'body' => json_encode($body, JSON_UNESCAPED_UNICODE), 'headers' => self::HEADERS];
    }

    public static function paginated(array $items, ?int $nextCursor): array
    {
        return self::ok(['items' => $items, 'next_cursor' => $nextCursor]);
    }
}
```

Create `system/Api/V1/JsonRequest.php`:

```php
<?php
declare(strict_types=1);
namespace App\Api\V1;

final class JsonRequest
{
    /** Parse a raw JSON body. Empty string → []. Malformed → throws. */
    public static function parse(string $raw): array
    {
        if ($raw === '') return [];
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('malformed_json');
        }
        return $decoded;
    }

    public static function requireString(array $body, string $key): string
    {
        if (!array_key_exists($key, $body) || !is_string($body[$key]) || $body[$key] === '') {
            throw new \InvalidArgumentException($key);
        }
        return $body[$key];
    }

    public static function optionalString(array $body, string $key, ?string $default = null): ?string
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) return $default;
        return is_string($body[$key]) ? $body[$key] : $default;
    }

    public static function requireInt(array $body, string $key): int
    {
        if (!array_key_exists($key, $body) || !is_int($body[$key])) {
            throw new \InvalidArgumentException($key);
        }
        return $body[$key];
    }

    public static function optionalInt(array $body, string $key): ?int
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) return null;
        return is_int($body[$key]) ? $body[$key] : null;
    }

    public static function optionalBool(array $body, string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $body)) return $default;
        return (bool)$body[$key];
    }
}
```

- [ ] **Step 4: Run tests**

Run: `make unit 2>&1 | tail -5`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add system/Api/V1/ApiResponse.php system/Api/V1/JsonRequest.php tests/unit/test_api_response.php
git commit -m "feat(api): ApiResponse + JsonRequest helpers"
```

---

## Task 6: `TokenAuthenticator`

**Files:**
- Create: `system/Api/V1/TokenAuthenticator.php`
- Create: `tests/unit/test_api_token_authenticator.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/unit/test_api_token_authenticator.php`:

```php
<?php
use App\Api\V1\TokenAuthenticator;
use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;

function _authPdo(): array {
    $pdo = \App\Database\Connection::open('sqlite::memory:');
    apply_migration($pdo, dirname(__DIR__, 2) . '/system/Database/migrations/20260522_000_users.php');
    apply_migration($pdo, dirname(__DIR__, 2) . '/system/Database/migrations/20260603_040_api_tokens.php');
    $pdo->exec("INSERT INTO users (name, email, password_hash, role, status, created_at) VALUES ('U','u@x','x','employee','active','2026-01-01')");
    return [$pdo, new ApiTokenRepository($pdo), new UserRepository($pdo)];
}

it('returns null for missing header', function () {
    [$pdo, $tokens, $users] = _authPdo();
    $auth = new TokenAuthenticator($tokens, $users);
    assert_eq(null, $auth->authenticate(null));
});

it('returns null for non-Bearer header', function () {
    [$pdo, $tokens, $users] = _authPdo();
    $auth = new TokenAuthenticator($tokens, $users);
    assert_eq(null, $auth->authenticate('Basic abc'));
});

it('returns null for unknown token', function () {
    [$pdo, $tokens, $users] = _authPdo();
    $auth = new TokenAuthenticator($tokens, $users);
    assert_eq(null, $auth->authenticate('Bearer otk_nope'));
});

it('returns user row + token row for a valid token', function () {
    [$pdo, $tokens, $users] = _authPdo();
    $t = $tokens->create(1, 'x');
    $auth = new TokenAuthenticator($tokens, $users);
    $ctx = $auth->authenticate('Bearer ' . $t['token']);
    assert_true($ctx !== null);
    assert_eq(1, (int)$ctx['user']['id']);
    assert_eq($t['id'], (int)$ctx['token']['id']);
});

it('returns null for revoked token', function () {
    [$pdo, $tokens, $users] = _authPdo();
    $t = $tokens->create(1, 'x');
    $tokens->revoke($t['id']);
    $auth = new TokenAuthenticator($tokens, $users);
    assert_eq(null, $auth->authenticate('Bearer ' . $t['token']));
});

it('returns null when user has been blocked', function () {
    [$pdo, $tokens, $users] = _authPdo();
    $t = $tokens->create(1, 'x');
    $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute(['blocked', 1]);
    $auth = new TokenAuthenticator($tokens, $users);
    assert_eq(null, $auth->authenticate('Bearer ' . $t['token']));
});
```

- [ ] **Step 2: Run to confirm failure**

Run: `make unit 2>&1 | tail -5`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the authenticator**

Create `system/Api/V1/TokenAuthenticator.php`:

```php
<?php
declare(strict_types=1);
namespace App\Api\V1;

use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;

final class TokenAuthenticator
{
    public function __construct(
        private ApiTokenRepository $tokens,
        private UserRepository $users,
    ) {}

    /**
     * @param string|null $authHeader raw Authorization header value
     * @return array{user: array, token: array}|null
     */
    public function authenticate(?string $authHeader): ?array
    {
        if (!is_string($authHeader)) return null;
        if (!preg_match('/^Bearer\s+(otk_\S+)$/', $authHeader, $m)) return null;
        $token = $m[1];

        $tokenRow = $this->tokens->findActiveByToken($token);
        if (!$tokenRow) return null;

        $user = $this->users->findById((int)$tokenRow['user_id']);
        if (!$user || ($user['status'] ?? '') !== 'active') return null;

        return ['user' => $user, 'token' => $tokenRow];
    }
}
```

- [ ] **Step 4: Verify**

Run: `make unit 2>&1 | tail -5`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add system/Api/V1/TokenAuthenticator.php tests/unit/test_api_token_authenticator.php
git commit -m "feat(api): TokenAuthenticator (Bearer header → user + token row)"
```

---

## Task 7: `ApiKernel` + `/api/v1/ping` wired into `public/index.php`

**Files:**
- Create: `system/Api/V1/ApiKernel.php`
- Modify: `public/index.php` (insert early dispatch)
- Modify: `system/bootstrap.php` (autoloader covers `App\Api\…` namespace)
- Create: `tests/api/run.php` (integration test harness)
- Create: `tests/api/test_ping.php`
- Modify: `Makefile` (add `make api` target)

### 7a — Verify autoloader covers `App\Api\…`

- [ ] **Step 1: Check the bootstrap autoloader**

Run: `grep -n "spl_autoload_register\|namespace" system/bootstrap.php | head -20`

Confirm the autoloader maps `App\Foo\Bar` → `system/Foo/Bar.php` (PSR-4-like). If yes, no change needed. If it hard-codes a flat structure, add a clause that strips the leading `App\` and replaces `\` with `/` under `system/`. The plan assumes the existing autoloader already works; tasks 1-6 implicitly verified this because their tests passed.

### 7b — Build the kernel with a single route

- [ ] **Step 2: Write the failing integration test**

Create `tests/api/run.php`:

```php
<?php
declare(strict_types=1);

// Lightweight HTTP client for the integration suite. Spawns nothing; calls
// php -S in the background once per test process.

$root = dirname(__DIR__, 2);
require $root . '/system/bootstrap.php';

$pass = 0; $fail = 0;
function api_it(string $name, callable $fn): void {
    global $pass, $fail;
    try { $fn(); echo "  v $name\n"; $pass++; }
    catch (Throwable $e) { echo "  x $name\n    {$e->getMessage()}\n"; $fail++; }
}

function api_request(string $method, string $path, array $opts = []): array {
    $url = 'http://localhost:8765' . $path;
    $ctx = [
        'http' => [
            'method' => $method,
            'header' => array_merge(
                ['Accept: application/json'],
                $opts['headers'] ?? []
            ),
            'ignore_errors' => true,
            'timeout' => 5,
            'content' => $opts['body'] ?? '',
        ],
    ];
    if (isset($opts['body'])) {
        $ctx['http']['header'][] = 'Content-Type: application/json';
    }
    $stream = @fopen($url, 'r', false, stream_context_create($ctx));
    if (!$stream) throw new RuntimeException("fopen failed for $url");
    $body = stream_get_contents($stream);
    $meta = stream_get_meta_data($stream);
    fclose($stream);
    preg_match('#^HTTP/\S+ (\d+)#', $meta['wrapper_data'][0] ?? '', $m);
    $status = isset($m[1]) ? (int)$m[1] : 0;
    $json = $body === '' ? null : json_decode($body, true);
    return ['status' => $status, 'body' => $body, 'json' => $json, 'headers' => $meta['wrapper_data']];
}

// Start a one-off php -S pointed at the test DB.
$dataDb = $root . '/data/app.api-test.sqlite';
@unlink($dataDb); @unlink($dataDb . '-wal'); @unlink($dataDb . '-shm');
@unlink($root . '/data/.schema.api-test'); // not used by file but reset anyway

$env = [
    'DB_PATH=data/app.api-test.sqlite',
    'APP_URL=http://localhost:8765',
    'SEED_DEFAULT_ADMIN_EMAIL=',
    'SEED_DEFAULT_ADMIN_PASSWORD_HASH=',
    'PATH=' . getenv('PATH'),
];
$cmd = '/usr/bin/env ' . implode(' ', array_map('escapeshellarg', $env))
     . ' php -S localhost:8765 -t ' . escapeshellarg($root . '/public')
     . ' ' . escapeshellarg($root . '/public/index.php')
     . ' > /tmp/otack-api-test-server.log 2>&1 & echo $!';
$pid = (int)trim(shell_exec($cmd));
register_shutdown_function(fn() => posix_kill($pid, 15));

// Wait for the server to come up.
for ($i = 0; $i < 50; $i++) {
    $ok = @fsockopen('localhost', 8765, $_, $_, 0.1);
    if ($ok) { fclose($ok); break; }
    usleep(100_000);
}

$dir = __DIR__;
foreach (glob($dir . '/test_*.php') as $f) {
    echo basename($f) . "\n";
    require $f;
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
```

Create `tests/api/test_ping.php`:

```php
<?php
api_it('GET /api/v1/ping requires auth', function () {
    $r = api_request('GET', '/api/v1/ping');
    assert_eq(401, $r['status']);
    assert_eq('unauthorized', $r['json']['error']);
});

api_it('GET /api/v1/ping with bad token returns 401', function () {
    $r = api_request('GET', '/api/v1/ping', ['headers' => ['Authorization: Bearer otk_nope']]);
    assert_eq(401, $r['status']);
});

api_it('GET /api/v1/ping with valid token returns 200 + user id', function () {
    // Insert a user + token directly into the test DB via the same SQLite file.
    $pdo = \App\Database\Connection::open(dirname(__DIR__, 2) . '/data/app.api-test.sqlite');
    $pdo->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role, status, created_at) VALUES (1, 'U', 'u@x', 'x', 'admin', 'active', '2026-01-01')");
    $repo = new \App\Repository\ApiTokenRepository($pdo);
    $t = $repo->create(1, 'test');
    $r = api_request('GET', '/api/v1/ping', ['headers' => ['Authorization: Bearer ' . $t['token']]]);
    assert_eq(200, $r['status']);
    assert_eq(true, $r['json']['ok']);
    assert_eq(1, $r['json']['user_id']);
});
```

- [ ] **Step 3: Add Makefile target**

Append to `Makefile`:

```make
api:
	php tests/api/run.php
```

Add `api` to `.PHONY:` line at the top of `Makefile` (next to `unit`).

- [ ] **Step 4: Run to confirm failure**

Run: `make api 2>&1 | tail -10`
Expected: FAIL — kernel not present, every call 404s.

- [ ] **Step 5: Implement the kernel**

Create `system/Api/V1/ApiKernel.php`:

```php
<?php
declare(strict_types=1);
namespace App\Api\V1;

use App\Http\Request;

final class ApiKernel
{
    /** @var array<string, array{handler:string, action:string}> "METHOD PATTERN" => handler */
    private array $routes = [];

    public function __construct(
        private TokenAuthenticator $auth,
        private RateLimiter $limiter,
        private \App\Repository\ApiTokenRepository $tokens,
        private \App\Repository\ActivityLogRepository $activity,
        private \PDO $pdo,
        private array $services,   // ['projects' => ProjectRepository, ...] injected
    ) {
        $this->register();
    }

    private function register(): void
    {
        // Stub for Task 7; later tasks expand the table.
        $this->routes['GET /api/v1/ping'] = ['handler' => 'Ping', 'action' => 'ping'];
    }

    public function handle(Request $req): void
    {
        // Public schema endpoint — no auth, no rate limit.
        if ($req->method === 'GET' && $req->path === '/api/v1/openapi.yaml') {
            $this->serveOpenApi();
            return;
        }

        $ctx = $this->auth->authenticate($req->header('authorization'));
        if (!$ctx) {
            $this->send(ApiResponse::error(401, 'unauthorized', 'Missing or invalid API token'));
            return;
        }

        $rl = $this->limiter->check((int)$ctx['token']['id']);
        if (!$rl['allowed']) {
            $resp = ApiResponse::error(429, 'rate_limited', 'Too many requests');
            $resp['headers']['Retry-After'] = (string)$rl['retry_after'];
            $this->send($resp);
            return;
        }

        $this->tokens->touchUsage((int)$ctx['token']['id'], $req->ip ?? ($_SERVER['REMOTE_ADDR'] ?? ''));

        $key = $req->method . ' ' . $this->normalisePath($req->path);
        $match = $this->routes[$key] ?? null;
        if (!$match) {
            $this->send(ApiResponse::error(404, 'not_found', 'Route not found'));
            return;
        }

        try {
            $resp = $this->dispatch($match, $req, $ctx);
        } catch (\InvalidArgumentException $e) {
            $resp = ApiResponse::error(422, 'validation_failed', 'Invalid input', [$e->getMessage() => 'required_or_invalid']);
        } catch (\Throwable $e) {
            error_log('[api] ' . $e);
            $resp = ApiResponse::error(500, 'server_error', 'Internal error');
        }

        $this->activity->log(
            'api.' . strtolower($match['handler']) . '.' . $match['action'],
            (int)$ctx['user']['id'],
            null, null,
            ['route' => $key, 'status' => $resp['status'], 'token_id' => (int)$ctx['token']['id']]
        );

        $this->send($resp);
    }

    /** Strip path params from the route key (handled by handlers themselves). */
    private function normalisePath(string $path): string
    {
        // Replace numeric segments and short slugs with placeholders so the
        // route table can use `{id}` keys.
        return preg_replace('#/\d+#', '/{id}', $path) ?? $path;
    }

    private function dispatch(array $match, Request $req, array $ctx): array
    {
        if ($match['handler'] === 'Ping') {
            return ApiResponse::ok(['ok' => true, 'user_id' => (int)$ctx['user']['id']]);
        }
        $class = '\\App\\Api\\V1\\Handlers\\' . $match['handler'] . 'Handler';
        if (!class_exists($class)) {
            return ApiResponse::error(404, 'not_found', 'Route not found');
        }
        $handler = new $class($this->pdo, $this->services, $ctx);
        return $handler->{$match['action']}($req);
    }

    private function serveOpenApi(): void
    {
        $path = APP_ROOT . '/docs/openapi.yaml';
        if (!is_file($path)) { http_response_code(404); echo '# openapi.yaml not present yet'; return; }
        header('Content-Type: application/yaml; charset=utf-8');
        readfile($path);
    }

    private function send(array $resp): void
    {
        http_response_code($resp['status']);
        foreach ($resp['headers'] ?? [] as $k => $v) header("$k: $v");
        echo $resp['body'];
    }
}
```

- [ ] **Step 6: Wire the kernel into `public/index.php`**

Insert immediately after the `$req = Request::fromGlobals();` line (around `public/index.php:340`):

```php
// ─── /api/v1/* hand-off ──────────────────────────────────────────────────────
if (str_starts_with($req->path, '/api/v1/')) {
    $services = [
        'projects'  => App::make('projects'),
        'members'   => App::make('members'),
        'columns'   => App::make('columns'),
        'tasks'     => App::make('tasks'),
        'task_links'=> App::make('task_links'),
        'comments'  => App::make('comments'),
        'attachments'=> App::make('attachments'),
        'tags'      => App::make('tags'),
        'forms'     => App::make('forms'),
        'form_submissions' => App::make('form_submissions'),
        'polls'     => App::make('polls'),
        'poll_votes'=> App::make('poll_votes'),
        'uploader'  => App::make('uploader'),
        'users'     => App::make('users'),
    ];
    $kernel = new \App\Api\V1\ApiKernel(
        new \App\Api\V1\TokenAuthenticator(
            new \App\Repository\ApiTokenRepository(App::make('db')),
            App::make('users'),
        ),
        new \App\Api\V1\RateLimiter(App::make('db'), max: 60, windowSeconds: 60),
        new \App\Repository\ApiTokenRepository(App::make('db')),
        App::make('activity'),
        App::make('db'),
        $services,
    );
    $kernel->handle($req);
    exit;
}
```

- [ ] **Step 7: Run integration tests**

Run: `make api 2>&1 | tail -20`
Expected: 3 cases pass. If the server fails to start, check `/tmp/otack-api-test-server.log`.

- [ ] **Step 8: Commit**

```bash
git add system/Api/V1/ApiKernel.php public/index.php Makefile tests/api/run.php tests/api/test_ping.php
git commit -m "feat(api): ApiKernel + /api/v1/ping + integration test harness"
```

---

## Task 8: `MeHandler`

**Files:**
- Create: `system/Api/V1/Handlers/BaseHandler.php`
- Create: `system/Api/V1/Handlers/MeHandler.php`
- Modify: `system/Api/V1/ApiKernel.php` (register route)
- Create: `tests/api/test_me.php`

- [ ] **Step 1: Write the failing test**

Create `tests/api/test_me.php`:

```php
<?php
api_it('GET /api/v1/me returns user identity', function () {
    $pdo = \App\Database\Connection::open(dirname(__DIR__, 2) . '/data/app.api-test.sqlite');
    $repo = new \App\Repository\ApiTokenRepository($pdo);
    $row = $pdo->query("SELECT id FROM users WHERE email='u@x'")->fetch();
    $t = $repo->create((int)$row['id'], 'me-test');
    $r = api_request('GET', '/api/v1/me', ['headers' => ['Authorization: Bearer ' . $t['token']]]);
    assert_eq(200, $r['status']);
    assert_eq((int)$row['id'], $r['json']['id']);
    assert_eq('u@x', $r['json']['email']);
    assert_true(in_array($r['json']['role'], ['admin','manager','employee'], true));
});
```

- [ ] **Step 2: Run to confirm failure**

Run: `make api 2>&1 | tail -5`
Expected: FAIL — 404 from kernel.

- [ ] **Step 3: Implement `BaseHandler`**

Create `system/Api/V1/Handlers/BaseHandler.php`:

```php
<?php
declare(strict_types=1);
namespace App\Api\V1\Handlers;

use App\Api\V1\ApiResponse;
use App\Api\V1\JsonRequest;
use App\Http\Request;

abstract class BaseHandler
{
    /** @param array<string, object> $services repositories/services keyed by short name */
    public function __construct(
        protected \PDO $pdo,
        protected array $services,
        protected array $ctx,   // ['user' => ..., 'token' => ...]
    ) {}

    protected function user(): array { return $this->ctx['user']; }
    protected function userId(): int { return (int)$this->ctx['user']['id']; }

    protected function svc(string $name): object
    {
        if (!isset($this->services[$name])) {
            throw new \LogicException("service $name not wired");
        }
        return $this->services[$name];
    }

    protected function readBody(Request $req): array
    {
        return JsonRequest::parse($req->rawBody ?? file_get_contents('php://input') ?: '');
    }

    /** Pull a numeric path id from /api/v1/<resource>/<id>(/...). */
    protected function pathId(Request $req, int $segmentIndex): int
    {
        $parts = explode('/', trim($req->path, '/'));
        return isset($parts[$segmentIndex]) ? (int)$parts[$segmentIndex] : 0;
    }

    protected function notFound(): array { return ApiResponse::error(404, 'not_found', 'Not found'); }
    protected function forbidden(): array { return ApiResponse::error(403, 'forbidden', 'Action not permitted'); }
}
```

Create `system/Api/V1/Handlers/MeHandler.php`:

```php
<?php
declare(strict_types=1);
namespace App\Api\V1\Handlers;

use App\Api\V1\ApiResponse;
use App\Http\Request;

final class MeHandler extends BaseHandler
{
    public function show(Request $req): array
    {
        $u = $this->user();
        return ApiResponse::ok([
            'id'     => (int)$u['id'],
            'name'   => $u['name'],
            'email'  => $u['email'],
            'role'   => $u['role'],
            'locale' => $u['locale'] ?? 'en',
        ]);
    }
}
```

- [ ] **Step 4: Register the route in `ApiKernel::register()`**

Add to the `register()` method (replace the existing single ping line):

```php
$this->routes['GET /api/v1/ping'] = ['handler' => 'Ping', 'action' => 'ping'];
$this->routes['GET /api/v1/me']   = ['handler' => 'Me',   'action' => 'show'];
```

- [ ] **Step 5: Verify**

Run: `make api 2>&1 | tail -5`
Expected: PASS for `GET /api/v1/me returns user identity`.

- [ ] **Step 6: Commit**

```bash
git add system/Api/V1/Handlers/BaseHandler.php system/Api/V1/Handlers/MeHandler.php system/Api/V1/ApiKernel.php tests/api/test_me.php
git commit -m "feat(api): MeHandler + BaseHandler scaffold"
```

---

## Task 9: `ProjectsHandler` — read endpoints

**Files:**
- Create: `system/Api/V1/Handlers/ProjectsHandler.php`
- Modify: `system/Api/V1/ApiKernel.php` (register 2 routes)
- Create: `tests/api/test_projects_read.php`

Endpoints in this task: `GET /api/v1/projects`, `GET /api/v1/projects/{id}`.

- [ ] **Step 1: Write failing tests**

Create `tests/api/test_projects_read.php`:

```php
<?php
api_it('GET /projects lists visible projects with cursor', function () {
    $pdo = \App\Database\Connection::open(dirname(__DIR__, 2) . '/data/app.api-test.sqlite');
    $repo = new \App\Repository\ApiTokenRepository($pdo);
    $row = $pdo->query("SELECT id FROM users WHERE email='u@x'")->fetch();
    $uid = (int)$row['id'];
    $pdo->exec("INSERT OR IGNORE INTO projects (id, name, slug, color, status, created_by, created_at, updated_at) VALUES (101, 'Alpha', 'alpha', '#fff', 'active', $uid, '2026-01-01', '2026-01-01')");
    $t = $repo->create($uid, 'proj-read');

    $r = api_request('GET', '/api/v1/projects', ['headers' => ['Authorization: Bearer ' . $t['token']]]);
    assert_eq(200, $r['status']);
    assert_true(array_key_exists('items', $r['json']));
    assert_true(array_key_exists('next_cursor', $r['json']));
    $ids = array_column($r['json']['items'], 'id');
    assert_true(in_array(101, $ids, true));
});

api_it('GET /projects/{id} returns 404 for non-existent', function () {
    $pdo = \App\Database\Connection::open(dirname(__DIR__, 2) . '/data/app.api-test.sqlite');
    $repo = new \App\Repository\ApiTokenRepository($pdo);
    $uid = (int)$pdo->query("SELECT id FROM users WHERE email='u@x'")->fetch()['id'];
    $t = $repo->create($uid, 'proj-404');
    $r = api_request('GET', '/api/v1/projects/999999', ['headers' => ['Authorization: Bearer ' . $t['token']]]);
    assert_eq(404, $r['status']);
});

api_it('GET /projects/{id} returns 404 for project the caller cannot see (employee not a member)', function () {
    $pdo = \App\Database\Connection::open(dirname(__DIR__, 2) . '/data/app.api-test.sqlite');
    $repo = new \App\Repository\ApiTokenRepository($pdo);
    $pdo->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role, status, created_at) VALUES (2, 'E', 'e@x', 'x', 'employee', 'active', '2026-01-01')");
    $pdo->exec("INSERT OR IGNORE INTO projects (id, name, slug, color, status, created_by, created_at, updated_at) VALUES (202, 'Hidden', 'hidden', '#fff', 'active', 1, '2026-01-01', '2026-01-01')");
    $t = $repo->create(2, 'proj-hidden');
    $r = api_request('GET', '/api/v1/projects/202', ['headers' => ['Authorization: Bearer ' . $t['token']]]);
    assert_eq(404, $r['status']);   // hidden = 404, not 403, per spec (don't leak existence)
});
```

- [ ] **Step 2: Run to confirm failure**

Run: `make api 2>&1 | tail -8`
Expected: FAIL on all three.

- [ ] **Step 3: Implement the handler**

Create `system/Api/V1/Handlers/ProjectsHandler.php`:

```php
<?php
declare(strict_types=1);
namespace App\Api\V1\Handlers;

use App\Api\V1\ApiResponse;
use App\Service\RolePolicy;
use App\Http\Request;

final class ProjectsHandler extends BaseHandler
{
    public function index(Request $req): array
    {
        $after = isset($req->query['after']) ? (int)$req->query['after'] : 0;
        $limit = min(100, max(1, (int)($req->query['limit'] ?? 50)));
        $isAdmin = RolePolicy::isAdmin($this->user());
        $projects = $this->svc('projects'); // ProjectRepository
        $members  = $this->svc('members');

        $items = $isAdmin
            ? $projects->listAfterId($after, $limit + 1)
            : $projects->listVisibleToUserAfterId($this->userId(), $after, $limit + 1, $members);

        $next = null;
        if (count($items) > $limit) {
            $items = array_slice($items, 0, $limit);
            $next = (int)end($items)['id'];
        }
        return ApiResponse::paginated($this->serializeMany($items), $next);
    }

    public function show(Request $req): array
    {
        $id = $this->pathId($req, 2);
        $project = $this->svc('projects')->findById($id);
        if (!$project) return $this->notFound();
        if (!$this->canSee($project)) return $this->notFound();

        $columns = $this->svc('columns')->listForProject($id);
        $members = $this->svc('members')->listForProject($id);
        return ApiResponse::ok($this->serializeOne($project, $columns, $members));
    }

    private function canSee(array $project): bool
    {
        if (RolePolicy::isAdmin($this->user())) return true;
        $members = $this->svc('members');
        return $members->isMember($this->userId(), (int)$project['id'])
            || (int)$project['created_by'] === $this->userId();
    }

    private function serializeMany(array $rows): array
    {
        return array_map(fn($p) => [
            'id'         => (int)$p['id'],
            'name'       => $p['name'],
            'slug'       => $p['slug'] ?? null,
            'color'      => $p['color'] ?? null,
            'status'     => $p['status'] ?? 'active',
            'pinned'     => !empty($p['pinned_at']),
            'created_at' => $this->isoTime($p['created_at']),
            'updated_at' => $this->isoTime($p['updated_at']),
        ], $rows);
    }

    private function serializeOne(array $p, array $columns, array $members): array
    {
        $base = $this->serializeMany([$p])[0];
        $base['columns'] = array_map(fn($c) => [
            'id' => (int)$c['id'], 'name' => $c['name'], 'position' => (int)$c['position'],
        ], $columns);
        $base['members'] = array_map(fn($m) => [
            'user_id' => (int)$m['user_id'], 'name' => $m['name'] ?? null,
        ], $members);
        return $base;
    }

    private function isoTime($v): ?string
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) return gmdate('Y-m-d\TH:i:s\Z', (int)$v);
        try { return (new \DateTimeImmutable($v))->format('Y-m-d\TH:i:s\Z'); }
        catch (\Throwable $_) { return (string)$v; }
    }
}
```

**Note on repository method names:** `listAfterId` / `listVisibleToUserAfterId` / `isMember` may not exist verbatim in the existing repositories. If a needed method is missing, **add it to the repository in this task** (not the handler) with a corresponding unit test in `tests/unit/test_project_repo.php`. Keep the addition tightly scoped to what the handler needs. Verify with `grep -n "public function" system/Repository/ProjectRepository.php` and `…/ProjectMemberRepository.php` before assuming a method exists.

- [ ] **Step 4: Register routes**

Append to `ApiKernel::register()`:

```php
$this->routes['GET /api/v1/projects']      = ['handler' => 'Projects', 'action' => 'index'];
$this->routes['GET /api/v1/projects/{id}'] = ['handler' => 'Projects', 'action' => 'show'];
```

- [ ] **Step 5: Run integration tests**

Run: `make api 2>&1 | tail -10`
Expected: 3 new cases pass.

- [ ] **Step 6: Commit**

```bash
git add system/Api/V1/Handlers/ProjectsHandler.php system/Api/V1/ApiKernel.php tests/api/test_projects_read.php system/Repository/
git commit -m "feat(api): ProjectsHandler — list + show"
```

---

## Task 10: `ProjectsHandler` — write endpoints

Endpoints: `POST /projects`, `PATCH /projects/{id}`, `DELETE /projects/{id}`, `POST /projects/{id}/pin`, `POST /projects/{id}/members`, `DELETE /projects/{id}/members/{user_id}`.

- [ ] **Step 1: Write failing tests** in `tests/api/test_projects_write.php`

For each endpoint write one happy-path and one denial test. Use the pattern from `test_projects_read.php`. Required cases (one `api_it` per bullet):

- `POST /projects` as admin → 201, returns id+name, project visible in subsequent GET.
- `POST /projects` as employee → 403 (`RolePolicy::canCreateProject` returns false).
- `POST /projects` with empty `name` → 422 `validation_failed`.
- `PATCH /projects/{id}` as admin renames → 200, GET reflects change.
- `PATCH /projects/{id}` non-existent id → 404.
- `DELETE /projects/{id}` as admin → 204, GET returns 404 after.
- `DELETE /projects/{id}` as employee → 403.
- `POST /projects/{id}/pin` with `{"pinned": true}` → 200, GET shows `pinned: true`. Second call with the same body → 200, still true (idempotent).
- `POST /projects/{id}/members` → 201, GET project shows the new member.
- `DELETE /projects/{id}/members/{user_id}` → 204.

- [ ] **Step 2: Run, expect FAIL**, then implement handler methods on `ProjectsHandler`:

```php
public function create(Request $req): array
{
    if (!RolePolicy::canCreateProject($this->user())) return $this->forbidden();
    $body = $this->readBody($req);
    try { $name = JsonRequest::requireString($body, 'name'); }
    catch (\InvalidArgumentException $e) { return ApiResponse::error(422, 'validation_failed', 'name is required', ['name' => 'required']); }
    $id = $this->svc('projects')->create($name, JsonRequest::optionalString($body, 'color'), JsonRequest::optionalString($body, 'description'), $this->userId());
    $project = $this->svc('projects')->findById($id);
    return ApiResponse::created($this->serializeMany([$project])[0]);
}

public function update(Request $req): array
{
    $id = $this->pathId($req, 2);
    $project = $this->svc('projects')->findById($id);
    if (!$project) return $this->notFound();
    if (!RolePolicy::canEditProject($this->user(), $project, $this->svc('members'))) return $this->forbidden();
    $body = $this->readBody($req);
    $patch = array_filter([
        'name'        => JsonRequest::optionalString($body, 'name'),
        'color'       => JsonRequest::optionalString($body, 'color'),
        'description' => JsonRequest::optionalString($body, 'description'),
        'status'      => JsonRequest::optionalString($body, 'status'),
    ], fn($v) => $v !== null);
    $this->svc('projects')->update($id, $patch);
    return ApiResponse::ok($this->serializeMany([$this->svc('projects')->findById($id)])[0]);
}

public function destroy(Request $req): array
{
    $id = $this->pathId($req, 2);
    $project = $this->svc('projects')->findById($id);
    if (!$project) return $this->notFound();
    if (!RolePolicy::isAdmin($this->user())) return $this->forbidden();
    $this->svc('projects')->delete($id);
    return ApiResponse::noContent();
}

public function setPin(Request $req): array
{
    $id = $this->pathId($req, 2);
    $project = $this->svc('projects')->findById($id);
    if (!$project) return $this->notFound();
    if (!$this->canSee($project)) return $this->notFound();
    $pinned = JsonRequest::optionalBool($this->readBody($req), 'pinned', false);
    $this->svc('projects')->setPinned($id, $this->userId(), $pinned);
    return ApiResponse::ok(['id' => $id, 'pinned' => $pinned]);
}

public function addMember(Request $req): array
{
    $id = $this->pathId($req, 2);
    $project = $this->svc('projects')->findById($id);
    if (!$project) return $this->notFound();
    if (!RolePolicy::canEditProject($this->user(), $project, $this->svc('members'))) return $this->forbidden();
    try { $userId = JsonRequest::requireInt($this->readBody($req), 'user_id'); }
    catch (\InvalidArgumentException $e) { return ApiResponse::error(422, 'validation_failed', 'user_id required', ['user_id' => 'required']); }
    $this->svc('members')->add($id, $userId);
    return ApiResponse::created(['project_id' => $id, 'user_id' => $userId]);
}

public function removeMember(Request $req): array
{
    $id = $this->pathId($req, 2);
    $userId = $this->pathId($req, 4);
    $project = $this->svc('projects')->findById($id);
    if (!$project) return $this->notFound();
    if (!RolePolicy::canEditProject($this->user(), $project, $this->svc('members'))) return $this->forbidden();
    $this->svc('members')->remove($id, $userId);
    return ApiResponse::noContent();
}
```

- [ ] **Step 3: Register routes** in `ApiKernel::register()`:

```php
$this->routes['POST /api/v1/projects']                       = ['handler' => 'Projects', 'action' => 'create'];
$this->routes['PATCH /api/v1/projects/{id}']                 = ['handler' => 'Projects', 'action' => 'update'];
$this->routes['DELETE /api/v1/projects/{id}']                = ['handler' => 'Projects', 'action' => 'destroy'];
$this->routes['POST /api/v1/projects/{id}/pin']              = ['handler' => 'Projects', 'action' => 'setPin'];
$this->routes['POST /api/v1/projects/{id}/members']          = ['handler' => 'Projects', 'action' => 'addMember'];
$this->routes['DELETE /api/v1/projects/{id}/members/{id}']   = ['handler' => 'Projects', 'action' => 'removeMember'];
```

The kernel's `normalisePath` already collapses any `/\d+` segment to `/{id}`, so both `members/{id}` are matched by the same pattern. The handler uses `pathId(2)` and `pathId(4)` to distinguish.

- [ ] **Step 4: Run integration tests**

Run: `make api 2>&1 | tail -15`
Expected: all 10 new cases pass.

- [ ] **Step 5: Commit**

```bash
git add system/Api/V1/Handlers/ProjectsHandler.php system/Api/V1/ApiKernel.php tests/api/test_projects_write.php
git commit -m "feat(api): ProjectsHandler — create/update/delete/pin/members"
```

---

## Task 11: `ColumnsHandler`

Endpoints: `GET /projects/{id}/columns`, `POST /projects/{id}/columns`, `PATCH /columns/{id}`, `DELETE /columns/{id}`, `POST /projects/{id}/columns/reorder`.

Follow the same shape as Task 9-10:

- [ ] **Step 1: Tests** in `tests/api/test_columns.php` — one happy + one role-denial per endpoint. Reorder test: send `{"order":[3,1,2]}` and assert `GET /projects/{id}` shows the new order.

- [ ] **Step 2: Implement** `system/Api/V1/Handlers/ColumnsHandler.php` extending `BaseHandler`. Use `TaskColumnRepository` (alias `columns` in services). Authorization: `RolePolicy::canEditProject` for write methods; read requires `canSee` from `ProjectsHandler` — extract that to `BaseHandler::canSeeProject(array $project): bool` and call it from both handlers.

- [ ] **Step 3: Register routes** in `ApiKernel::register()`.

- [ ] **Step 4: Run `make api`, expect green.**

- [ ] **Step 5: Commit** as `feat(api): ColumnsHandler`.

---

## Task 12: `TasksHandler`

The biggest handler. Endpoints:
- `GET /projects/{id}/tasks` (paginated with filters `column_id`, `assignee_id`, `tag_id`, `status`, `priority`, `search`)
- `GET /tasks/{id}`
- `POST /projects/{id}/tasks`
- `PATCH /tasks/{id}`
- `POST /tasks/{id}/move`
- `DELETE /tasks/{id}`
- `POST /tasks/{id}/promote-to-project`
- `POST /tasks/{id}/links` body `{ "other_id": int }`
- `DELETE /tasks/{id}/links/{other_id}`

- [ ] **Step 1: Write tests** `tests/api/test_tasks.php` — happy + denial per endpoint, plus:
  - filter test: create 3 tasks across 2 columns, GET with `?column_id=X` returns only that column's tasks
  - pagination test: create 60 tasks, GET with `limit=25`, verify `items.length == 25` and a non-null `next_cursor`; follow the cursor and confirm union covers all 60
  - move test: PATCH succeeds, GET shows new column + position

- [ ] **Step 2: Implement** `system/Api/V1/Handlers/TasksHandler.php`. Reuse `TaskRepository` extensively. Authorization via `RolePolicy::canEditTask` for writes and `BaseHandler::canSeeProject` for reads.

- [ ] **Step 3: Register routes** in `ApiKernel`.

- [ ] **Step 4: Run `make api`.**

- [ ] **Step 5: Commit** as `feat(api): TasksHandler — full CRUD + links + move + promote`.

---

## Task 13: `CommentsHandler`

Endpoints:
- `GET /tasks/{id}/comments`
- `GET /projects/{id}/comments`
- `POST /comments` body `{ "entity": "task"|"project", "entity_id": int, "body": "...", "parent_id": int? }`
- `DELETE /comments/{id}`

**Important:** "comment author or admin can delete" is currently inline in `CommentController`. Per the spec's "RolePolicy consolidation" note, **first extract** this to `RolePolicy::canDeleteComment(array $user, array $comment): bool`, update `CommentController` to use it, then call it from `CommentsHandler::destroy`.

- [ ] **Step 1: Extract `canDeleteComment`** to `system/Service/RolePolicy.php` with unit tests in `tests/unit/test_role_policy.php` (create if missing).
- [ ] **Step 2: Update `CommentController::delete`** to use the new method. Re-run `make unit` and confirm green.
- [ ] **Step 3: Commit** as `refactor(policy): extract canDeleteComment from CommentController`.
- [ ] **Step 4: Write API tests** `tests/api/test_comments.php`.
- [ ] **Step 5: Implement** `CommentsHandler` calling `RolePolicy::canDeleteComment`. Render comment bodies as raw Markdown (the field already stores Markdown); do **not** HTML-escape — clients receive the raw text and can pipe through their own renderer.
- [ ] **Step 6: Register routes, run `make api`.**
- [ ] **Step 7: Commit** as `feat(api): CommentsHandler`.

---

## Task 14: `TagsHandler`

Endpoints:
- `GET /projects/{id}/tags`
- `GET /tags` (admin only)
- `POST /tags` body `{ "name": "...", "color": "..."? }` (admin only)
- `POST /projects/{id}/tags` body `{ "tag_id": int }`
- `DELETE /projects/{id}/tags/{tag_id}`
- `POST /tasks/{id}/tags` body `{ "tag_id": int }`
- `DELETE /tasks/{id}/tags/{tag_id}`

- [ ] **Step 1: Tests** `tests/api/test_tags.php` — one happy + one denial per endpoint.
- [ ] **Step 2: Implement** `TagsHandler`. Use `TagRepository`.
- [ ] **Step 3: Register routes, run `make api`.**
- [ ] **Step 4: Commit** as `feat(api): TagsHandler`.

---

## Task 15: `AttachmentsHandler` (multipart upload)

Endpoints:
- `GET /tasks/{id}/attachments`
- `GET /projects/{id}/attachments`
- `POST /attachments` (multipart/form-data, fields: `entity`, `entity_id`, `file`)
- `DELETE /attachments/{id}`

**Multipart in this kernel:** the kernel currently expects JSON. The upload path is the only exception. In `ApiKernel::dispatch`, detect `Content-Type: multipart/form-data` for `POST /api/v1/attachments` and pass `$_FILES` + `$_POST` through to the handler via `Request->files` and `Request->post`. (`App\Http\Request::fromGlobals` already populates these.)

- [ ] **Step 1: Tests** `tests/api/test_attachments.php`. Use PHP's `--data-binary` style via the integration harness — easier path: spin a curl subprocess for these specific cases since `stream_context_create` doesn't easily compose multipart. Allowed: a thin helper `api_upload($path, $token, $fields, $filePath)` that shells out to `curl -s -X POST -H "Authorization: Bearer ..." -F "entity=task" -F "entity_id=1" -F "file=@path" http://localhost:8765/...`.
- [ ] **Step 2: Implement** `AttachmentsHandler` calling `FileUploader::validate` and `FileUploader::store` (existing service). Reuse exact MIME/size limits — do not re-implement.
- [ ] **Step 3: Register routes, run `make api`.**
- [ ] **Step 4: Commit** as `feat(api): AttachmentsHandler with multipart upload`.

---

## Task 16: `FormsHandler` + `PollsHandler` (read-only)

Endpoints:
- `GET /forms`, `GET /forms/{id}`, `GET /forms/{id}/submissions`, `GET /submissions/{id}` — all gated by `RolePolicy::canViewFormsData` for non-admin, admin sees all.
- `GET /polls`, `GET /polls/{id}` (returns stats), `GET /polls/{id}/voters` — voters list gated by `RolePolicy::canManagePolls`.

- [ ] **Step 1: Tests** `tests/api/test_forms.php` + `tests/api/test_polls.php`.
- [ ] **Step 2: Implement** both handlers; read-only, no write endpoints.
- [ ] **Step 3: Register routes, run `make api`.**
- [ ] **Step 4: Commit** as `feat(api): FormsHandler + PollsHandler (read-only)`.

---

## Task 17: Profile page — API tokens panel

**Files:**
- Modify: `views/profile/show.php` (add a new section)
- Create: `public/assets/js/api-tokens.js`
- Modify: `system/Controller/ProfileController.php` (add `tokens()` action + `tokensCreate` + `tokensRevoke`)
- Modify: `public/index.php` (add 3 routes under `/profile/tokens`)
- Modify: `public/assets/css/app.css` (small additions for the panel)

The web UI for token CRUD uses regular CSRF-protected form-style endpoints (these are *not* part of `/api/v1/*` — that surface is for third-party consumers). On token creation the controller flashes the plaintext into `$_SESSION['flash_token_once']`, the view renders a one-time-reveal modal on next load, and clearing the flash clears the plaintext from memory.

- [ ] **Step 1: Routes** — append to `public/index.php`:

```php
$router->get('/profile/tokens',             'Profile@tokens');
$router->post('/profile/tokens',            'Profile@tokensCreate');
$router->post('/profile/tokens/{id}/revoke','Profile@tokensRevoke');
```

- [ ] **Step 2: Controller** — add to `system/Controller/ProfileController.php`:

```php
public function tokens(Request $req, array $params): void
{
    $repo = App::make('api_tokens');     // wire singleton in step 4
    $list = $repo->listForUser((int)$this->user['id']);
    $oneTime = $_SESSION['flash_token_once'] ?? null;
    unset($_SESSION['flash_token_once']);
    $this->render('profile/tokens', ['tokens' => $list, 'oneTime' => $oneTime]);
}

public function tokensCreate(Request $req, array $params): void
{
    $name = trim((string)($req->post['name'] ?? ''));
    if ($name === '') {
        $this->flash('flash_error', t('api_tokens.error_name_required'));
        Response::redirect('/profile/tokens');
        return;
    }
    $expiresAt = null;
    if (!empty($req->post['expires_at'])) {
        $ts = strtotime((string)$req->post['expires_at']);
        if ($ts) $expiresAt = $ts;
    }
    $res = App::make('api_tokens')->create((int)$this->user['id'], $name, $expiresAt);
    $_SESSION['flash_token_once'] = ['name' => $name, 'token' => $res['token']];
    Response::redirect('/profile/tokens');
}

public function tokensRevoke(Request $req, array $params): void
{
    $id = (int)($params['id'] ?? 0);
    $repo = App::make('api_tokens');
    $row  = $repo->findById($id);
    if ($row && (int)$row['user_id'] === (int)$this->user['id']) {
        $repo->revoke($id);
        $this->flash('flash_success', t('api_tokens.revoked'));
    }
    Response::redirect('/profile/tokens');
}
```

- [ ] **Step 3: Register `api_tokens` singleton** in `public/index.php` next to the other `App::singleton(...)` calls:

```php
App::singleton('api_tokens', fn() => new \App\Repository\ApiTokenRepository(App::make('db')));
```

- [ ] **Step 4: View** — create `views/profile/tokens.php`:

```php
<?php /** @var array $tokens */ /** @var ?array $oneTime */ ?>
<section class="overview-panel">
  <h2 class="overview-panel__title"><?= e(t('api_tokens.title')) ?></h2>

  <?php if (!empty($oneTime)): ?>
    <div class="alert alert--success">
      <strong><?= e(t('api_tokens.created_once')) ?></strong>
      <p><?= e(t('api_tokens.copy_now')) ?></p>
      <pre class="copyable"><?= e($oneTime['token']) ?></pre>
    </div>
  <?php endif; ?>

  <form method="post" action="/profile/tokens" class="form-inline">
    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
    <input type="text" name="name" required placeholder="<?= e(t('api_tokens.placeholder_name')) ?>">
    <input type="date" name="expires_at">
    <button class="btn btn--primary" type="submit"><?= e(t('api_tokens.create')) ?></button>
  </form>

  <table class="data-table">
    <thead>
      <tr>
        <th><?= e(t('api_tokens.col_name')) ?></th>
        <th><?= e(t('api_tokens.col_prefix')) ?></th>
        <th><?= e(t('api_tokens.col_created')) ?></th>
        <th><?= e(t('api_tokens.col_last_used')) ?></th>
        <th><?= e(t('api_tokens.col_expires')) ?></th>
        <th><?= e(t('api_tokens.col_status')) ?></th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($tokens as $row): ?>
      <tr>
        <td><?= e($row['name']) ?></td>
        <td><code><?= e($row['prefix']) ?>…</code></td>
        <td><?= e(gmdate('Y-m-d', (int)$row['created_at'])) ?></td>
        <td><?= $row['last_used_at'] ? e(gmdate('Y-m-d H:i', (int)$row['last_used_at'])) : '—' ?></td>
        <td><?= $row['expires_at'] ? e(gmdate('Y-m-d', (int)$row['expires_at'])) : '—' ?></td>
        <td>
          <?php if ($row['revoked_at']) echo e(t('api_tokens.status_revoked'));
                elseif ($row['expires_at'] && (int)$row['expires_at'] < time()) echo e(t('api_tokens.status_expired'));
                else echo e(t('api_tokens.status_active')); ?>
        </td>
        <td>
          <?php if (!$row['revoked_at']): ?>
            <form method="post" action="/profile/tokens/<?= (int)$row['id'] ?>/revoke" data-confirm="<?= e(t('api_tokens.confirm_revoke')) ?>">
              <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
              <button class="btn btn--danger btn--sm" type="submit"><?= e(t('api_tokens.revoke')) ?></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
```

- [ ] **Step 5: JS** — create `public/assets/js/api-tokens.js`:

```js
// One-time reveal: copy-to-clipboard on the .copyable block.
document.querySelectorAll('pre.copyable').forEach((el) => {
  el.style.cursor = 'pointer';
  el.title = 'Click to copy';
  el.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(el.textContent.trim());
      el.classList.add('flash-copied');
      setTimeout(() => el.classList.remove('flash-copied'), 1200);
    } catch (e) {
      window.CRM?.toast?.('Copy failed: ' + e.message, 'error');
    }
  });
});

// Confirm-on-submit hook for revoke buttons.
document.querySelectorAll('form[data-confirm]').forEach((form) => {
  form.addEventListener('submit', (ev) => {
    if (!window.CRM?.confirm) return;
    ev.preventDefault();
    window.CRM.confirm(form.dataset.confirm).then((ok) => { if (ok) form.submit(); });
  });
});
```

Link this script in the view's `</body>`-adjacent partial or `views/layouts/app.php` conditional load (follow whatever pattern other pages use — e.g. `links/show.php` loads `links-show.js`).

- [ ] **Step 6: Playwright spec** — create `tests/e2e/api-tokens.spec.ts`:

```ts
import { test, expect } from '@playwright/test';

test.describe('API tokens', () => {
  test('user can create, see, copy, and revoke', async ({ page }) => {
    // Login via the standard E2E helper used elsewhere in the suite.
    await page.goto('/login');
    await page.fill('input[name=email]', 'admin@test');
    await page.fill('input[name=password]', 'admin123');
    await page.click('button[type=submit]');

    await page.goto('/profile/tokens');
    await page.fill('input[name=name]', 'Playwright');
    await page.click('button:has-text("Create")');
    await expect(page.locator('.copyable')).toBeVisible();
    await expect(page.locator('table tbody tr')).toContainText('Playwright');

    // Reload — the plaintext must NOT reappear.
    await page.reload();
    await expect(page.locator('.copyable')).toHaveCount(0);

    // Revoke.
    await page.click('form[data-confirm] button');
    // CRM.confirm is a custom modal — click its OK button.
    await page.click('button:has-text("OK")');
    await expect(page.locator('table tbody tr')).toContainText('Revoked');
  });
});
```

- [ ] **Step 7: Run** `make e2e` (filter to this spec while iterating: `npx playwright test api-tokens.spec.ts`). Confirm green.

- [ ] **Step 8: Commit**

```bash
git add views/profile/tokens.php public/assets/js/api-tokens.js system/Controller/ProfileController.php public/index.php tests/e2e/api-tokens.spec.ts
git commit -m "feat(api): /profile API tokens panel — create/list/revoke + one-time reveal"
```

Also add a link from the existing `views/profile/show.php` to `/profile/tokens` (single anchor under existing nav).

---

## Task 18: Admin tokens view

**Files:**
- Modify: `views/users/show.php` (or `views/admin/user-show.php` — locate the user detail page first via `grep -rln "user-edit\|users/.*\.php" views/`)
- Modify: `system/Controller/UserController.php`
- Modify: `public/index.php`

Admin can:
- See the list of a user's tokens (metadata only — never the plaintext).
- Revoke a single token.
- "Revoke all" button.

- [ ] **Step 1: Route additions**

```php
$router->post('/users/{id}/tokens/{tid}/revoke', 'User@revokeToken');
$router->post('/users/{id}/tokens/revoke-all',   'User@revokeAllTokens');
```

- [ ] **Step 2: Controller actions** mirror the profile flow but assert `RolePolicy::isAdmin($this->user)` (else 403).

- [ ] **Step 3: Append a "API tokens" section** to the user detail view listing tokens with the same columns as `/profile/tokens` (no reveal column, since plaintext never existed for the admin).

- [ ] **Step 4: Playwright spec extension** in `tests/e2e/api-tokens.spec.ts`:
  - Admin views another user's tokens page → sees the list.
  - Admin clicks "Revoke all" → all rows show Revoked.

- [ ] **Step 5: Run `make e2e`, commit** as `feat(api): admin can view + revoke user tokens`.

---

## Task 19: OpenAPI spec + schema route + drift check

**Files:**
- Create: `docs/openapi.yaml`
- Create: `tests/unit/test_openapi_drift.php`

- [ ] **Step 1: Write `docs/openapi.yaml`** as a complete OpenAPI 3.1.0 document. Sections required:
  - `info`: title, version, description pointing to `docs/API.md`.
  - `servers`: `{ url: "{base_url}/api/v1" }` with `base_url` as a server variable.
  - `components.securitySchemes.BearerToken`: `{ type: http, scheme: bearer, bearerFormat: "otk_*" }`.
  - `components.schemas`: `Error`, `Project`, `Task`, `Column`, `Comment`, `Tag`, `Attachment`, `Form`, `Submission`, `Poll`, `Me`, `PaginatedProjects`, `PaginatedTasks`, etc.
  - `paths`: every route listed in `ApiKernel::register()`. For each: method, summary, parameters (path + query + body where applicable), request body schema, response codes (200/201/204/400/401/403/404/422/429) referencing `Error` or the resource schema.
  - `security`: `[{ BearerToken: [] }]` at the root, with `security: []` overrides on the openapi.yaml route itself.

There is no shortcut here — this is hand-written. Use the existing endpoint inventory in the spec doc (§5) as the source of truth.

- [ ] **Step 2: Schema route already wired** in Task 7's `ApiKernel::serveOpenApi()`. Verify by running `make dev` and `curl http://localhost:8000/api/v1/openapi.yaml`.

- [ ] **Step 3: Drift-check test** — create `tests/unit/test_openapi_drift.php`:

```php
<?php
it('every route in ApiKernel::register() appears in docs/openapi.yaml', function () {
    $kernelSrc = file_get_contents(dirname(__DIR__, 2) . '/system/Api/V1/ApiKernel.php');
    preg_match_all('#\$this->routes\[\'(\w+) (/api/v1/[^\']+)\'\]#', $kernelSrc, $m);
    $kernelRoutes = array_map(fn($i) => strtolower($m[1][$i]) . ' ' . $m[2][$i], array_keys($m[1]));

    $yaml = file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.yaml');
    foreach ($kernelRoutes as $route) {
        [$method, $path] = explode(' ', $route, 2);
        // Strip /api/v1 prefix (the spec uses server-relative paths) and {id}-normalised vs OpenAPI {param} variants.
        $specPath = preg_replace('#^/api/v1#', '', $path);
        $needle = $specPath;
        assert_true(str_contains($yaml, $needle), "openapi.yaml missing path $specPath");
        // (Light check — full method-level validation is left to runtime tooling.)
    }
});

it('every path: in openapi.yaml is also in ApiKernel', function () {
    $yaml = file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.yaml');
    preg_match_all('#^  (/[^:\n]+):#m', $yaml, $m);
    $specPaths = $m[1];
    $kernelSrc = file_get_contents(dirname(__DIR__, 2) . '/system/Api/V1/ApiKernel.php');
    foreach ($specPaths as $p) {
        // Translate {id} → /\d+ for substring check.
        $needle = preg_replace('#\{[^}]+\}#', '{id}', $p);
        assert_true(str_contains($kernelSrc, $needle), "ApiKernel missing route $p");
    }
});
```

- [ ] **Step 4: Run `make unit`.** Expected: PASS.

- [ ] **Step 5: Commit** as `docs(api): hand-written openapi.yaml + drift check`.

---

## Task 20: Integration guide + endpoint reference (`docs/API.md`)

**Files:**
- Create: `docs/API.md` (full integration guide + endpoint reference)
- Create: `docs/INTEGRATION-CHECKLIST.md` (one-page setup checklist for integrators)
- Modify: `README.md`

**Audience:** an engineer at a third-party service who has never seen this codebase and wants to wire it into their system (CI runner, Zapier, MCP bridge, internal automation). Document everything they need without making them grep the source.

### 20.1 — `docs/API.md` structure

The doc MUST have exactly the sections below, in this order. Each section is described concretely; no "TBD".

- [ ] **Step 1: Section 1 — Overview**

Write 3-4 paragraphs:
- What the API is (versioned REST/JSON, mirrors web UI capabilities for manager+employee roles).
- What it is NOT (no user management, no settings, no public-form submission — those have separate channels).
- Versioning policy: `/api/v1/` is stable; breaking changes ship under a new prefix.
- Link to `docs/openapi.yaml` as the machine-readable contract.
- Stack note: PHP backend, SQLite/MySQL persistence — but consumers only see HTTP+JSON.

- [ ] **Step 2: Section 2 — Integration setup (the integrator's checklist)**

A numbered walkthrough of what a third-party service needs to do to integrate. Each step has the exact action + verification:

1. **Decide who the integration runs as.** Recommended: ask the Otack admin to create a dedicated `employee`-role user (e.g. `ci-bot@yourcompany.com`) and add it as a member to the projects the integration touches. This is **safer than using an admin's personal account** because token compromise is scoped to that user's project membership. Document why (no scopes per token in v1 — see Trade-offs).
2. **Get an API token.** Log into Otack as the service-account user → `/profile` → "API tokens" → "Create token". Name it after the integration ("CI Runner — GitHub Actions"). Copy the displayed `otk_…` value immediately — it is shown only once.
3. **Store the token securely** in your environment (`OTACK_API_TOKEN` env var, or a secrets manager). Never commit it to git, never log it. Token compromise → revoke at `/profile` and create a new one.
4. **Configure the base URL.** Production: `https://your-otack-host.example.com/api/v1`. Local dev: `http://localhost:8000/api/v1`. Document both as `OTACK_API_URL`.
5. **Smoke-test the auth.** Run:
   ```bash
   curl -sS -H "Authorization: Bearer $OTACK_API_TOKEN" "$OTACK_API_URL/me"
   ```
   Expected: 200 with `{ "id": ..., "email": ..., "role": ... }`. If 401 → token wrong / revoked / expired. If 404 → wrong base URL.
6. **Plan for rate limits.** Default is 60 requests/minute/token. Bursty clients must back off on 429 (read the `Retry-After` header). For sustained high-throughput integrations, request a larger limit from the admin (configured per-deployment).
7. **Plan for errors.** Every error response is `{ "error": "<code>", "message": "...", "fields": {...}? }`. Map the `error` code (machine-stable) to your logic, not the message (English, may change).
8. **Decide on idempotency.** The API does not yet support idempotency keys. Use natural keys client-side: before creating a resource, fetch the listing and check for a duplicate, OR accept duplicates and de-dupe later. `POST /projects/{id}/pin` is intentionally idempotent (explicit state) — others are not.
9. **Set up local development against a test instance.** Do not test integrations against production data. Use `make dev` to spin a local Otack on `:8000`; create a token there.

- [ ] **Step 3: Section 3 — Authentication**

- Bearer token format: `Authorization: Bearer otk_<random>`. No other auth methods.
- What 401 means (missing/malformed/unknown/revoked/expired token, or user blocked). 401 is uniform — no distinction between cases, to avoid leaking which tokens exist.
- Token lifecycle: created, optionally `expires_at`, revoked (terminal). Show `last_used_at`/`last_used_ip` are visible in `/profile`.
- **Worked example** (copy-paste-ready):
  ```bash
  curl -sS -H "Authorization: Bearer $OTACK_API_TOKEN" \
       -H "Accept: application/json" \
       "$OTACK_API_URL/me"
  ```
  Expected response body (JSON, formatted):
  ```json
  { "id": 7, "name": "CI Bot", "email": "ci-bot@example.com",
    "role": "employee", "locale": "en" }
  ```

- [ ] **Step 4: Section 4 — Request / response conventions**

Document, with one runnable curl example each:
- **Content-Type:** `application/json` on every write (POST/PATCH). Multipart only for `POST /attachments`.
- **HTTP methods:** GET read, POST create-or-action, PATCH partial update, DELETE destroy.
- **Status codes table:**

  | Code | Meaning | Body |
  |---|---|---|
  | 200 | Success with body | Resource or list |
  | 201 | Created | Created resource |
  | 204 | Success, no body | empty |
  | 400 | Malformed JSON / bad request shape | error envelope |
  | 401 | Auth failed | error envelope |
  | 403 | Authenticated but RolePolicy denied | error envelope |
  | 404 | Not found or not visible to caller | error envelope |
  | 409 | Conflict (e.g. duplicate slug) | error envelope |
  | 422 | Validation failed | error envelope with `fields` |
  | 429 | Rate limited | error envelope, `Retry-After` header |
  | 5xx | Server error (logged) | error envelope |

- **Error envelope:** show the exact shape. Document each `error` code: `unauthorized`, `forbidden`, `not_found`, `validation_failed`, `malformed_json`, `rate_limited`, `conflict`, `server_error`.
- **Timestamps:** ISO-8601 UTC, e.g. `2026-06-03T12:34:56Z`. Inputs accept the same.
- **Pagination:** cursor-based on `id`. Worked example:
  ```bash
  curl -sS -H "Authorization: Bearer $OTACK_API_TOKEN" \
       "$OTACK_API_URL/projects/12/tasks?limit=50&after=1234"
  ```
  Response:
  ```json
  { "items": [...], "next_cursor": 1290 }
  ```
  Pseudocode for "fetch all":
  ```python
  cursor = 0
  while True:
      r = get(f"/projects/{pid}/tasks?after={cursor}&limit=100")
      yield from r["items"]
      if r["next_cursor"] is None: break
      cursor = r["next_cursor"]
  ```

- [ ] **Step 5: Section 5 — Endpoint reference**

This is the centrepiece. For **every endpoint** in `ApiKernel`, include:
- Method + path
- One-line description
- Required role (admin / manager / employee / member of project)
- Path params, query params, request body schema (concrete JSON example, not a generic schema)
- Response body schema (concrete JSON example)
- Possible error codes specific to this endpoint
- One copy-paste curl example

Organise by resource, in this order: **Me, Projects, Columns, Tasks, Comments, Tags, Attachments, Forms, Polls**. Within each resource, list endpoints in CRUD order (list, show, create, update, action, delete).

Template for each endpoint (use this exact format):

````markdown
### `POST /projects/{id}/tasks`

Create a task in a project.

**Auth:** member of the project, or manager/admin.

**Path parameters:**
- `id` (integer) — project id.

**Request body:**
```json
{
  "title": "Write docs",
  "description": "Markdown body, optional",
  "column_id": 5,
  "assignee_id": 12,
  "priority": "high",
  "tag_ids": [3, 7]
}
```
Only `title` is required. Defaults: `column_id` = project's first column, `priority` = `normal`.

**Response 201:**
```json
{
  "id": 482,
  "project_id": 12,
  "title": "Write docs",
  "description": "Markdown body, optional",
  "column_id": 5,
  "position": 7,
  "assignee_id": 12,
  "priority": "high",
  "tags": [{ "id": 3, "name": "docs" }, { "id": 7, "name": "p1" }],
  "created_at": "2026-06-03T12:34:56Z",
  "updated_at": "2026-06-03T12:34:56Z"
}
```

**Errors:**
- 403 `forbidden` — caller is not a member of the project.
- 404 `not_found` — project does not exist or is invisible.
- 422 `validation_failed` — `title` empty, `column_id` not in project, `assignee_id` not a member.

**Example:**
```bash
curl -sS -X POST \
     -H "Authorization: Bearer $OTACK_API_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"title":"Write docs","column_id":5}' \
     "$OTACK_API_URL/projects/12/tasks"
```
````

Write one such block for **every** endpoint registered in `ApiKernel::register()`. Do not abbreviate — this is the user-facing contract. Use the same data shapes the handlers actually return (run `make api` and copy real responses into the doc if needed).

- [ ] **Step 6: Section 6 — End-to-end recipes**

Three concrete walkthroughs. Each shows the full sequence of requests and responses an integrator would issue.

1. **"Sync tasks from our issue tracker into an Otack project"**
   - Discover projects: `GET /projects`.
   - Find a target column: `GET /projects/{id}` → pick a column from the response.
   - For each issue: `POST /projects/{id}/tasks` with title + description.
   - Tag them: `POST /tasks/{id}/tags`.
   - Show the full curl chain.

2. **"Notify our chat when a task moves to Done"**
   - Poll `GET /projects/{id}/tasks?column_id={done_id}&after={cursor}` every minute.
   - Persist `next_cursor` between polls.
   - On new items, send to your chat webhook.
   - Discuss the rate-limit math: ~1 req/min/project well under 60/min.

3. **"Upload a file attachment to an existing task"**
   - Multipart curl example using `-F`.
   - Show the response with the stored file URL.
   - Note size + MIME limits enforced by the server (link to `FileUploader` configurable env vars).

- [ ] **Step 7: Section 7 — Rate limiting**

- 60 requests/minute/token, sliding window.
- 429 includes `Retry-After: <seconds>` header.
- Counter is per token, not per user — multiple integrations on one user share isolated counters because each holds a different token.
- Pattern for client-side backoff (pseudocode):
  ```python
  resp = http.get(url, headers={"Authorization": f"Bearer {tok}"})
  if resp.status == 429:
      time.sleep(int(resp.headers.get("Retry-After", "5")))
      resp = http.get(url, ...)  # retry once
  ```
- "Burst budget" intuition: 60 req in the first second of the window is allowed; the 61st gets 429 until the window rolls.

- [ ] **Step 8: Section 8 — Security & best practices**

- **Service accounts > personal accounts.** Why: a personal admin's token compromise = full admin breach. A scoped employee service-account token compromise = limited to that user's project membership.
- **No scopes in v1** — token inherits owner role. Mitigation: limit the service-account user's project membership.
- **Rotate tokens** on integration ownership changes (employee leaves → revoke all tokens they created).
- **HTTPS only** in production. Bearer tokens MUST NOT travel over plain HTTP.
- **Don't log tokens.** If you must log the request, redact `Authorization` to `Bearer otk_…[REDACTED]`.
- **Audit trail.** Every API call is recorded in `activity_log` with the calling user and token id; admins can investigate after-the-fact.

- [ ] **Step 9: Section 9 — Versioning & deprecation**

- `/api/v1/` is the current stable surface. Breaking changes will ship as `/api/v2/` alongside; v1 continues to work until a deprecation window (announced in CHANGELOG) closes.
- Additive changes (new optional fields, new endpoints) ship within v1 without notice.
- Removed fields: never. Replaced fields: dual-emit during the transition.

- [ ] **Step 10: Section 10 — Troubleshooting**

A table of common symptoms → cause → fix:

| Symptom | Likely cause | Fix |
|---|---|---|
| 401 on first call | Token wrong/revoked/expired | Re-issue via `/profile/tokens` |
| 401 only after some time | Token expired | Check `expires_at` in `/profile` |
| 403 on routes that worked yesterday | User role demoted | Ask admin to restore role |
| 404 on a project you created | You're not a member of it (admin removed you) | Re-add user to project |
| 422 with `{"fields":{"X":"required"}}` | Missing required field | Add field to request body |
| 429 once a minute, regularly | Polling cadence > 60/min | Increase poll interval or batch |
| Connection refused | Wrong base URL or instance down | `curl -I "$OTACK_API_URL"` to verify |
| TLS errors | Self-signed cert in dev | Use `curl -k` only in dev, never prod |

- [ ] **Step 11: Section 11 — Reference**

- Link to `docs/openapi.yaml` and how to load it into Postman / Insomnia / Swagger UI.
- Link to the spec doc `docs/superpowers/specs/2026-06-03-external-api-design.md`.
- Link to `docs/INTEGRATION-CHECKLIST.md` (the next file we write).
- (Phase 2 placeholder) MCP bridge link — `mcp/README.md` — exists only once the MCP work is done; mark as "coming in phase 2".

### 20.2 — `docs/INTEGRATION-CHECKLIST.md`

- [ ] **Step 12: One-page checklist for integrators**

This is a printable single-page summary an integrator can tick through:

```markdown
# Otack API Integration Checklist

## Before you start
- [ ] Confirmed your instance URL: `https://_______/api/v1`
- [ ] Confirmed who the integration will run as (recommended: a dedicated `employee` service-account user, NOT a personal admin)
- [ ] Service-account user has been added as a member of the projects the integration touches

## Get the token
- [ ] Logged in as the service-account user
- [ ] Created token at `/profile/tokens` with a descriptive label
- [ ] Copied the `otk_…` value at the reveal screen (it cannot be retrieved later)
- [ ] Stored token in environment / secrets manager (NOT in git)

## Wire the client
- [ ] All requests send `Authorization: Bearer otk_…`
- [ ] All write requests send `Content-Type: application/json`
- [ ] HTTPS in production (mandatory)
- [ ] Smoke test passed: `GET /me` returns 200

## Hardening
- [ ] Client retries on 429 with the `Retry-After` value
- [ ] Client maps `error` codes (not `message`) to logic
- [ ] Logs redact the `Authorization` header
- [ ] You have a runbook for token compromise (revoke + reissue)

## Operations
- [ ] You poll cursors, not full lists
- [ ] You have a metric/alert for repeated 4xx/5xx from the API
- [ ] You have documented the integration owner (who rotates the token)
```

### 20.3 — README and final steps

- [ ] **Step 13: README update** — add to the existing "Documentation" section:

```markdown
- [docs/API.md](docs/API.md) — third-party REST API guide: integration setup, auth, endpoint reference, recipes
- [docs/INTEGRATION-CHECKLIST.md](docs/INTEGRATION-CHECKLIST.md) — one-page checklist for integrators
- [docs/openapi.yaml](docs/openapi.yaml) — OpenAPI 3.1.0 machine-readable contract
```

- [ ] **Step 14: Cross-check the endpoint reference against `ApiKernel`**

Run:

```bash
grep -oE "routes\['(GET|POST|PATCH|DELETE) /api/v1/[^']+'" system/Api/V1/ApiKernel.php \
  | sed -E "s#routes\['##; s#'##" | sort -u
```

For each line, grep `docs/API.md` for an `### ` heading containing that method+path. Any missing route → add a Section 5 block following the template. Any block in `docs/API.md` for a route not in the kernel → delete it.

- [ ] **Step 15: Verify all curl examples actually work**

Spin up `make dev`, create a token, and run **at least one curl example per resource** from the doc against the live instance. Update the doc if any example returned a different shape than documented.

- [ ] **Step 16: Commit**

```bash
git add docs/API.md docs/INTEGRATION-CHECKLIST.md README.md
git commit -m "docs(api): integration guide + full endpoint reference + integrator checklist"
```

---

## Task 21: i18n parity

**Files:**
- Modify: `system/i18n/en.php`
- Modify: `system/i18n/pl.php`
- Modify: `system/i18n/uk.php`

- [ ] **Step 1: Add the full `api_tokens.*` key set to all three files.** Required keys (use English as the source; PL and UK translators may iterate later — placeholder translations OK as long as **no key is missing**):

```
api_tokens.title
api_tokens.create
api_tokens.placeholder_name
api_tokens.col_name
api_tokens.col_prefix
api_tokens.col_created
api_tokens.col_last_used
api_tokens.col_expires
api_tokens.col_status
api_tokens.status_active
api_tokens.status_revoked
api_tokens.status_expired
api_tokens.revoke
api_tokens.revoke_all
api_tokens.confirm_revoke
api_tokens.confirm_revoke_all
api_tokens.created_once
api_tokens.copy_now
api_tokens.revoked
api_tokens.error_name_required
api_tokens.admin_section_title
api_tokens.admin_no_value_note
```

- [ ] **Step 2: Parity test** — extend an existing i18n test or add to `tests/unit/test_i18n.php`:

```php
it('i18n catalogues have key parity', function () {
    $en = include dirname(__DIR__, 2) . '/system/i18n/en.php';
    $pl = include dirname(__DIR__, 2) . '/system/i18n/pl.php';
    $uk = include dirname(__DIR__, 2) . '/system/i18n/uk.php';
    $missingPl = array_diff_key($en, $pl);
    $missingUk = array_diff_key($en, $uk);
    assert_true(empty($missingPl), 'pl missing: ' . implode(',', array_keys($missingPl)));
    assert_true(empty($missingUk), 'uk missing: ' . implode(',', array_keys($missingUk)));
});
```

(If this test already exists, just run it to confirm green after the additions.)

- [ ] **Step 3: Run `make unit`.** Expected: PASS, and the pre-existing `forms_data.brand_tag` gap from the 2026-06-02 audit is **not** re-introduced.

- [ ] **Step 4: Commit** as `i18n(api): add api_tokens keys to en/pl/uk`.

---

## Task 22: Final pass

- [ ] **Step 1: Run the full suite**

```bash
make unit && make api && make e2e
```

All green.

- [ ] **Step 2: Smoke the API by hand** with a real token created via `/profile/tokens`:

```bash
TOKEN="otk_…"
curl -sS -H "Authorization: Bearer $TOKEN" http://localhost:8000/api/v1/me | jq
curl -sS -H "Authorization: Bearer $TOKEN" http://localhost:8000/api/v1/projects | jq '.items | length'
curl -sS -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
     -d '{"name":"Smoke"}' http://localhost:8000/api/v1/projects | jq
```

- [ ] **Step 3: Inspect `activity_log`** to confirm `api.*` rows are landing with the expected `meta` payload:

```bash
sqlite3 data/app.sqlite "SELECT action, meta FROM activity_log WHERE action LIKE 'api.%' ORDER BY id DESC LIMIT 10"
```

- [ ] **Step 4: Inspect `api_tokens.last_used_at`** is updating:

```bash
sqlite3 data/app.sqlite "SELECT id, name, last_used_at, last_used_ip FROM api_tokens"
```

- [ ] **Step 5: Re-read `data/errors.log`** for any 5xx that slipped past tests; investigate and fix or open follow-up items in TODO.md.

- [ ] **Step 6: Commit any small fixes** uncovered by the smoke, then update `TODO.md`:

```
# replace the existing TODO #2 line
done - #2 - Внешнее API с per-user токенами и OpenAPI спекой ... done (см. docs/API.md, docs/openapi.yaml, /profile/tokens; MCP-bridge — phase 2, отдельный спек)
```

- [ ] **Step 7: Final commit**

```bash
git add TODO.md
git commit -m "chore: mark TODO #2 (external API) done"
```

---

## Self-review (done by plan author, not at execution time)

**Spec coverage:**
- §2 token model → Tasks 1, 3.
- §3 rate limiting → Tasks 2, 4.
- §4 transport/contract → Tasks 5, 7.
- §5 endpoint inventory → Tasks 8 (Me), 9-10 (Projects), 11 (Columns), 12 (Tasks), 13 (Comments), 14 (Tags), 15 (Attachments), 16 (Forms+Polls).
- §6 architecture (parallel namespace, no controller tunneling) → Task 7.
- §6 RolePolicy consolidation → Task 13 step 1-3.
- §7 data model migrations → Tasks 1-2.
- §9 UI surface → Tasks 17-18.
- §10 audit + last_used_at → Task 7 (kernel logs + touchUsage) + Task 22 step 3-4 verification.
- §12 testing coverage (unit + integration + e2e + drift) → Tasks 3-16 unit/integration + 17 e2e + 19 drift.
- §13 OpenAPI + API.md + README → Tasks 19-20. Task 20 expanded into a full integration guide (setup checklist, auth, conventions, per-endpoint reference with examples, recipes, rate limits, security best practices, versioning, troubleshooting) + a one-page `INTEGRATION-CHECKLIST.md`.
- §14 build order matches the task order.

**Placeholder scan:**
- Task 11 says "follow the same shape as Task 9-10" — that's a structural reference, not a code placeholder; each endpoint still has its acceptance criteria spelled out in step 1 of that task.
- Task 12 lists the test scenarios concretely (filter / pagination / move) rather than "write tests for the above".
- Tasks 14, 16 list every endpoint by route + body + auth rule — no "etc." gaps.

**Type / name consistency:**
- `ApiTokenRepository::create()` returns `['id' => int, 'token' => string]` in Task 3 and that exact contract is consumed in Tasks 7 (kernel test), 8 (me test), 17 (profile controller).
- `RateLimiter::check()` returns `['allowed' => bool, 'count' => int, 'retry_after' => int]` in Task 4 and is consumed identically in Task 7 (`$rl['allowed']`, `$rl['retry_after']`).
- `TokenAuthenticator::authenticate()` returns `['user' => array, 'token' => array] | null` in Task 6 and is used as `$ctx['user']` / `$ctx['token']` in Task 7 (kernel) and via `$this->ctx` in BaseHandler (Task 8).
- `BaseHandler::pathId(int $segmentIndex)` introduced in Task 8 and used in Tasks 9-15 — `/api/v1/projects/{id}` → segment 2; `/api/v1/projects/{id}/members/{user_id}` → segments 2 and 4. Consistent.
- `services` array keys (`'projects'`, `'members'`, `'columns'`, …) match the singleton names registered in `public/index.php` (Task 7) and the `App::singleton(...)` calls already present in the pre-existing bootstrap.
