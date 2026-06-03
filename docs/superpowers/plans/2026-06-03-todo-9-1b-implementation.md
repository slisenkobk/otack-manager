# TODO #9.1b Implementation Plan — Architecture tidy-up (post-ship)

> **For agentic workers:** Use superpowers:subagent-driven-development. Wait until **v1.2.0 is shipped (Wave 9.1a complete)** before starting this wave. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Pay down the structural debt that the 2026-06-03 audit identified as "should-fix" — 38 items spanning backend architecture, defence-in-depth security, frontend module split, view/UX polish, test fill-in, asset budget, and docs/ops finish-out. End state: codebase is comfortable to onboard new contributors into; performance budget is back under control; tests cover the surfaces a future audit will check.

**Architecture:** Mostly mechanical refactors plus a few targeted abstractions:
- New `system/Http/Validator.php`, `system/Service/Log.php`, `system/Bootstrap/{Container,Routes,Events}.php`, `system/Events/TelegramListeners.php`
- Lift `ProjectsHandler::canSeeProject` and similar helpers into `BaseHandler` (already done in #9.1a follow-through? Verify before duplicating.)
- JS modules split: `ui.js` → `ui-modal.js`/`ui-fetch.js`/`ui-bootstrap.js`; `kanban.js` → `kanban-board.js`/`kanban-toolbar.js`/`kanban-columns.js`; extract `ui-fields.js`
- CSS layered: `tokens.css` / `base.css` / `layout.css` / `forms.css` / `kanban.css` / `cards-panels.css` / `modal-toast.css` / `utilities.css`

**Tech Stack:** PHP 8.2+ / SQLite + MySQL / vanilla JS ES modules / Playwright. Composer-free.

**Spec:** [docs/superpowers/specs/2026-06-03-todo-9-audit-and-cleanup-plan.md](../specs/2026-06-03-todo-9-audit-and-cleanup-plan.md)

**Branch:** `refactor/9-1b-architecture` (single feature branch; merge to main + tag v1.3.0 at the end).

**Prerequisites:** Wave 9.1a complete on `main`; tag `v1.2.0` exists; 230+ unit / 86+ api / 122+ e2e tests passing.

---

## Conventions

- Same as 9.1a: TDD where it pays off, one logical change per commit, no new dependencies, i18n keys in all three locales.
- **Refactor discipline:** when splitting a file, ensure the split files cover the union of the original file's responsibilities, not a subset. Run the relevant e2e specs after each split.
- **Performance assertion** for the asset-budget tasks (AS-1/AS-2/AS-3): measure with `curl -sS -w "%{size_download}\n" -o /dev/null URL` before + after. The expected delta is documented.
- Tasks within a logical group are mostly independent — can be parallelised by the controller.

---

## Group 1 — Backend architectural split (Tasks 1-3)

### Task 1 — Split `public/index.php` into `Bootstrap/{Container,Events,Routes}.php` (A-1, A-2)

**Files:**
- Create: `system/Bootstrap/Container.php`
- Create: `system/Bootstrap/Events.php` (the 84-LOC Telegram listener block)
- Create: `system/Bootstrap/Routes.php` (all `$router->...` calls)
- Create: `system/Events/TelegramListeners.php` (the actual listener logic, called by `Bootstrap/Events.php`)
- Modify: `public/index.php` (reduce to ~50 LOC glue)

- [ ] **Step 1: Inspect current index.php structure**

```bash
wc -l public/index.php
grep -nE "^(// ─|App::singleton|\\\$router->|\\\$events->on)" public/index.php
```

You should see ~30 singleton registrations (lines 49-193), ~16 event listeners (90-173), ~100 route declarations (203-339).

- [ ] **Step 2: Create `system/Bootstrap/Container.php`**

```php
<?php
declare(strict_types=1);
namespace App\Bootstrap;

use App\App;

/**
 * DI singleton registration. Keep registrations top-down — no
 * cross-references via App::make() inside a factory above where the
 * dependency is registered.
 */
final class Container
{
    public static function register(array &$sessionStore): void
    {
        App::singleton('db',     fn() => /* ... move from index.php ... */);
        // ... move every App::singleton(...) call here
        // The session and csrf singletons need $sessionStore captured by ref
        App::singleton('session', function () use (&$sessionStore) { /* ... */ });
        App::singleton('csrf',    function () use (&$sessionStore) { /* ... */ });
        // etc.
    }
}
```

Important: the factory closures that captured `$store` by reference (`auth`, `csrf`, `session`, `session_manager`) need to receive `&$sessionStore` as a parameter and re-capture by-ref.

- [ ] **Step 3: Create `system/Events/TelegramListeners.php`**

Consolidate the 16 inline event handlers from `public/index.php:97-170` into a single class with one helper to collapse boilerplate:

```php
<?php
declare(strict_types=1);
namespace App\Events;

use App\Service\EventBus;
use App\Service\NotificationLogger;
use App\Service\TelegramNotifier;

final class TelegramListeners
{
    private NotificationLogger $tg;

    public function __construct(NotificationLogger $tg)
    {
        $this->tg = $tg;
    }

    public function register(EventBus $events): void
    {
        $b = fn(string $s) => '<b>' . TelegramNotifier::escape($s) . '</b>';
        $e = fn(string $s) => TelegramNotifier::escape($s);

        $events->on('user.registered', fn($p) =>
            $this->tg->notify('user.registered',
                "[NEW] Registration request: " . $b($p['name']) . " &lt;" . $e($p['email']) . "&gt;",
                null, $p));

        $events->on('user.approved', fn($p) =>
            $this->tg->notify('user.approved',
                "[USER] " . $b($p['name']) . " approved by " . $b($p['actor_name']),
                null, $p));

        // ... 14 more events, same shape
    }
}
```

- [ ] **Step 4: Create `system/Bootstrap/Events.php`**

Thin wrapper that wires `TelegramListeners`:

```php
<?php
declare(strict_types=1);
namespace App\Bootstrap;

use App\App;
use App\Events\TelegramListeners;
use App\Service\NotificationLogger;
use App\Service\TelegramNotifier;

final class Events
{
    public static function register(): void
    {
        $tg = new NotificationLogger(
            new TelegramNotifier(App::env('TG_BOT_TOKEN'), App::env('TG_CHAT_ID')),
            App::make('notif_log')
        );
        (new TelegramListeners($tg))->register(App::make('events'));
    }
}
```

- [ ] **Step 5: Create `system/Bootstrap/Routes.php`**

```php
<?php
declare(strict_types=1);
namespace App\Bootstrap;

use App\App;
use App\Routing\Router;

final class Routes
{
    public static function build(): Router
    {
        $router = new Router();

        // Dashboard
        $router->get('/', 'Dashboard@index');
        $router->get('/api/activity', 'Dashboard@moreActivity');

        // Auth
        $router->get('/login',    'Auth@loginForm');
        $router->post('/login',   'Auth@login');
        // ... 80+ more

        if (App::env('APP_DEBUG') === 'true') {
            $router->get('/ui-sandbox', 'Smoke@uiSandbox');
        }

        return $router;
    }
}
```

- [ ] **Step 6: Rewrite `public/index.php`**

Target ~50 LOC:

```php
<?php
declare(strict_types=1);

// PHP built-in server static fall-through
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $file = __DIR__ . $path;
    if ($path !== '/' && is_file($file)) return false;
}

require dirname(__DIR__) . '/system/bootstrap.php';

use App\App;
use App\Http\{Request, Response};
use App\Bootstrap\{Container, Events, Routes};
use App\Auth\SessionManager;
use App\Database\{Migrations, SchemaBootstrap};

// CSP + security headers
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; font-src 'self'; connect-src 'self'; frame-ancestors 'none'");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");

ini_set('error_log', APP_ROOT . '/data/errors.log');
set_exception_handler(/* same as before */);

App::reset();

$session = new SessionManager();
$session->start((int)App::env('SESSION_LIFETIME', '43200'));
$store = &$session->storage();

Container::register($store);
SchemaBootstrap::$legacyMarkerDir = APP_ROOT . '/data/.schema';
Migrations::run(App::make('schema'));

Events::register();

// Remember-me slide
if (!empty($store['user_id'])) {
    $session->extendCookie(
        !empty($store['__remember']) ? SessionManager::REMEMBER_LIFETIME : SessionManager::DEFAULT_LIFETIME
    );
}

$router = Routes::build();
$req    = Request::fromGlobals();

// API hand-off (unchanged)
if (str_starts_with($req->path, '/api/v1/')) {
    /* ... ApiKernel block as before ... */
}

// Landing + login-hash gate, CSRF, AuthGuard, dispatch (unchanged)
/* ... */
```

- [ ] **Step 7: Run everything**

```bash
make unit 2>&1 | tail -3   # unchanged
make api 2>&1 | tail -3    # unchanged
make e2e 2>&1 | tail -5    # unchanged
```

If anything fails, the issue is in how factories closed over `$store`. Inspect carefully.

- [ ] **Step 8: Commit**

```bash
git add system/Bootstrap/ system/Events/ public/index.php
git commit -m "refactor: split public/index.php into Bootstrap/{Container,Events,Routes} (A-1, A-2)"
```

---

### Task 2 — DI container re-entry guard (A-4)

**Files:**
- Modify: `system/App.php`

- [ ] **Step 1: Add re-entry detection to `App::make()`**

```php
private static array $resolving = [];

public static function make(string $key): mixed
{
    if (isset(self::$resolving[$key])) {
        throw new \LogicException("DI circular dependency: " . implode(' -> ', array_keys(self::$resolving)) . " -> $key");
    }
    self::$resolving[$key] = true;
    try {
        // ... existing resolution logic
    } finally {
        unset(self::$resolving[$key]);
    }
}
```

- [ ] **Step 2: Add a unit test**

```php
it('App::make detects circular dependencies', function () {
    App::singleton('a', fn() => App::make('b'));
    App::singleton('b', fn() => App::make('a'));
    $threw = false;
    try { App::make('a'); }
    catch (\LogicException $_) { $threw = true; }
    assert_true($threw);
});
```

- [ ] **Step 3: Run, expect PASS**

- [ ] **Step 4: Commit**

```bash
git add system/App.php tests/unit/test_app.php
git commit -m "feat(di): re-entry guard in App::make (A-4)"
```

---

### Task 3 — `ApiKernel` route pre-compilation (A-7)

**Files:**
- Modify: `system/Api/V1/ApiKernel.php`
- Modify: `tests/api/test_*.php` (none — internal change, transparent)

- [ ] **Step 1: Replace `normalisePath` regex with per-route regex match**

Instead of:
```php
$key = $req->method . ' ' . $this->normalisePath($req->path);
$match = $this->routes[$key] ?? null;
```

Use:
```php
foreach ($this->routes as $pattern => $action) {
    if ($this->matches($pattern, $req->method, $req->path, $params)) {
        $match = $action; break;
    }
}
```

Where `matches()` compiles `'POST /api/v1/projects/{id}/members/{user_id}'` into a regex `#^POST /api/v1/projects/(\d+)/members/(\d+)$#` and extracts named params.

Thread `$params` through to handlers — replaces the brittle `pathId($req, 3)` calls (covered by Task 4 / C-8).

- [ ] **Step 2: Update handlers to receive `$params` array**

Modify `BaseHandler` to expose `$this->params['id']` instead of `$this->pathId(...)`. Migrate handlers one resource at a time:
- Projects (id, user_id)
- Tasks (id, other_id)
- Columns (id)
- Comments (id)
- Tags (id, tag_id)
- Attachments (id)
- Forms (id)
- Polls (id)

- [ ] **Step 3: Smoke**

```bash
make api 2>&1 | tail -3   # 84+ passing (or higher, if you added new tests)
```

- [ ] **Step 4: Commit**

```bash
git add system/Api/V1/
git commit -m "refactor(api): pre-compiled per-route regex + named params (A-7, C-8)"
```

---

## Group 2 — Backend code-quality sweeps (Tasks 4-9)

### Task 4 — Constructor-injection sweep across controllers (C-1)

**Files:** all of `system/Controller/*.php` (23 controllers, 222 `App::make()` calls inside method bodies).

- [ ] **Step 1: Inventory**

```bash
grep -c "App::make(" system/Controller/*.php | sort -t: -k2 -n
```

Lowest-count files first (those are quickest wins).

- [ ] **Step 2: For each controller**

Lift all `App::make()` calls from method bodies into the constructor as typed properties. Example for `ProjectController`:

Before:
```php
public function index(...) {
    $projects = App::make('projects');
    $members  = App::make('members');
    // ...
}
```

After:
```php
public function __construct(
    Renderer $view,
    ?array $user,
    private \App\Repository\ProjectRepository $projects,
    private \App\Repository\ProjectMemberRepository $members,
    // ... etc
) {
    parent::__construct($view, $user);
}
```

Controllers are instantiated at `public/index.php:438` (or wherever `new $class(...)` happens). That call needs to receive the new deps. Easiest: change to a small factory that pulls from `App::make()` once per request — `Controllers/Factory.php` or inline in the dispatcher.

- [ ] **Step 3: Add a convention test**

```php
// tests/unit/test_controller_conventions.php
it('no App::make() outside __construct in controllers', function () {
    $files = glob(dirname(__DIR__, 2) . '/system/Controller/*.php');
    $offenders = [];
    foreach ($files as $f) {
        $src = file_get_contents($f);
        // Strip __construct body
        $stripped = preg_replace('/public function __construct\s*\([^{]+\{[^}]*\}/s', '', $src);
        if (str_contains($stripped, 'App::make(')) $offenders[] = basename($f);
    }
    assert_true(empty($offenders), 'App::make() in body: ' . implode(', ', $offenders));
});
```

- [ ] **Step 4: Run**

```bash
make unit 2>&1 | tail -3
make api 2>&1 | tail -3
make e2e 2>&1 | tail -5
```

- [ ] **Step 5: Commit per controller (or in logical batches)**

```bash
git commit -m "refactor(controllers): constructor-inject deps (C-1, batch 1: Auth/User/Profile)"
# etc.
```

---

### Task 5 — `Request::jsonBody()` + migrate 17 call sites (C-2 + S-7)

**Files:**
- Modify: `system/Http/Request.php`
- Modify: 17 call sites in `LinkController`, `ColumnController`, `ProjectController`, `TaskController`, `UserController`, `Form*`, `Poll*`

- [ ] **Step 1: Add the helper**

```php
// In system/Http/Request.php
public function jsonBody(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') return [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new \InvalidArgumentException('invalid_json');
    }
    return $decoded;
}
```

- [ ] **Step 2: Migrate call sites**

Replace `json_decode((string)file_get_contents('php://input'), true) ?: []` with `$req->jsonBody()`. Wrap the call in try/catch and return 400 on `InvalidArgumentException`.

- [ ] **Step 3: Smoke + commit**

```bash
git add system/Http/Request.php system/Controller/
git commit -m "refactor(http): centralise JSON body parsing via Request::jsonBody (C-2, S-7)"
```

---

### Task 6 — `Validator` service for POST validation (C-3)

**Files:**
- Create: `system/Http/Validator.php`
- Modify: 5 highest-volume controllers (login, register, project create/update, user create, form save)

- [ ] **Step 1: Design**

A fluent builder that accumulates errors and either returns the cleaned array or throws `ValidationException` mapping `field => code`.

```php
<?php
declare(strict_types=1);
namespace App\Http;

final class Validator
{
    private array $errors = [];

    public function __construct(private array $data) {}

    public static function for(array $data): self { return new self($data); }

    public function required(string $field): self
    {
        if (!isset($this->data[$field]) || trim((string)$this->data[$field]) === '') {
            $this->errors[$field] = 'required';
        }
        return $this;
    }

    public function email(string $field): self { /* ... */ return $this; }
    public function minLength(string $field, int $n): self { /* ... */ return $this; }
    public function maxLength(string $field, int $n): self { /* ... */ return $this; }
    public function in(string $field, array $allowed): self { /* ... */ return $this; }

    public function passes(): bool { return empty($this->errors); }
    public function errors(): array { return $this->errors; }
    public function clean(): array {
        if (!$this->passes()) throw new ValidationException($this->errors);
        return $this->data;
    }
}

final class ValidationException extends \RuntimeException
{
    public function __construct(public readonly array $fields)
    {
        parent::__construct('validation_failed');
    }
}
```

- [ ] **Step 2: Unit tests**

```php
// tests/unit/test_validator.php
it('required catches empty string', function () { /* ... */ });
it('passes returns true on all-valid input', function () { /* ... */ });
it('clean returns the data when valid', function () { /* ... */ });
it('clean throws ValidationException when invalid', function () { /* ... */ });
```

- [ ] **Step 3: Migrate 5 call sites**

Wrap the existing inline trim/check pattern in `Validator::for($req->post)->required('email')->email('email')->required('password')->minLength('password', 8)->clean()`.

Catch `ValidationException` at the controller level (or in a shared middleware) and return 422 with `fields` payload.

- [ ] **Step 4: Smoke + commit**

```bash
git add system/Http/Validator.php tests/unit/test_validator.php system/Controller/AuthController.php
git commit -m "refactor(http): Validator service + migrate auth flow (C-3, part 1)"
```

Plan to migrate the rest opportunistically as touch them.

---

### Task 7 — `Log` service for structured logging (C-5)

**Files:**
- Create: `system/Service/Log.php`
- Migrate the 9 `error_log()` sites

- [ ] **Step 1: Implement**

```php
<?php
declare(strict_types=1);
namespace App\Service;

final class Log
{
    public static function error(string $tag, string $msg, array $ctx = []): void
    {
        self::write('error', $tag, $msg, $ctx);
    }

    public static function warn(string $tag, string $msg, array $ctx = []): void
    {
        self::write('warn', $tag, $msg, $ctx);
    }

    private static function write(string $level, string $tag, string $msg, array $ctx): void
    {
        $line = sprintf(
            '[%s] [%s] %s %s',
            gmdate('Y-m-d\TH:i:s\Z'),
            strtoupper($level) . ':' . $tag,
            $msg,
            $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );
        error_log($line);
    }
}
```

- [ ] **Step 2: Migrate the 9 sites**

Each `error_log('[updater:restore] ' . $e->getMessage())` → `Log::error('updater.restore', $e->getMessage(), ['exception' => $e->getMessage()])`.

- [ ] **Step 3: Commit**

```bash
git add system/Service/Log.php system/Controller/ system/Service/Updater.php system/Api/V1/ApiKernel.php
git commit -m "feat(log): Log::error/warn service replaces 9 ad-hoc error_log calls (C-5)"
```

---

### Task 8 — Repository return-type annotations (C-6)

**Files:** every file in `system/Repository/*.php` (21 repos).

- [ ] **Step 1: Apply standard**

```php
/** @return array<string,mixed>|null */
public function findById(int $id): ?array { ... }

/** @return list<array<string,mixed>> */
public function listAll(): array { ... }
```

- [ ] **Step 2: (Optional) Add PHPStan**

If the project is willing to take on Composer-less PHPStan: download the `phpstan.phar`, drop it in `bin/`, add `make stan` target. Configure to level 4 first.

- [ ] **Step 3: Commit**

```bash
git add system/Repository/
git commit -m "refactor(repos): @return list<array<string,mixed>> annotations (C-6)"
```

---

### Task 9 — `ActivityLogRepository::log()` → DTO/assoc-array signature (C-7)

**Files:**
- Modify: `system/Repository/ActivityLogRepository.php`
- Modify: all 32+ call sites

- [ ] **Step 1: Add the new signature alongside the old**

```php
public function log(string|array $event, ...): int
{
    if (is_array($event)) {
        $args = $event;
        $event = $args['event'];
        // ... extract from assoc array
    }
    // existing logic
}
```

This lets you migrate gradually. Eventually deprecate the positional form.

- [ ] **Step 2: Migrate call sites**

```php
App::make('activity')->log([
    'event'      => 'task.created',
    'actor_id'   => $userId,
    'project_id' => $projectId,
    'task_id'    => $taskId,
    'summary'    => "{$user['name']} added '{$title}'",
    'meta'       => ['source' => 'kanban'],
]);
```

- [ ] **Step 3: Commit**

```bash
git add system/Repository/ActivityLogRepository.php system/
git commit -m "refactor(activity): support assoc-array signature for log() (C-7)"
```

---

## Group 3 — Security defence-in-depth (Tasks 10-14)

### Task 10 — `HtmlSanitizer` requires DOMDocument (S-3)

**Files:**
- Modify: `system/Service/HtmlSanitizer.php`
- Modify: `system/bootstrap.php` (extension check)
- Add to: `docs/DEPLOYMENT.md` (already written in #9.1a)

- [ ] **Step 1: Hard-require DOM extension**

In `system/bootstrap.php` add (early):

```php
foreach (['dom', 'fileinfo', 'mbstring', 'pdo'] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "Missing required PHP extension: $ext\n");
        http_response_code(500);
        exit(1);
    }
}
```

- [ ] **Step 2: Remove the fallback path in `HtmlSanitizer`**

Delete the `if (!extension_loaded('dom'))` branch.

- [ ] **Step 3: Tests**

```php
// tests/unit/test_html_sanitizer.php — verify hard-require throws (or run only with dom available)
```

- [ ] **Step 4: Commit**

```bash
git add system/Service/HtmlSanitizer.php system/bootstrap.php docs/DEPLOYMENT.md
git commit -m "fix(security): hard-require ext-dom + finfo + mbstring at boot (S-3)"
```

---

### Task 11 — Explicit chmod on session dir + APP_SECRET split (S-5, S-8)

**Files:**
- Modify: `system/Auth/SessionManager.php`
- Modify: 4 call sites that use `LOGIN_HASH` as HMAC secret
- Modify: `.env.example`

- [ ] **Step 1: Session dir chmod**

```php
// system/Auth/SessionManager.php
if (!is_dir($dir)) mkdir($dir, 0700, true);
if (is_dir($dir)) @chmod($dir, 0700);  // tighten if umask loosened
session_save_path($dir);
```

- [ ] **Step 2: Add `APP_SECRET` env**

```php
// system/View/helpers.php (or wherever you have an env helper)
function app_secret(): string {
    return App::env('APP_SECRET') ?: App::env('LOGIN_HASH', '');
}
```

- [ ] **Step 3: Replace `LOGIN_HASH` usages**

In `PublicFormController`, `PublicPollController`, `PublicLinkController`, `ShortLinkVisitRepository::hashIp` — replace direct `App::env('LOGIN_HASH')` reads in HMAC contexts with `app_secret()`.

Keep `LOGIN_HASH` for the `/login` URL gate only.

- [ ] **Step 4: Document**

`.env.example` and `docs/SECURITY.md`:

```
# Required: shared secret for anti-bot HMAC + IP-hash dedup. Fall back
# to LOGIN_HASH for backward compatibility, but new installs should set
# this explicitly. 32+ chars of random.
APP_SECRET=
```

- [ ] **Step 5: Commit**

```bash
git add system/Auth/SessionManager.php system/Controller/ system/Repository/ShortLinkVisitRepository.php .env.example docs/SECURITY.md
git commit -m "feat(security): explicit sess-dir chmod + APP_SECRET env split (S-5, S-8)"
```

---

### Task 12 — Replace `@unlink` / `@rmdir` in Updater (S-9)

**Files:**
- Modify: `system/Service/Updater.php`

- [ ] **Step 1: Sweep**

```bash
grep -n "@unlink\|@rmdir" system/Service/Updater.php
```

For each, wrap in try/catch:

```php
try {
    if (!@unlink($path)) {
        Log::warn('updater.cleanup', "unlink failed for $path");
    }
} catch (\Throwable $e) {
    Log::warn('updater.cleanup', $e->getMessage(), ['path' => $path]);
}
```

(Alternatively, use `set_error_handler` to convert warnings to exceptions inside the cleanup loop.)

- [ ] **Step 2: Commit**

```bash
git add system/Service/Updater.php
git commit -m "fix(updater): log file-cleanup errors instead of silently swallowing (S-9)"
```

---

### Task 13 — CSP nonce for inline styles (S-6) — depends on V-5/CSS-5 progress

**Files:**
- Modify: `system/View/helpers.php` (`app_brand_style_tag()` + add `csp_nonce()` helper)
- Modify: `public/index.php` (set nonce in CSP header)
- Modify: `views/layouts/*.php` (use nonce attr)

- [ ] **Step 1: Generate per-request nonce**

```php
// system/View/helpers.php
function csp_nonce(): string {
    static $nonce = null;
    if ($nonce === null) $nonce = base64_encode(random_bytes(16));
    return $nonce;
}
```

- [ ] **Step 2: Embed in CSP**

```php
$nonce = csp_nonce();
header("Content-Security-Policy: default-src 'self'; ... style-src 'self' 'nonce-$nonce'; ...");
```

Remove `'unsafe-inline'`.

- [ ] **Step 3: Update inline style emitters**

`app_brand_style_tag()` → `<style nonce="<?= csp_nonce() ?>">…</style>`.

For inline `style=""` attributes in views, this won't be fully closeable until CSS-5 sweep completes — defer the actual removal of `'unsafe-inline'` until then.

- [ ] **Step 4: Commit**

```bash
git add system/View/helpers.php public/index.php views/
git commit -m "feat(security): CSP nonce for inline brand style (S-6 prep)"
```

---

### Task 14 — `BaseHandler::canSeeProject` lifted, helper consolidation (cross-cutting)

If 9.1a already lifted this, skip. Otherwise consolidate any handler-specific helpers that duplicate logic into `BaseHandler` once.

- [ ] **Step 1: grep for duplicates**
- [ ] **Step 2: Lift**
- [ ] **Step 3: Commit**

---

## Group 4 — Frontend module reorganisation (Tasks 15-19)

### Task 15 — Split `kanban.js` into 3 modules (J-2)

**Files:**
- Create: `public/assets/js/kanban-board.js` (~280 LOC: lazy-load + sortable + recount)
- Create: `public/assets/js/kanban-toolbar.js` (~120 LOC: filter/search/mine/sort)
- Create: `public/assets/js/kanban-columns.js` (~180 LOC: add/settings/delete-cascade)
- Modify: `public/assets/js/kanban.js` (becomes a thin façade that imports from the three)

- [ ] **Step 1: Identify section boundaries**

```bash
grep -nE "^(function|//.*ection|// ─)" public/assets/js/kanban.js
```

- [ ] **Step 2: Cut each section into its own file**

Be careful with shared closure state. If `initSortable` reads `currentFilter`, that state needs to be exposed via a small `state.js` or passed in.

- [ ] **Step 3: Verify e2e**

```bash
npx playwright test tests/e2e/kanban.spec.ts tests/e2e/kanban-features.spec.ts --reporter=line
```

- [ ] **Step 4: Commit**

```bash
git add public/assets/js/kanban*
git commit -m "refactor(js): split kanban.js (652 LOC) into board/toolbar/columns (J-2)"
```

---

### Task 16 — Extract `ui-fields.js` + refactor `form-builder.js` to class (J-1, J-4)

**Files:**
- Create: `public/assets/js/ui-fields.js` (exports `buildField`, `buildColorPickerField`, `buildPasswordField`)
- Modify: `public/assets/js/form-builder.js` (refactor into `class FormBuilder`)
- Modify: `public/assets/js/projects.js`, `users.js`, `kanban.js` (import from `ui-fields.js`)

- [ ] **Step 1: Create `ui-fields.js` consolidated helpers**
- [ ] **Step 2: Refactor `form-builder.js` to class**
- [ ] **Step 3: Smoke**

```bash
npx playwright test tests/e2e/forms-auto-task.spec.ts --reporter=line
```

- [ ] **Step 4: Commit**

```bash
git add public/assets/js/ui-fields.js public/assets/js/form-builder.js public/assets/js/projects.js public/assets/js/users.js public/assets/js/kanban.js
git commit -m "refactor(js): FormBuilder class + ui-fields.js consolidation (J-1, J-4)"
```

---

### Task 17 — Split `ui.js` (J-6) [conditional on stability]

Only if Task 1-16 are stable. Otherwise defer to 9.1c.

**Files:**
- Create: `public/assets/js/ui-modal.js`, `ui-fetch.js`, `ui-bootstrap.js`
- Modify: `views/layouts/main.php` (load `ui-bootstrap.js`)

- [ ] **Step 1: Split**
- [ ] **Step 2: Smoke**
- [ ] **Step 3: Commit**

---

### Task 18 — CSS split into 8 layered files (CSS-1)

**Files:**
- Create: `public/assets/css/{tokens,base,layout,forms,kanban,cards-panels,modal-toast,utilities}.css`
- Modify: `views/layouts/main.php` (link all 8)
- Delete: `public/assets/css/app.css`

- [ ] **Step 1: Map sections of `app.css` to new files**

Section markers `/* ─── §N */` already exist; use them.

- [ ] **Step 2: Move into the new files**

- [ ] **Step 3: Test cascade**

CSS cascade order matters — tokens first, then layout, then components, then utilities (highest specificity).

- [ ] **Step 4: Smoke via visual-audit Playwright**

```bash
npx playwright test tests/e2e/visual-audit.spec.ts --reporter=line
```

If screenshots differ, the cascade order is wrong.

- [ ] **Step 5: Commit**

```bash
git add public/assets/css/ views/layouts/main.php
git rm public/assets/css/app.css
git commit -m "refactor(css): split app.css (5175 LOC) into 8 layered files (CSS-1)"
```

---

### Task 19 — Migrate `.btn-primary` → `.btn--primary` (CSS-2) + delete dead `.top-pill` (CSS-3)

**Files:**
- Modify: all view files with `.btn-primary`/`.btn-secondary`/`.btn-ghost`/`.btn-danger`/`.btn-brand` (legacy single-dash)
- Modify: `public/assets/css/cards-panels.css` (or wherever the BEM definitions live)

- [ ] **Step 1: Sweep**

```bash
grep -rln 'class="[^"]*\bbtn-\(primary\|secondary\|ghost\|danger\|brand\)\b' views/ public/assets/js/
```

- [ ] **Step 2: sed-replace**

```bash
# In views/
sed -i '' 's/btn-primary/btn--primary/g; s/btn-secondary/btn--secondary/g; s/btn-ghost/btn--ghost/g; s/btn-danger/btn--danger/g; s/btn-brand/btn--brand/g' views/**/*.php public/assets/js/*.js
```

Verify visual-audit screenshots still match.

- [ ] **Step 3: Delete legacy CSS rules + `.top-pill` block**

In the appropriate CSS file (`cards-panels.css` or wherever), remove the single-dash `.btn-*` rules and the entire `§13 Top pills (legacy)` section.

- [ ] **Step 4: Commit**

```bash
git add views/ public/assets/js/ public/assets/css/
git commit -m "refactor(css): unify on .btn--variant BEM, drop legacy + dead .top-pill (CSS-2, CSS-3)"
```

---

## Group 5 — Frontend UX polish (Tasks 20-23)

### Task 20 — Modal focus trap (V-3)

**Files:**
- Modify: `public/assets/js/ui.js` (or `ui-modal.js` if Task 17 done)

- [ ] **Step 1: Add focus-trap logic to UI.modal**

```js
// On modal open:
const focusable = node.querySelectorAll('a[href], button, textarea, input, select, [tabindex]:not([tabindex="-1"])');
const first = focusable[0];
const last  = focusable[focusable.length - 1];
node.addEventListener('keydown', (e) => {
  if (e.key !== 'Tab') return;
  if (e.shiftKey && document.activeElement === first) {
    e.preventDefault(); last.focus();
  } else if (!e.shiftKey && document.activeElement === last) {
    e.preventDefault(); first.focus();
  }
});
```

- [ ] **Step 2: Add a Playwright a11y check**

```ts
// tests/e2e/a11y.spec.ts
test('modal traps focus on Tab', async ({ page }) => { /* ... */ });
```

- [ ] **Step 3: Commit**

```bash
git add public/assets/js/ui.js tests/e2e/a11y.spec.ts
git commit -m "fix(a11y): proper focus trap in UI.modal (V-3)"
```

---

### Task 21 — `withButtonBusy` helper + `.btn[aria-busy]` styling (V-4)

**Files:**
- Modify: `public/assets/js/utils.js` (add helper)
- Modify: `public/assets/css/utilities.css` (or the btn module)
- Migrate: 6 highest-traffic call sites

- [ ] **Step 1: Helper**

```js
export async function withButtonBusy(btn, fn) {
  if (!btn) return await fn();
  btn.disabled = true;
  btn.setAttribute('aria-busy', 'true');
  try {
    return await fn();
  } finally {
    btn.disabled = false;
    btn.removeAttribute('aria-busy');
  }
}
```

- [ ] **Step 2: CSS**

```css
.btn[aria-busy="true"] { position: relative; color: transparent; }
.btn[aria-busy="true"]::before {
  content: ''; position: absolute; inset: 50% auto auto 50%; width: 14px; height: 14px;
  margin: -7px 0 0 -7px; border: 2px solid currentColor; border-top-color: transparent;
  border-radius: 50%; animation: spin .8s linear infinite;
}
@keyframes spin { to { transform: rotate(1turn); } }
```

- [ ] **Step 3: Migrate 6 sites**

`form-builder.js` save, `poll-builder.js` save, `task-page.js` save-description, `polls-index.js` activate, `tags.js` save, `users.js` create.

- [ ] **Step 4: Commit**

```bash
git add public/assets/js/utils.js public/assets/css/
git commit -m "feat(ux): withButtonBusy helper + aria-busy spinner styling (V-4)"
```

---

### Task 22 — Inline field-error pattern (V-5)

**Files:**
- Modify: `public/assets/css/forms.css` (or the forms module)
- Modify: 3-5 highest-volume forms (login, register, project create, user create)

- [ ] **Step 1: CSS for `.field--invalid`**

```css
.field--invalid .input { border-color: var(--danger); }
.field__error { color: var(--danger); font-size: 12px; margin-top: 4px; }
```

- [ ] **Step 2: Controller passes `$errors` map to view**

For each migrated form, pass `$errors = ['email' => 'invalid', ...]` to the view.

- [ ] **Step 3: View renders inline error**

```php
<div class="field<?= isset($errors['email']) ? ' field--invalid' : '' ?>">
  <label for="f-email">…</label>
  <input id="f-email" name="email">
  <?php if (isset($errors['email'])): ?>
    <div class="field__error"><?= e(t('errors.email.' . $errors['email'])) ?></div>
  <?php endif; ?>
</div>
```

- [ ] **Step 4: i18n keys**

Add `errors.email.invalid`, `errors.password.too_short`, etc., in all three catalogues.

- [ ] **Step 5: Commit**

```bash
git add public/assets/css/forms.css views/auth/ views/users/ views/projects/ system/Controller/ system/i18n/
git commit -m "feat(ux): inline field-error pattern + 5 highest-volume forms (V-5)"
```

---

### Task 23 — Mobile breakpoints for `tasks/show`, `projects/show`, `admin/compass/*` (V-8)

**Files:**
- Modify: `public/assets/css/layout.css` (or kanban + cards modules)

- [ ] **Step 1: Add `@media (max-width: 720px)` rules**

```css
@media (max-width: 720px) {
  .project-overview { grid-template-columns: 1fr; gap: 16px; }
  .project-sidebar  { margin-top: 16px; }
  .task-page__layout { grid-template-columns: 1fr; }
  .compass-tabs { flex-direction: column; }
}
```

- [ ] **Step 2: Smoke via visual-audit at 375×667**

Add a mobile project to `playwright.config.ts`:

```ts
projects: [
  { name: 'desktop', use: { /* default */ } },
  { name: 'mobile',  use: { viewport: { width: 375, height: 667 } } },
],
```

- [ ] **Step 3: Commit**

```bash
git add public/assets/css/ playwright.config.ts
git commit -m "feat(ux): mobile breakpoints for tasks/projects/compass + mobile playwright project (V-8)"
```

---

## Group 6 — Test fill-in (Tasks 24-30)

### Task 24 — `TelegramNotifier` unit tests (T-2)
### Task 25 — Markdown edge-case tests (T-4)
### Task 26 — `FileUploader::store()` tests (T-5)
### Task 27 — Standalone comment/lightbox e2e (T-6)
### Task 28 — Admin smoke e2e (T-7)
### Task 29 — Mobile viewport e2e (T-9)
### Task 30 — `EventBus`, `NotificationLogger` unit tests (T-10)

Each follows the same TDD shape (write tests, run, expect fail, implement if needed, commit). Detailed step-by-step omitted here for brevity — refer to spec for the per-test list.

- [ ] **One commit per file** to keep git history readable.

Combined effort: ~5h.

---

## Group 7 — Asset budget (Tasks 31-33)

### Task 31 — Delete FontAwesome brand fonts (AS-1)

**Files:**
- Delete: `public/assets/vendor/fontawesome/webfonts/fa-brands-400.*`
- Delete: `public/assets/vendor/fontawesome/webfonts/fa-v4compatibility.*`
- Modify: `public/assets/vendor/fontawesome/css/all.min.css` (strip `@font-face` for brands)

- [ ] **Step 1: Confirm zero usage**

```bash
grep -roh "fa-brands\|fab " views/ public/assets/js/ | head
```

Expected: zero.

- [ ] **Step 2: Delete fonts**

```bash
rm public/assets/vendor/fontawesome/webfonts/fa-brands-400.* public/assets/vendor/fontawesome/webfonts/fa-v4compatibility.*
```

- [ ] **Step 3: Strip `@font-face` for those families from all.min.css**

Find the relevant blocks (search for "Font Awesome Brands" and "FontAwesome-v4compat") and delete.

- [ ] **Step 4: Smoke**

```bash
make e2e 2>&1 | tail -5  # all 114+ passing
```

- [ ] **Step 5: Commit**

```bash
git add public/assets/vendor/fontawesome/
git commit -m "perf(assets): drop 324KB unused FontAwesome brand fonts (AS-1)"
```

---

### Task 32 — `<link rel="preload">` for primary fonts (AS-2)

**Files:**
- Modify: `views/layouts/main.php`

- [ ] **Step 1: Add preload tags**

```html
<link rel="preload" as="font" type="font/woff2" href="/assets/fonts/manrope-500.woff2" crossorigin>
<link rel="preload" as="font" type="font/woff2" href="/assets/fonts/jetbrainsmono-400.woff2" crossorigin>
```

- [ ] **Step 2: Commit**

```bash
git add views/layouts/main.php
git commit -m "perf(assets): preload primary woff2 fonts (AS-2)"
```

---

### Task 33 — Lazy-load Quill (AS-3)

**Files:**
- Modify: `views/layouts/main.php` (remove the unconditional Quill script + CSS load)
- Modify: `public/assets/js/wysiwyg.js` (dynamic-load on first `[data-quill]` detection)

- [ ] **Step 1: Wrap Quill init in dynamic import**

```js
async function ensureQuillLoaded() {
  if (window.Quill) return;
  // Inject CSS + script tags
  const css = document.createElement('link');
  css.rel = 'stylesheet'; css.href = '/assets/vendor/quill/quill.snow.css';
  document.head.appendChild(css);
  await new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = '/assets/vendor/quill/quill.min.js';
    s.onload = resolve; s.onerror = reject;
    document.head.appendChild(s);
  });
}
```

Call `await ensureQuillLoaded()` before each `new Quill(...)`.

- [ ] **Step 2: Remove unconditional load from main.php**

- [ ] **Step 3: Smoke pages that need Quill**

```bash
npx playwright test tests/e2e/comments.spec.ts tests/e2e/forms-auto-task.spec.ts tests/e2e/polls.spec.ts --reporter=line
```

- [ ] **Step 4: Commit**

```bash
git add views/layouts/main.php public/assets/js/wysiwyg.js
git commit -m "perf(assets): lazy-load Quill on first [data-quill] match (AS-3)"
```

---

## Group 8 — Docs / ops finish-out (Tasks 34-38)

### Task 34 — Move dev-notes out of `docs/` (D-3, D-4)

**Files:**
- Move: `docs/PLAN-next-session.md`, `docs/NEXT-SESSION-PROMPT.md` → `.dev-notes/`
- Move: `docs/superpowers/` → `.dev-notes/superpowers/`
- Modify: `Makefile` (`make package` already excludes — verify)

- [ ] **Step 1: Create + move**

```bash
mkdir -p .dev-notes
git mv docs/PLAN-next-session.md docs/NEXT-SESSION-PROMPT.md .dev-notes/
git mv docs/superpowers .dev-notes/
```

- [ ] **Step 2: Update any links** (this plan + spec self-references)

- [ ] **Step 3: Commit**

```bash
git commit -m "docs: move dev-notes (plans + handoffs) to .dev-notes/ (D-3, D-4)"
```

---

### Task 35 — `.env.example` parity with code (O-3)

**Files:**
- Modify: `.env.example`

- [ ] **Step 1: Add missing keys**

```
UPLOAD_DIR=public/uploads

UPDATE_ENABLED=true
UPDATE_CHECK_INTERVAL=3600
UPDATE_REPO_URL=https://github.com/your-org/otack-manager
UPDATE_BACKUP_KEEP=5

# Used by Compass → Migrate to MySQL wizard
MYSQLDUMP_PATH=mysqldump
MYSQL_PATH=mysql

# Trusted reverse proxies (CSV CIDR), see SECURITY.md
TRUSTED_PROXIES=

# HMAC secret for anti-bot + IP dedup. Falls back to LOGIN_HASH if unset.
APP_SECRET=
```

- [ ] **Step 2: Add a convention test**

```php
// tests/unit/test_env_example.php
it('every App::env() key has a doc in .env.example', function () {
    $envExample = file_get_contents(dirname(__DIR__, 2) . '/.env.example');
    $code = '';
    foreach (glob(dirname(__DIR__, 2) . '/system/**/*.php') as $f) {
        $code .= file_get_contents($f);
    }
    preg_match_all('#App::env\([\'"]([A-Z_]+)[\'"]#', $code, $m);
    $keys = array_unique($m[1]);
    $missing = [];
    foreach ($keys as $k) {
        if (!preg_match("/^$k=/m", $envExample)) $missing[] = $k;
    }
    assert_true(empty($missing), '.env.example missing: ' . implode(', ', $missing));
});
```

- [ ] **Step 3: Commit**

```bash
git add .env.example tests/unit/test_env_example.php
git commit -m "ops(env): document 7 missing variables + convention test (O-3)"
```

---

### Task 36 — `package.json` cleanup (O-4)

- [ ] **Step 1: Fix license + main + description + scripts**

```json
{
  "name": "otack-manager",
  "version": "1.3.0",
  "description": "Self-hosted PHP/SQLite/MySQL project + task manager with kanban, forms, polls, and a third-party REST API.",
  "license": "MIT",
  "scripts": {
    "test": "make test",
    "e2e": "make e2e",
    "unit": "make unit",
    "api": "make api"
  },
  "devDependencies": {
    "@playwright/test": "^...",
    "typescript": "^..."
  }
}
```

- [ ] **Step 2: Commit**

```bash
git add package.json
git commit -m "chore: align package.json with reality (license MIT, drop boilerplate) (O-4)"
```

---

### Task 37 — Log rotation + activity_log prune (O-5)

**Files:**
- Modify: `system/bootstrap.php` (size check on errors.log)
- Modify: `system/Controller/CompassController.php` (add prune action)
- Modify: `views/admin/compass/cache.php` (or wherever the prune buttons live)

- [ ] **Step 1: errors.log size check on boot**

```php
// system/bootstrap.php
$errLog = APP_ROOT . '/data/errors.log';
if (is_file($errLog) && filesize($errLog) > 5_000_000) {
    // Truncate to last 1MB
    $h = fopen($errLog, 'r+');
    if ($h) {
        fseek($h, -1_000_000, SEEK_END);
        $tail = stream_get_contents($h);
        ftruncate($h, 0); rewind($h);
        fwrite($h, "[truncated at boot]\n" . $tail);
        fclose($h);
    }
}
```

- [ ] **Step 2: Activity log prune in Compass**

```php
// Add to CompassController
public function pruneActivityLog(Request $req, array $params): void
{
    $days = (int)App::env('ACTIVITY_LOG_KEEP_DAYS', '180');
    $cutoff = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d\TH:i:s\Z');
    $count = App::make('activity')->pruneBefore($cutoff);
    $this->flash('flash_success', t('compass.activity_log.pruned', ['count' => $count]));
    Response::redirect('/admin/compass/cache');
}
```

Add UI button + route.

- [ ] **Step 3: Commit**

```bash
git add system/bootstrap.php system/Controller/CompassController.php system/Repository/ActivityLogRepository.php public/index.php views/admin/compass/cache.php system/i18n/
git commit -m "ops(logs): errors.log auto-truncate + activity_log prune button (O-5)"
```

---

### Task 38 — `bin/check-env.php` (O-6)

**Files:**
- Create: `bin/check-env.php`
- Modify: `Makefile` (add `make check-env` target; run from `make setup`)

- [ ] **Step 1: Script**

```php
<?php
declare(strict_types=1);

$required = ['pdo', 'pdo_sqlite', 'pdo_mysql', 'dom', 'fileinfo', 'mbstring', 'curl'];
$missing = [];
foreach ($required as $ext) {
    if (!extension_loaded($ext)) $missing[] = $ext;
}
if ($missing) {
    fwrite(STDERR, "Missing required PHP extensions: " . implode(', ', $missing) . "\n");
    exit(1);
}
echo "All required PHP extensions present.\n";

// Sanity-check writable dirs
foreach (['data', 'public/uploads'] as $dir) {
    $path = __DIR__ . '/../' . $dir;
    if (!is_dir($path) || !is_writable($path)) {
        fwrite(STDERR, "Required writable directory: $path\n");
        exit(1);
    }
}
echo "Required directories present and writable.\n";

exit(0);
```

- [ ] **Step 2: Makefile**

```make
check-env:
	php bin/check-env.php

setup: check-env
	# ... existing setup
```

- [ ] **Step 3: Commit**

```bash
git add bin/check-env.php Makefile
git commit -m "ops(setup): bin/check-env.php sanity script (O-6)"
```

---

## Group 9 — i18n cleanup (Task 39)

### Task 39 — Prune 105 unused i18n keys (I-1)

**Files:**
- Modify: `system/i18n/en.php`, `pl.php`, `uk.php`
- Create: `tools/i18n-check.php` (or as a test helper)

- [ ] **Step 1: Implement the diff**

After Wave 9.1a fixed V-6 (activity-row.php → t()), re-run the diff:

```bash
# Extract all keys
grep -oE "^\s*'[a-z_.]+'" system/i18n/en.php | sed -E "s/^\s*'(.+)'.*/\1/" | sort -u > /tmp/declared.txt

# Extract all referenced keys
grep -rohE "t\(\s*'[a-z_.]+" --include=*.php --include=*.js views/ system/ public/assets/js/ \
  | sed -E "s/.*t\(\s*'(.+)/\1/" | sort -u > /tmp/used.txt

# Find unused
diff /tmp/declared.txt /tmp/used.txt | grep "^<" | sed 's/^< //' > /tmp/unused.txt
wc -l /tmp/unused.txt
```

- [ ] **Step 2: Delete the unused keys from all three catalogues**

Be careful: some keys might be used via `t('prefix.' . $dynamic)` and not picked up by static grep. Spot-check `activity.*` (probably all used after V-6), `compass.*`, `updates.*`.

- [ ] **Step 3: Convention test that fails on unused keys**

```php
// tests/unit/test_i18n_usage.php
it('no unused i18n keys (modulo dynamic-key prefixes)', function () {
    // Allowlist of dynamic prefixes that grep can't see
    $dynamic = ['activity.', 'errors.', 'js.', 'compass.action.'];
    // ... diff logic
});
```

- [ ] **Step 4: Commit**

```bash
git add system/i18n/ tests/unit/test_i18n_usage.php
git commit -m "i18n: prune 105 unused keys + usage convention test (I-1)"
```

---

## Final pass — Wave 9.1b ship

- [ ] **Step 1: Run full suite**

```bash
make unit 2>&1 | tail -3   # ≥ 260 passed
make api 2>&1 | tail -3    # ≥ 86 passed
make e2e 2>&1 | tail -5    # ≥ 130 passed (with new tests)
```

- [ ] **Step 2: Manual smoke + performance check**

```bash
# Asset budget verification:
curl -sS -w "\nTotal: %{size_download} bytes\n" -o /dev/null http://localhost:8000/login  # was ~600KB, expect ~260KB after AS-1/AS-3
curl -sS -w "\nTotal: %{size_download} bytes\n" -o /dev/null http://localhost:8000/projects/1
```

- [ ] **Step 3: Version bump**

```bash
sed -i '' "s/APP_VERSION = '1.2.0'/APP_VERSION = '1.3.0'/" system/version.php
```

- [ ] **Step 4: Update TODO.md** mark #9.1b done.

- [ ] **Step 5: Merge + tag + push**

```bash
git checkout main
git merge --no-ff refactor/9-1b-architecture -m "Merge wave 9.1b — architecture tidy-up (v1.3.0)"
git tag -a v1.3.0 -m "v1.3.0 — architecture tidy from TODO #9.1b"
git push origin main
git push origin v1.3.0
```

---

## Self-review

All 38 should-fix items accounted for:
- Backend arch: A-1, A-2, A-3, A-4, A-7 → Tasks 1-3
- Backend code-quality: C-1 to C-8 → Tasks 4-9
- Security defence: S-3, S-5, S-6, S-7 (folded), S-8, S-9 → Tasks 10-13
- Frontend JS: J-1, J-2, J-4, J-6 → Tasks 15-17
- CSS: CSS-1, CSS-2, CSS-3 → Tasks 18-19
- UX: V-3, V-4, V-5, V-8 → Tasks 20-23
- Tests: T-2, T-4, T-5, T-6, T-7, T-9, T-10, T-11, T-12 → Tasks 24-30
- Assets: AS-1, AS-2, AS-3 → Tasks 31-33
- Docs/ops: D-3, D-4, O-3, O-4, O-5, O-6 → Tasks 34-38
- i18n: I-1 → Task 39

Total ~39 tasks, ~5 dev-days.
