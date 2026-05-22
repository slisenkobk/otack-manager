# Otack Tasks Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a minimal multi-user PHP/SQLite project & task manager with kanban, comments, file attachments, admin-approved auth, and Telegram notifications — server-rendered, no SPA, no composer dependencies.

**Architecture:** PHP 8.2+ procedural-OO with hand-rolled namespaced autoloader, SQLite via PDO, server-rendered PHP templates, vanilla JS modules. Two vendored frontend libs only: SortableJS (kanban) + FontAwesome 6 (icons). One single-channel Telegram notifier via curl. Editorial design system from `dashboard-editorial-mockup.html` re-skinned to Manrope (no italics, no serifs).

**Tech Stack:** PHP 8.2+ · SQLite · vanilla JS · Manrope · JetBrains Mono · FontAwesome 6 · SortableJS · Playwright (E2E tests)

**Spec:** `docs/superpowers/specs/2026-05-22-otack-tasks-design.md`

**Testing strategy:** Hand-rolled tiny PHP test runner (`tests/run.php`) for unit tests of pure logic (Router, Markdown, FileUploader::validate, position-midpoint math). Playwright for E2E behaviour tests covering auth flow, project CRUD, kanban DnD, comments, attachments. No composer.

**Working directory:** `/Users/slisenkobogdan/Work/AINeoLab/internal/otack/otack-tasks`

## Conventions used throughout

### Controller constructor signature (canonical)

Every controller extends `App\Controller\BaseController` and uses **one** signature:

```php
abstract class BaseController {
    public function __construct(
        protected \App\View\Renderer $view,
        protected \App\App $app,
        protected ?array $user = null,
    ) {}
}
```

Subclasses pull additional services via `$this->app->make('…')` (typically assigned to a typed property in their own constructor that calls `parent::__construct(...)`). The dispatcher in `public/index.php` always passes exactly these three args. No other signature is used — earlier mentions of `(Renderer, PDO, &$session, Csrf)` in Task 6 are superseded by Task 12 step 6 which installs this canonical pattern.

### Singletons registered in `App` container

These keys are referenced across tasks. Introduced in the task in brackets, but the list lives here for quick lookup:

| Key | Type | Task |
|---|---|---|
| `db` | `\PDO` | 6 |
| `schema` | `\App\Database\SchemaBootstrap` | 6 |
| `view` | `\App\View\Renderer` | 6 |
| `users` | `\App\Repository\UserRepository` | 12 |
| `hasher` | `\App\Auth\PasswordHasher` | 12 |
| `auth` | `\App\Auth\AuthManager` | 12 |
| `projects` | `\App\Repository\ProjectRepository` | 16 |
| `members` | `\App\Repository\ProjectMemberRepository` | 16 |
| `columns` | `\App\Repository\TaskColumnRepository` | 16 |
| `tasks` | `\App\Repository\TaskRepository` | 17 |
| `events` | `\App\Service\EventBus` | 19 |
| `comments` | `\App\Repository\CommentRepository` | 20 |
| `attachments` | `\App\Repository\AttachmentRepository` | 21 |
| `uploader` | `\App\Service\FileUploader` | 21 |
| `tags` | `\App\Repository\TagRepository` | 23 |
| `tg` | `\App\Service\NotificationLogger` | 25 |

---

## Task 0: Repository init

**Files:** Create `.gitignore`, `README.md`, `.env.example`, root `.htaccess`

- [ ] **Step 1: Init git and create base files**

```bash
cd /Users/slisenkobogdan/Work/AINeoLab/internal/otack/otack-tasks
git init -b main
```

Create `.gitignore`:
```
/data/app.sqlite
/data/sessions/
/data/.schema/
/data/errors.log
/public/uploads/
/.env
/node_modules/
/.playwright/
/test-results/
.DS_Store
```

Create `.env.example`:
```
APP_URL=http://localhost:8000
APP_DEBUG=true
DB_PATH=data/app.sqlite
SESSION_LIFETIME=43200
UPLOAD_MAX_FILE=52428800
UPLOAD_MAX_IMAGE=5242880
TG_BOT_TOKEN=
TG_CHAT_ID=
```

Create root `.htaccess`:
```apache
RewriteEngine On
RewriteRule ^(system|data|docs|tests)/ - [F,L]
RewriteRule ^\.env - [F,L]
RewriteCond %{REQUEST_URI} !^/public/
RewriteRule ^(.*)$ public/$1 [L]
```

Create `README.md` with run instructions: copy `.env.example` to `.env`, then `php -S localhost:8000 -t public public/index.php`. First registered user becomes admin automatically.

- [ ] **Step 2: Commit**

```bash
git add .gitignore README.md .env.example .htaccess
git commit -m "chore: repository init"
```

---

## Task 1: Autoloader and App container

**Files:** Create `system/bootstrap.php`, `system/App.php`, `tests/run.php`, `tests/unit/test_autoload.php`, stub `system/Http/Csrf.php`

- [ ] **Step 1: Write failing test** — create `tests/run.php` with minimal `it()`/`assert_eq`/`assert_true` helpers that load `system/bootstrap.php`, glob `tests/unit/test_*.php`, run each, and exit with non-zero on any failure. Create `tests/unit/test_autoload.php` that asserts `class_exists('App\Http\Csrf')`.

- [ ] **Step 2: Run** `php tests/run.php` — expect fatal (bootstrap missing).

- [ ] **Step 3: Implement**

`system/bootstrap.php`:
```php
<?php
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));
if (is_file(APP_ROOT . '/.env')) {
    foreach (file(APP_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#') continue;
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        $_ENV[trim($k)] = trim($v);
    }
}
spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'App\\')) return;
    $rel = str_replace('\\', '/', substr($class, 4)) . '.php';
    $path = APP_ROOT . '/system/' . $rel;
    if (is_file($path)) require $path;
});
require APP_ROOT . '/system/App.php';
```

`system/App.php`:
```php
<?php
declare(strict_types=1);
namespace App;
final class App {
    private static array $factories = [];
    private static array $instances = [];
    public static function singleton(string $id, callable $factory): void { self::$factories[$id] = $factory; }
    public static function make(string $id): object {
        if (isset(self::$instances[$id])) return self::$instances[$id];
        if (!isset(self::$factories[$id])) throw new \RuntimeException("Service '$id' not registered");
        return self::$instances[$id] = (self::$factories[$id])();
    }
    public static function env(string $key, string $default = ''): string { return (string)($_ENV[$key] ?? $default); }
}
```

Stub `system/Http/Csrf.php`:
```php
<?php
declare(strict_types=1);
namespace App\Http;
final class Csrf { /* filled in Task 5 */ }
```

- [ ] **Step 4: Run** `php tests/run.php` → expect `1 passed`.

- [ ] **Step 5: Commit**
```bash
git add system tests
git commit -m "feat: autoloader, App container and test runner"
```

---

## Task 2: SQLite Connection and SchemaBootstrap

**Files:** Create `system/Database/Connection.php`, `system/Database/SchemaBootstrap.php`, `tests/unit/test_database.php`, `data/.gitkeep`

- [ ] **Step 1: Write failing test**

`tests/unit/test_database.php`:
- Asserts `Connection::open($tmpFile)` returns PDO with `PRAGMA foreign_keys=1`.
- Asserts `SchemaBootstrap::ensure('foo', 1, $fn)` runs `$fn` once even when called twice.

- [ ] **Step 2: Run** — expect FAIL.

- [ ] **Step 3: Implement**

`system/Database/Connection.php`:
```php
<?php
declare(strict_types=1);
namespace App\Database;
final class Connection {
    public static function open(string $path): \PDO {
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $pdo = new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        foreach (['PRAGMA foreign_keys = ON', 'PRAGMA journal_mode = WAL', 'PRAGMA busy_timeout = 5000'] as $p) {
            $pdo->query($p);
        }
        return $pdo;
    }
}
```

`system/Database/SchemaBootstrap.php`:
```php
<?php
declare(strict_types=1);
namespace App\Database;
final class SchemaBootstrap {
    public function __construct(private \PDO $pdo, private string $markerDir) {
        if (!is_dir($markerDir)) mkdir($markerDir, 0755, true);
    }
    public function ensure(string $key, int $version, callable $migrate): void {
        $marker = $this->markerDir . "/$key.$version";
        if (is_file($marker)) return;
        $migrate($this->pdo);
        file_put_contents($marker, (string)time());
    }
}
```

- [ ] **Step 4: Run** — expect `3 passed`.

- [ ] **Step 5: Commit**
```bash
git add system/Database tests/unit/test_database.php data/.gitkeep
git commit -m "feat: SQLite Connection and SchemaBootstrap"
```

---

## Task 3: Router

**Files:** Create `system/Http/Request.php`, `system/Http/Response.php`, `system/Routing/Router.php`, `tests/unit/test_router.php`

- [ ] **Step 1: Write failing test** — four cases: static path match, `{id}` param capture, miss returns null, GET/POST distinguished.

- [ ] **Step 2: Run** — expect FAIL.

- [ ] **Step 3: Implement**

`system/Http/Request.php` — readonly DTO with `method/path/query/post/files/headers/cookies`, `fromGlobals()` factory, `header(name)` and `isAjax()` helpers.

`system/Http/Response.php` — static `html($body,$status=200)`, `json(array,$status=200)`, `redirect($url,$status=303)`, `notFound($msg='Not found')`.

`system/Routing/Router.php`:
```php
<?php
declare(strict_types=1);
namespace App\Routing;
final class Router {
    private array $routes = [];
    public function get(string $path, string $target): void  { $this->add('GET',  $path, $target); }
    public function post(string $path, string $target): void { $this->add('POST', $path, $target); }
    private function add(string $method, string $path, string $target): void {
        $params = [];
        $regex = preg_replace_callback('#\{(\w+)\}#',
            function ($m) use (&$params) { $params[] = $m[1]; return '([^/]+)'; }, $path);
        $this->routes[] = ['method' => $method, 'regex' => '#^' . $regex . '$#', 'params' => $params, 'target' => $target];
    }
    public function match(string $method, string $path): ?array {
        foreach ($this->routes as $r) {
            if ($r['method'] !== $method) continue;
            if (!preg_match($r['regex'], $path, $m)) continue;
            $params = [];
            foreach ($r['params'] as $i => $name) $params[$name] = $m[$i + 1];
            [$controller, $action] = explode('@', $r['target'], 2);
            return ['controller' => $controller, 'action' => $action, 'params' => $params];
        }
        return null;
    }
}
```

- [ ] **Step 4: Run** — expect all router tests pass.

- [ ] **Step 5: Commit**
```bash
git add system/Http system/Routing tests/unit/test_router.php
git commit -m "feat: Request/Response and Router"
```

---

## Task 4: View Renderer and helpers

**Files:** Create `system/View/Renderer.php`, `system/View/helpers.php`, `views/layouts/blank.php`, `tests/unit/test_renderer.php`

- [ ] **Step 1: Write failing test** — assert `Renderer::render($template, $vars, $layout)` produces escaped output wrapped in layout; assert `e('<a>')` returns `&lt;a&gt;`.

- [ ] **Step 2: Run** — expect FAIL.

- [ ] **Step 3: Implement**

`system/View/helpers.php` exports plain functions: `e($s)` (htmlspecialchars), `url($path)` (prefix `APP_URL`), `csrf_field($token)` (hidden input), `icon($name, $extra='')` (`<i class="fa-solid fa-…">`), `fmt_date($iso)`, `fmt_datetime($iso)`, `fmt_size($bytes)`.

`system/View/Renderer.php`:
```php
<?php
declare(strict_types=1);
namespace App\View;
final class Renderer {
    public function __construct(private string $viewRoot) {}
    public function render(string $template, array $data = [], ?string $layout = null): string {
        require_once APP_ROOT . '/system/View/helpers.php';
        $content = $this->renderRaw($template, $data);
        if ($layout === null) return $content;
        return $this->renderRaw($layout, array_merge($data, ['content' => $content]));
    }
    private function renderRaw(string $template, array $data): string {
        $path = $this->viewRoot . '/' . $template . '.php';
        if (!is_file($path)) throw new \RuntimeException("View not found: $template");
        extract($data, EXTR_SKIP);
        ob_start();
        require $path;
        return ob_get_clean();
    }
}
```

`views/layouts/blank.php`:
```php
<!doctype html><html lang="uk"><head><meta charset="utf-8">
<title><?= e($title ?? 'Otack Tasks') ?></title></head>
<body><?= $content ?></body></html>
```

- [ ] **Step 4: Run** — expect renderer tests pass.

- [ ] **Step 5: Commit**
```bash
git add system/View views/layouts/blank.php tests/unit/test_renderer.php
git commit -m "feat: View Renderer and template helpers"
```

---

## Task 5: CSRF guard

**Files:** Modify `system/Http/Csrf.php`, create `tests/unit/test_csrf.php`

- [ ] **Step 1: Write failing test** — three cases: generate+verify, token() is stable within instance, `regenerate()` changes token.

- [ ] **Step 2: Run** — expect FAIL.

- [ ] **Step 3: Implement** — replace stub:
```php
<?php
declare(strict_types=1);
namespace App\Http;
final class Csrf {
    public function __construct(private array &$store) {}
    public function token(): string {
        if (empty($this->store['csrf'])) $this->store['csrf'] = bin2hex(random_bytes(32));
        return $this->store['csrf'];
    }
    public function regenerate(): void { $this->store['csrf'] = bin2hex(random_bytes(32)); }
    public function verify(?string $token): bool {
        if (!$token || empty($this->store['csrf'])) return false;
        return hash_equals($this->store['csrf'], $token);
    }
}
```

- [ ] **Step 4: Run** — expect CSRF tests pass.

- [ ] **Step 5: Commit**
```bash
git add system/Http/Csrf.php tests/unit/test_csrf.php
git commit -m "feat: CSRF token guard"
```

---

## Task 6: Front controller wired end-to-end

**Files:** Create `public/index.php`, `public/.htaccess`, `views/layouts/main.php`, `views/partials/{sidebar,topbar,modal-root,toast-root,lightbox-root}.php`, `public/assets/css/app.css` (minimal stub), `system/Auth/SessionManager.php`, `system/Controller/SmokeController.php`

- [ ] **Step 1: SessionManager** — `start($lifetime)` (sets save_path to `data/sessions/`, cookie httponly, samesite=Lax), `&storage()` returns ref to `$_SESSION`, `destroy()`.

- [ ] **Step 2: `public/.htaccess`** — RewriteCond on `-f`/`-d` to pass through, otherwise rewrite to `index.php [L,QSA]`. Deny `.env`.

- [ ] **Step 3: `views/layouts/main.php`** — full editorial shell: `<html lang="uk">`, preconnect, link `app.css` and `fontawesome/css/all.min.css`, meta csrf-token, `.shell` grid with sidebar partial, `.main > topbar partial + .body-wrap with $content`, includes modal-root/toast-root/lightbox-root partials, module script `/assets/js/ui.js`.

- [ ] **Step 4: Stub each partial** — empty wrappers: `<aside class="sidebar"></aside>`, `<header class="topbar"></header>`, `<div id="modal-root"></div>`, `<div id="toast-root" aria-live="polite"></div>`, `<div id="lightbox-root"></div>`.

- [ ] **Step 5: Stub `public/assets/css/app.css`** — only `:root` CSS variables (paper/ink/brand/rule palette as in spec §15) + base reset + `body { font-family: 'Manrope', system-ui, sans-serif; background: var(--paper); color: var(--ink); }`. Full design system added in Task 8.

- [ ] **Step 6: `public/index.php`**

```php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/system/bootstrap.php';

use App\App;
use App\Http\{Request, Response, Csrf};
use App\Routing\Router;
use App\Database\{Connection, SchemaBootstrap};
use App\Auth\SessionManager;
use App\View\Renderer;

$session = new SessionManager();
$session->start((int)App::env('SESSION_LIFETIME', '43200'));
$store = &$session->storage();

App::singleton('db', fn() => Connection::open(APP_ROOT . '/' . App::env('DB_PATH', 'data/app.sqlite')));
App::singleton('schema', fn() => new SchemaBootstrap(App::make('db'), APP_ROOT . '/data/.schema'));
App::singleton('view', fn() => new Renderer(APP_ROOT . '/views'));
$csrf = new Csrf($store);

$router = new Router();
$router->get('/', 'Smoke@hello');

$req = Request::fromGlobals();
$match = $router->match($req->method, $req->path);
if (!$match) { Response::notFound(); exit; }

if ($req->method === 'POST') {
    $token = $req->post['_csrf'] ?? $req->header('x-csrf-token');
    if (!$csrf->verify($token)) { Response::json(['error' => 'CSRF mismatch'], 419); exit; }
}

$class = 'App\\Controller\\' . $match['controller'] . 'Controller';
if (!class_exists($class)) { Response::notFound("Controller missing"); exit; }
$ctrl = new $class(App::make('view'), App::make('db'), $store, $csrf);
$ctrl->{$match['action']}($req, $match['params']);
```

- [ ] **Step 7: `system/Controller/SmokeController.php`** — TEMPORARY shape until Task 12 introduces `BaseController`: constructor `(Renderer $view, PDO $db, array &$session, Csrf $csrf)`; `hello()` renders `layouts/blank` with title "Otack Tasks" and content `<h1>Otack Tasks is up.</h1>`. This constructor signature is replaced in Task 12 by the canonical one declared in the "Conventions" section at the top of this plan; do not propagate this 4-arg shape to any later controller.

- [ ] **Step 8: Manual smoke**
```bash
cp .env.example .env
php -S localhost:8000 -t public public/index.php
```
Open `http://localhost:8000/` → expect "Otack Tasks is up." in Manrope (system font fallback for now). Stop server.

- [ ] **Step 9: Commit**
```bash
git add public system/Auth views/layouts views/partials system/Controller
git commit -m "feat: front controller, layouts, sessions, smoke route"
```

---

## Task 7: Vendor static assets (FontAwesome, SortableJS, fonts)

**Files:** Create `public/assets/vendor/fontawesome/{css/all.min.css, webfonts/*}`, `public/assets/vendor/sortable.min.js`, `public/assets/fonts/{manrope-*, jetbrainsmono-*}.woff2`

- [ ] **Step 1: FontAwesome 6 Free**
```bash
cd public/assets/vendor
curl -L -o fa.zip https://use.fontawesome.com/releases/v6.5.2/fontawesome-free-6.5.2-web.zip
unzip -q fa.zip && mv fontawesome-free-6.5.2-web fontawesome && rm fa.zip
find fontawesome -mindepth 1 -maxdepth 1 ! -name css ! -name webfonts -exec rm -rf {} +
cd fontawesome/css && find . -type f ! -name 'all.min.css' -delete
cd /Users/slisenkobogdan/Work/AINeoLab/internal/otack/otack-tasks
```

- [ ] **Step 2: SortableJS**
```bash
curl -L -o public/assets/vendor/sortable.min.js https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js
```

- [ ] **Step 3: Manrope + JetBrains Mono** — download from `fonts.google.com` (Manrope: weights 400/500/600/700; JetBrains Mono: 400/500/700). Place as `public/assets/fonts/manrope-{w}.woff2` and `public/assets/fonts/jetbrainsmono-{w}.woff2`. If running headless, create `public/assets/fonts/README.md` listing required filenames and pause for manual download.

- [ ] **Step 4: Append `@font-face` rules to `public/assets/css/app.css`** — one rule per weight pointing to `/assets/fonts/{family}-{weight}.woff2`, `font-display: swap`.

- [ ] **Step 5: Verify in browser** — `http://localhost:8000/` text renders in Manrope (devtools → Computed → font-family resolves to Manrope, not system-ui fallback). FontAwesome glyph from `<i class="fa-solid fa-house"></i>` (add to smoke page temporarily) renders.

- [ ] **Step 6: Commit**
```bash
git add public/assets
git commit -m "chore: vendor FontAwesome, SortableJS, Manrope, JetBrains Mono"
```

---

## Task 8: Full design-system CSS

**Files:** Replace `public/assets/css/app.css` body

- [ ] **Step 1: Author the full stylesheet** — sections in order (selector list per section; values follow `dashboard-editorial-mockup.html` lines 11-887 with these substitutions: every `font-family: 'Fraunces' ...` → `var(--font-sans)`; every `font-style: italic` → `font-weight: 600` (drop italic entirely); body **omits** `background-image: radial-gradient(...)` (no dot pattern); keep `body::before` grain SVG noise overlay):

  1. `:root` tokens (palette, shadows, fonts) — already present from Task 6
  2. `*, html, body` reset + base font + grain overlay (no dots)
  3. `.shell`, `.sidebar` (260px), `.nav-group`, `.nav-group-label`, `.nav-item`, `.nav-item.active` (brand color, `font-weight: 600`, ruler-tick `::before`), `.brand`, `.sidebar-foot`
  4. `.topbar` (sticky, mono-cased meta, `.seal`, `.crumb`, `.top-pill`, `.avatar` with PRO badge variant)
  5. `.body-wrap` (max 1180px, 56px padding)
  6. `.kicker` (mono uppercase with brand color and counter)
  7. `h1.display` (Manrope 700, large size with clamp), `.lede` (no italic — `font-weight: 500`)
  8. `.brief` textarea card with corner tag and counter
  9. `.submit` (dark CTA), `.btn`, `.btn-secondary`, `.btn-ghost`, `.btn-danger`, `.btn-icon`
  10. `.section-head` grid (num/title/rule/meta)
  11. `.cards-row`, `.card`, `.card:hover`, `.card.selected`, `.card .corner-tag`, `.card .corner-meta`, `.card-head`, `.ini` (mono initials avatar), `.card .name`, `.card-row`, `.status`, `.status.is-ready`, `.status.is-draft`, `.share-link`
  12. `.form-grid`, `.field`, `.field label`, `.input`, `.input:focus`, `.textarea`, `.select`
  13. `.kanban` (horizontal scroll flex), `.kanban-col` (320px fixed), `.kanban-col-head`, `.kanban-col-count`, `.kanban-list` (min-height 60px for empty drops), `.kanban-card`, `.kanban-card:hover`, `.kanban-ghost`, `.kanban-dragging`, `.kanban-quickadd`
  14. `.comment-thread`, `.comment`, `.comment-author`, `.comment-body`, `.comment-meta`, `.comment-composer`
  15. `.attach-grid` (4-col grid 120px), `.attach-img`, `.attach-file` (mono filename + size + delete)
  16. `.modal-backdrop` (fixed full-screen dim), `.modal` (max 600px, paper-2 bg), `.modal-head`, `.modal-body`, `.modal-actions`, `.modal-close`
  17. `.toast-root` fixed bottom-right, `.toast`, `.toast--success` (green), `.toast--error` (red), slide-in keyframes
  18. `.lightbox-backdrop`, `.lightbox-img`, `.lb-close`, `.lb-prev`, `.lb-next`
  19. `.tag`, `.tag .x` (FA xmark), `.tag-picker`, `.tag-picker .dropdown`
  20. Utilities: `.muted`, `.mono` (JetBrains Mono with letter-spacing), `.row`, `.col`, `.gap-{2,4,6,8,12}`, `.mt-{8,16,24,32}`, `.hidden`, `.sr-only`
  21. `@media (max-width: 960px)` collapses sidebar to top bar, kanban stays scrollable, cards stack 1-col

- [ ] **Step 2: Visual sanity** — start dev server, visit `/`; in devtools confirm: body font Manrope, no italic anywhere, no dot pattern background, brand color `#C2410C`. Take a screenshot for the design-handoff record.

- [ ] **Step 3: Commit**
```bash
git add public/assets/css/app.css
git commit -m "feat: full design-system CSS (Manrope, no italic, no dot pattern)"
```

---

## Task 9: UI primitives JS (modal/confirm/prompt/toast/lightbox) + Playwright

**Files:** Create `public/assets/js/ui.js`, `package.json`, `tests/e2e/playwright.config.ts`, `tests/e2e/ui.spec.ts`

- [ ] **Step 1: Initialize Playwright**
```bash
npm init -y
npm i -D @playwright/test
npx playwright install chromium
```

- [ ] **Step 2: `tests/e2e/playwright.config.ts`** — all Playwright commands MUST be invoked from the project root so relative paths (`public/`, `data/`) resolve correctly.
```ts
import { defineConfig } from '@playwright/test';
export default defineConfig({
  testDir: '.',
  workers: 1,                  // tests share an SQLite file; never run in parallel
  fullyParallel: false,
  use: { baseURL: 'http://localhost:8000', trace: 'on-first-retry' },
  webServer: {
    command: 'php -S localhost:8000 -t public public/index.php',
    cwd: '../..',               // project root, so relative DB_PATH resolves
    url: 'http://localhost:8000',
    reuseExistingServer: !process.env.CI,
  },
});
```

- [ ] **Step 3: `public/assets/js/ui.js`** — ES module exporting `UI = { modal, confirm, prompt, toast, lightbox }` and an `api(url, opts)` fetch wrapper. Implementation details:
  - `el(html)` helper creates a DOM node from an HTML string via `<template>`.
  - `UI.modal({title, body, actions})` appends a `.modal-backdrop > .modal` to `#modal-root` with focus trap (focus first button on open, restore on close), Escape/backdrop-click closes, `.modal-close` button closes. `body` can be string or DOM node. Returns `{ close(), node }`.
  - `UI.confirm(msg, {confirmLabel?, danger?})` returns Promise<bool>. Renders Cancel (ghost) + confirm button (`btn-danger` if danger else `submit`).
  - `UI.prompt(msg, {default?, placeholder?})` returns Promise<string|null>. Renders input that autofocuses, Enter submits.
  - `UI.toast(msg, type)` — `type ∈ {info, success, error}`. Appends to `#toast-root`, auto-removes in 4s, click to dismiss.
  - `UI.lightbox(images[], startIndex=0)` — backdrop + prev/next/close buttons + `<img>`. Esc closes, Arrow keys navigate, click backdrop closes.
  - `api(url, opts)` — sets `X-CSRF-Token` header from `<meta name=csrf-token>`, sets `Content-Type: application/json` if body is not FormData, on non-2xx parses `{error}` and calls `UI.toast(error, 'error')` then throws.
  - Final lines: `window.UI = UI; window.api = api;` so non-module scripts can use them.

- [ ] **Step 4: `tests/e2e/ui.spec.ts`**

For these tests we add a minimal **temporary** route `GET /ui-sandbox` that renders an empty page using `layouts/main.php` but skips the auth guard. This is needed because before Task 13 there's no auth guard, but later tests run with the guard installed and `/` would redirect to `/login`. The sandbox route is gated by `App::env('APP_DEBUG') === 'true'` so it never ships to production.

Add to `public/index.php` right after the smoke route registration:
```php
if (App::env('APP_DEBUG') === 'true') {
    $router->get('/ui-sandbox', 'Smoke@uiSandbox');
}
```
Add `uiSandbox()` method to `SmokeController`: renders `layouts/main` with empty `content`.

```ts
import { test, expect } from '@playwright/test';
test('toast appears', async ({ page }) => {
  await page.goto('/ui-sandbox');
  await page.evaluate(() => window.UI.toast('hello', 'success'));
  await expect(page.locator('.toast--success')).toHaveText('hello');
});
test('confirm resolves true on OK', async ({ page }) => {
  await page.goto('/ui-sandbox');
  const result = page.evaluate(() => window.UI.confirm('Sure?'));
  await page.click('.modal-actions .submit, .modal-actions .btn-danger');
  expect(await result).toBe(true);
});
```

- [ ] **Step 5: Run** `npx playwright test --config tests/e2e/playwright.config.ts` → expect 2 passed.

- [ ] **Step 6: Commit**
```bash
git add public/assets/js/ui.js tests/e2e package.json package-lock.json
git commit -m "feat: UI primitives and Playwright E2E setup"
```

---

## Task 10: Schema migrations for all tables

**Files:** Create `system/Database/Migrations.php`, modify `public/index.php`

- [ ] **Step 1: `system/Database/Migrations.php`** — single class with `run(SchemaBootstrap $boot)` method; each `ensure(...)` call creates one table per spec §5 with the exact columns listed. Tables in order: `users`, `projects`, `project_members`, `task_columns`, `tasks`, `tags`, `project_tag_map`, `task_tag_map`, `comments`, `attachments`, `notifications_log`.

For each table, follow this template (substitute name/columns):
```php
$boot->ensure('users', 1, function (\PDO $pdo) {
    $pdo->query("CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        name TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'member',
        status TEXT NOT NULL DEFAULT 'pending',
        telegram_chat_id TEXT,
        created_at TEXT NOT NULL,
        last_login_at TEXT
    )");
});
```

After each table: add necessary `CREATE INDEX` (per spec — comments by `(entity_type, entity_id, created_at)`, attachments by `(entity_type, entity_id)`).

- [ ] **Step 2: Wire in `public/index.php`** — after registering `schema` singleton:
```php
\App\Database\Migrations::run(App::make('schema'));
```

- [ ] **Step 3: Manual verify**
```bash
rm -f data/app.sqlite && rm -rf data/.schema
php -S localhost:8000 -t public public/index.php &
SERVER_PID=$!
sleep 1
curl -s http://localhost:8000/ > /dev/null
kill $SERVER_PID
sqlite3 data/app.sqlite ".tables"
```
Expected: all 11 table names listed.

- [ ] **Step 4: Commit**
```bash
git add system/Database/Migrations.php public/index.php
git commit -m "feat: schema migrations for all tables"
```

---

## Task 11: User repository and auth core

**Files:** Create `system/Repository/UserRepository.php`, `system/Auth/PasswordHasher.php`, `system/Auth/AuthManager.php`, `tests/unit/test_user_repo.php`, `tests/unit/test_auth.php`

- [ ] **Step 1: Write failing test** — `test_user_repo.php`:
  - `findByEmail` returns null when missing
  - `create($email, $hash, $name)` returns id; first-ever user is auto promoted to role=admin, status=approved; subsequent users role=member, status=pending
  - `approve($id)` sets status='approved'; `block($id)` sets status='blocked'; `setRole($id,'admin')` updates role
  - `listAll()` ordered by created_at DESC

`test_auth.php`:
  - `AuthManager::login('a@b','wrong')` returns null + fails counter
  - After 5 failed within 15min for same email → returns `'throttled'`
  - `login` on `pending` user returns `'pending'`
  - `login` on valid + approved → returns user array, updates `last_login_at`

- [ ] **Step 2: Run** — expect FAIL.

- [ ] **Step 3: Implement**

`system/Auth/PasswordHasher.php`:
```php
<?php
declare(strict_types=1);
namespace App\Auth;
final class PasswordHasher {
    public function hash(string $plain): string { return password_hash($plain, PASSWORD_BCRYPT); }
    public function verify(string $plain, string $hash): bool { return password_verify($plain, $hash); }
}
```

`system/Repository/UserRepository.php` — methods: `findByEmail(string $email): ?array`, `findById(int $id): ?array`, `create(string $email, string $hash, string $name): int` (inside transaction: count rows; if 0 → role=admin/status=approved; else role=member/status=pending; insert; return id), `approve(int $id): void`, `block(int $id): void`, `setRole(int $id, string $role): void`, `setTelegramChatId(int $id, ?string $chatId): void`, `touchLastLogin(int $id): void`, `listAll(): array`, `delete(int $id): void`. All take `\PDO` in constructor.

`system/Auth/AuthManager.php`:
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
    ) {}

    /** @return array|string user array on success, 'pending'|'blocked'|'throttled' or null on bad credentials */
    public function login(string $email, string $plain): array|string|null {
        if ($this->isThrottled($email)) return 'throttled';
        $user = $this->users->findByEmail($email);
        if (!$user || !$this->hasher->verify($plain, $user['password_hash'])) {
            $this->recordFail($email);
            return null;
        }
        if ($user['status'] === 'pending') return 'pending';
        if ($user['status'] === 'blocked') return 'blocked';
        $this->users->touchLastLogin((int)$user['id']);
        $this->session['user_id'] = (int)$user['id'];
        $this->resetFails($email);
        return $user;
    }

    public function logout(): void { unset($this->session['user_id']); }

    public function currentUser(): ?array {
        $id = $this->session['user_id'] ?? null;
        return $id ? $this->users->findById((int)$id) : null;
    }

    private function failsKey(string $email): string { return 'auth_fails_' . md5(strtolower($email)); }
    private function isThrottled(string $email): bool {
        $k = $this->failsKey($email);
        $f = $this->session[$k] ?? null;
        if (!$f) return false;
        if ((time() - $f['first']) > 900) { unset($this->session[$k]); return false; }
        return $f['count'] >= 5;
    }
    private function recordFail(string $email): void {
        $k = $this->failsKey($email);
        $f = $this->session[$k] ?? ['first' => time(), 'count' => 0];
        if ((time() - $f['first']) > 900) $f = ['first' => time(), 'count' => 0];
        $f['count']++;
        $this->session[$k] = $f;
    }
    private function resetFails(string $email): void { unset($this->session[$this->failsKey($email)]); }
}
```

- [ ] **Step 4: Run** — expect all tests pass.

- [ ] **Step 5: Commit**
```bash
git add system/Auth system/Repository tests/unit/test_user_repo.php tests/unit/test_auth.php
git commit -m "feat: user repository, password hasher, AuthManager with throttling"
```

---

## Task 12: Auth pages (login, register, pending, logout)

**Files:** Create `system/Controller/AuthController.php`, `views/layouts/auth.php`, `views/auth/{login,register,pending}.php`; modify `public/index.php` (wire `auth` singleton + routes)

- [ ] **Step 1: `views/layouts/auth.php`** — minimal centered card layout using design tokens, no sidebar. Slot `$content`. Must include the same head bits as `layouts/main.php`: `<link rel="stylesheet" href="/assets/css/app.css">`, FontAwesome CSS, `<meta name="csrf-token" content="<?= e($csrfToken) ?>">`, `#modal-root` / `#toast-root` / `#lightbox-root` divs, and `<script type="module" src="/assets/js/ui.js"></script>` — so login/register pages can use `UI.toast` for flash messages and any future AJAX from the auth flow.

- [ ] **Step 2: `views/auth/login.php`** — form `POST /login`, fields email/password, csrf hidden, displays `$error` flash if present, link to `/register`. Uses `.brief`-style card.

- [ ] **Step 3: `views/auth/register.php`** — form `POST /register`, fields name/email/password (min 8), csrf hidden, link to `/login`.

- [ ] **Step 4: `views/auth/pending.php`** — informational page: "Your account is awaiting admin approval. You'll be notified by the admin once approved." with FA icon `fa-hourglass-half`.

- [ ] **Step 5: Install `BaseController` and refactor dispatch to canonical signature**

Create `system/Controller/BaseController.php` exactly as in the "Conventions" section: `(Renderer $view, App $app, ?array $user = null)`.

Refactor `public/index.php` dispatcher to construct controllers with these three args only — drop the `(view, db, session, csrf)` shape from Task 6. Update `SmokeController` to extend `BaseController` (or delete it now — it's removed in Task 26 anyway; deleting now is cleaner).

Pass `$csrf` and `$store` access through the `App` container by adding two more singletons:
```php
App::singleton('csrf', fn() use ($csrf) => $csrf);
App::singleton('session', function () use (&$store) { return new class($store) { public function __construct(public array &$store) {} }; });
```
(Or simply make `$csrf` and `$store` accessible via `App::make('csrf')` and `App::make('session')->store`.)

The `$user` argument is null for guest routes (login/register/pending) and the resolved user array otherwise. Public route detection happens in dispatcher in Task 13; for this task pass `null` always (auth guard not installed yet).

- [ ] **Step 6: `system/Controller/AuthController.php`** — extends `BaseController`. In its own constructor pull `$this->auth = $app->make('auth')`, `$this->users = $app->make('users')`, `$this->csrf = $app->make('csrf')`, `$this->session = & $app->make('session')->store`.

Actions:
  - `loginForm(Request)` — if `$this->user` is non-null redirect `/`; else render `auth/login` in `auth.php` layout with `csrfToken` and optional `$error` from `$this->session['flash_error']` (consume-on-read: unset after pulling).
  - `login(Request)` — read email/password from `$req->post`; call `$this->auth->login(...)`. Branch on result: null → flash "Невірний email або пароль", redirect `/login`; `'throttled'` → flash "Забагато спроб. Спробуйте за 15 хв"; `'pending'` → redirect `/pending`; `'blocked'` → flash "Обліковий запис заблоковано"; array (success) → `$this->csrf->regenerate()`; redirect `/`.
  - `registerForm(Request)` — render `auth/register`.
  - `register(Request)` — validate (email format, password >= 8, name not blank). On error flash + back. On success: hash password, `$this->users->create(...)`. If returned user is the first-ever (auto-admin), log them in immediately and redirect `/`. Otherwise redirect `/pending`. **The `user.registered` event is NOT fired here yet** — `EventBus` arrives in Task 19, which retrofits this action to fire the event after the redirect-or-create.
  - `pending(Request)` — render `auth/pending`.
  - `logout(Request)` — `$this->auth->logout()`; `$this->csrf->regenerate()`; redirect `/login`.

- [ ] **Step 7: Wire singletons in `public/index.php`**:
```php
App::singleton('users', fn() => new \App\Repository\UserRepository(App::make('db')));
App::singleton('hasher', fn() => new \App\Auth\PasswordHasher());
App::singleton('auth', fn() use (&$store) => new \App\Auth\AuthManager(App::make('users'), App::make('hasher'), $store));
App::singleton('csrf', fn() use ($csrf) => $csrf);
App::singleton('session', function () use (&$store) { return new class($store) { public array $store; public function __construct(array &$s) { $this->store = &$s; } }; });
```

Add routes:
```php
$router->get('/login', 'Auth@loginForm');
$router->post('/login', 'Auth@login');
$router->get('/register', 'Auth@registerForm');
$router->post('/register', 'Auth@register');
$router->get('/pending', 'Auth@pending');
$router->post('/logout', 'Auth@logout');
```

- [ ] **Step 8: E2E test** — `tests/e2e/auth.spec.ts`:
```ts
import { test, expect } from '@playwright/test';
import fs from 'fs';
test.describe.configure({ mode: 'serial' });   // tests in this file share DB state
test.beforeAll(() => {
  fs.rmSync('data/app.sqlite', { force: true });
  fs.rmSync('data/.schema', { recursive: true, force: true });
});
test('first user becomes admin and logs in', async ({ page }) => {
  await page.goto('/register');
  await page.fill('input[name=name]', 'Admin');
  await page.fill('input[name=email]', 'admin@example.com');
  await page.fill('input[name=password]', 'password123');
  await page.click('button[type=submit]');
  await expect(page).toHaveURL('/');
});
test('second user goes pending', async ({ page }) => {
  await page.goto('/register');
  await page.fill('input[name=name]', 'User');
  await page.fill('input[name=email]', 'u@example.com');
  await page.fill('input[name=password]', 'password123');
  await page.click('button[type=submit]');
  await expect(page).toHaveURL('/pending');
});
```

- [ ] **Step 9: Run** `npx playwright test` — expect auth tests pass.

- [ ] **Step 10: Commit**
```bash
git add system/Controller system/Controller/BaseController.php system/Controller/AuthController.php views public/index.php tests/e2e/auth.spec.ts
git commit -m "feat: BaseController canonical signature + auth pages with throttling"
```

---

## Task 13: Auth middleware + sidebar/topbar populated

**Files:** Create `system/Http/AuthGuard.php`; modify `public/index.php`, `views/partials/sidebar.php`, `views/partials/topbar.php`; refactor controller dispatch

- [ ] **Step 1: `system/Http/AuthGuard.php`** — class with `require(Request $req, App $app): array` that returns current user array or 302-redirects to `/login` + `exit`. `requireAdmin($user)` returns 403 page if `$user['role'] !== 'admin'`. `requireApproved($user)` redirects pending users to `/pending`.

- [ ] **Step 2: Modify `public/index.php`** — after CSRF check and before dispatching, define a list of public routes (`/login`, `/register`, `/pending`, `POST /login`, `POST /register`); for any other route call `AuthGuard::require(...)`. Pass the resolved user (or null) into the controller as the 3rd canonical constructor arg `?array $user` — slot already exists in `BaseController` from Task 12, no new arg.

- [ ] **Step 3: `views/partials/sidebar.php`** — full editorial sidebar:
  - Brand block (Otack Tasks)
  - Nav group "WORKSPACE" with items: Dashboard (`/`), Projects (`/projects`); add "Users" if `$user['role'] === 'admin'`
  - Nav group "ACCOUNT": Profile (`/profile`), Logout (POST form with csrf to `/logout` styled as a nav-item)
  - Active class on current path (passed in as `$activeNav` from controller)

- [ ] **Step 4: `views/partials/topbar.php`** — seal "Otack Tasks", crumb showing `$crumb` (e.g. "01 · Dashboard"), right side: user `.avatar` with first letter of name. No language pill, no inbox pill (cut).

- [ ] **Step 5: Visit `/` while logged out** — expect redirect to `/login`. While logged in — expect dashboard placeholder with sidebar/topbar populated.

- [ ] **Step 6: Commit**
```bash
git add system/Http/AuthGuard.php public/index.php views/partials system/Controller
git commit -m "feat: auth guard middleware + populated sidebar/topbar"
```

---

## Task 14: Users admin page (list, approve, block, role, safe delete)

**Files:** Create `system/Controller/UserController.php`, `views/users/index.php`; add routes; add E2E

- [ ] **Step 1: `views/users/index.php`** — section head "Users", card grid rows: avatar, name, email, status pill, role pill, created_at, action buttons (Approve, Block, Make admin/Make member, Delete). Buttons fire AJAX `POST /users/{id}/...` via `api()` and on success call `UI.toast` + reload row. Pending users get a brand-color corner-tag "PENDING".

- [ ] **Step 2: `system/Controller/UserController.php`** — admin-only via `AuthGuard::requireAdmin($this->user)`:
  - `index(Request)` — list users, render with `activeNav='users'`, crumb "Users".
  - `approve($id)` — `users->approve($id)`; fire `user.approved` event; JSON `{ok:true}`.
  - `block($id)`, `setRole($id)` with whitelist `['admin','member']`.
  - `delete($id)` — refuse if `$id === current user`, refuse if `UserRepository::hasRelatedData($id)` (any project ownership / comments / attachments referencing this user); UI shows "Block" as the safe alternative when delete is blocked.

  Add `UserRepository::hasRelatedData(int $id): bool` — runs `SELECT 1 FROM projects WHERE created_by=? UNION ALL SELECT 1 FROM comments WHERE user_id=? UNION ALL SELECT 1 FROM attachments WHERE uploaded_by=? LIMIT 1`.

- [ ] **Step 3: Routes**
```php
$router->get('/users', 'User@index');
$router->post('/users/{id}/approve', 'User@approve');
$router->post('/users/{id}/block', 'User@block');
$router->post('/users/{id}/role', 'User@setRole');
$router->post('/users/{id}/delete', 'User@delete');
```

- [ ] **Step 4: E2E** — `tests/e2e/users.spec.ts`: admin logs in, second user registers (separate browser context), admin approves them, second user can then log in.

- [ ] **Step 5: Run** `npx playwright test tests/e2e/users.spec.ts` — expect pass.

- [ ] **Step 6: Commit**
```bash
git add system/Controller/UserController.php system/Repository/UserRepository.php views/users public/index.php tests/e2e/users.spec.ts
git commit -m "feat: users admin (list, approve, block, role, safe delete)"
```

---

## Task 15: Profile page

**Files:** Create `system/Controller/ProfileController.php`, `views/profile/show.php`

- [ ] **Step 1: `views/profile/show.php`** — two `.brief`-style cards: (1) Name change — POST `/profile`, field `name`. (2) Password change — POST `/profile/password`, fields `current`/`new`/`confirm`. Static muted note: "Notifications go to a shared Telegram channel configured by admin."

- [ ] **Step 2: `system/Controller/ProfileController.php`** — actions `show`, `update` (change name; flash success; redirect back), `updatePassword` (verify current via `PasswordHasher`, check `new === confirm` and length≥8, persist new hash via new repo method `UserRepository::updatePassword(int $id, string $hash): void`).

- [ ] **Step 3: Routes**
```php
$router->get('/profile', 'Profile@show');
$router->post('/profile', 'Profile@update');
$router->post('/profile/password', 'Profile@updatePassword');
```

- [ ] **Step 4: Commit**
```bash
git add system/Controller/ProfileController.php views/profile public/index.php
git commit -m "feat: profile page (name + password)"
```

---

## Task 16: Project repository + Projects CRUD pages

**Files:** Create `system/Repository/{ProjectRepository,ProjectMemberRepository,TaskColumnRepository}.php`, `system/Controller/ProjectController.php`, `views/projects/{index,form,show}.php`, `tests/unit/test_project_repo.php`

- [ ] **Step 1: Write failing test** — `test_project_repo.php`:
  - `ProjectRepository::create($name, $description, $createdBy)` returns id; slugifies (`'My Project'` → `'my-project'`, ensures uniqueness via numeric suffix `-2`, `-3`).
  - `ProjectMemberRepository::add($projectId, $userId, $role='member')` and `list($projectId)`; `remove($projectId, $userId)`; `isMember`, `isOwner`.
  - `TaskColumnRepository::seedDefaults($projectId)` creates 3 columns: `To Do` color `#5A4E3F` pos 0; `In Progress` color `#C2410C` pos 1; `Done` color `#4D6840` pos 2 `is_done=1`.

- [ ] **Step 2: Run** — expect FAIL.

- [ ] **Step 3: Implement**

`system/Repository/ProjectRepository.php` — methods: `create(string $name, ?string $description, int $createdBy): int` (transaction; slug; insert; return id); `findById(int $id): ?array`; `findBySlug(string $slug): ?array`; `update(int $id, array $fields): void` (whitelist name/description/status); `listForUser(int $userId, bool $isAdmin): array` (admins: all; others: `WHERE EXISTS in project_members`); `delete(int $id): void`; `slugify(string $name): string` (lowercase; transliterate cyrillic to latin via a fixed map; replace non `[a-z0-9]+` with `-`; trim; uniqueness loop appending `-N`).

`system/Repository/ProjectMemberRepository.php` — `add(int $projectId, int $userId, string $role='member')`, `remove($projectId, $userId)`, `list($projectId): array` (join users for name/email), `isMember(int $projectId, int $userId): bool`, `isOwner(int $projectId, int $userId): bool`.

`system/Repository/TaskColumnRepository.php` — `seedDefaults(int $projectId): void`, `listForProject(int $projectId): array` ordered by `position`, `create($projectId, $name, $color): int` (position = max+1), `update(int $id, array $fields)` (whitelist name/color/position/is_done), `delete(int $id, ?int $moveTasksTo)` (transaction: if `moveTasksTo` reassign tasks then delete; else fail if column has tasks), `reorder(int $projectId, int[] $orderedIds)`.

- [ ] **Step 4: Run** — expect project repo tests pass.

- [ ] **Step 5: `system/Controller/ProjectController.php`** actions:
  - `index(Request)` — list user's projects via `listForUser($this->user['id'], $this->user['role']==='admin')`; render `projects/index` with `.cards-row`.
  - `createForm(Request)` — render `projects/form` mode=create.
  - `create(Request)` — validate name not blank; transaction: create project, add creator as owner in `project_members`, seed 3 default columns; redirect `/projects/{id}`; fire `project.created` event.
  - `show(Request, ['id'=>$id])` — fetch project; assert membership; fetch members + tags + columns + tasks (grouped by column); render `projects/show` with tabs (Board default, Overview as alt).
  - `editForm`, `update` — only owner or admin.
  - `delete` — owner or admin; cascades.

- [ ] **Step 6: Templates**
  - `projects/index.php` — section head + `.cards-row` of cards (corner-tag `P · 0N`, initials avatar based on first 2 chars of name, status pill, "Open" link). "+ New project" button → `/projects/new`.
  - `projects/form.php` — `.brief`-style name input + textarea description + submit "Create"/"Save". In edit mode include a member-picker (autocomplete from approved users) — picker JS deferred to Task 19.
  - `projects/show.php` — tab bar (Board / Overview); Board renders the kanban container shell (filled by Task 18); Overview reuses partials `partials/{members,tags,attachments,comments}.php` (added in subsequent tasks).

- [ ] **Step 7: Routes**
```php
$router->get('/projects', 'Project@index');
$router->get('/projects/new', 'Project@createForm');
$router->post('/projects', 'Project@create');
$router->get('/projects/{id}', 'Project@show');
$router->get('/projects/{id}/edit', 'Project@editForm');
$router->post('/projects/{id}', 'Project@update');
$router->post('/projects/{id}/delete', 'Project@delete');
```

- [ ] **Step 8: E2E** — `tests/e2e/projects.spec.ts`: admin creates a project, sees it in list, opens it, asserts 3 default columns are visible (`To Do`, `In Progress`, `Done`).

- [ ] **Step 9: Commit**
```bash
git add system/Repository system/Controller/ProjectController.php views/projects tests public/index.php
git commit -m "feat: projects CRUD with default columns seeding"
```

---

## Task 17: Task repository + create/delete API

**Files:** Create `system/Repository/TaskRepository.php`, `tests/unit/test_task_repo.php`, `tests/unit/test_position_math.php`; modify `system/Controller/TaskController.php`

- [ ] **Step 1: Write failing test**

`test_position_math.php`:
  - `TaskRepository::computePosition(null, null)` → `1024.0`
  - `computePosition(10, null)` → `1034.0`
  - `computePosition(null, 10)` → `-1014.0`
  - `computePosition(10, 20)` → `15.0`

`test_task_repo.php`:
  - `create($projectId, $columnId, $title, $createdBy)` returns id with position = (max position in column) + 1024.
  - `listForProject($projectId)` returns `[columnId => [tasks ordered by position ASC]]`.
  - `move($id, $newColumnId, $newPosition)` updates both fields and bumps `updated_at`.

- [ ] **Step 2: Run** — expect FAIL.

- [ ] **Step 3: Implement** `system/Repository/TaskRepository.php`:
  - `public static function computePosition(?float $prev, ?float $next): float` — pure math per spec.
  - `create(int $projectId, int $columnId, string $title, int $createdBy, ?string $description=null, ?int $assigneeId=null, ?string $dueDate=null): int` — computes bottom position via `MAX(position)` in column.
  - `findById(int $id): ?array`
  - `listForProject(int $projectId): array` returns `[columnId => […]]`.
  - `update(int $id, array $fields): void` — whitelist title/description/column_id/position/assignee_id/due_date; touch `updated_at`.
  - `move(int $id, int $newColumnId, float $newPosition): void`
  - `delete(int $id): void`

- [ ] **Step 4: Run** — expect task repo tests pass.

- [ ] **Step 5: Extend `system/Controller/TaskController.php`**:
  - `create(Request, ['id'=>$projectId])` — quick-add: parse JSON body, read `column_id` and `title`; verify membership; create; fire `task.created`; return JSON `{ok:true, task: {id, title, column_id, position}}`.
  - `delete(Request, ['id'=>$taskId])` — verify membership via project lookup; delete; return `{ok:true}`.

- [ ] **Step 6: Routes**
```php
$router->post('/projects/{id}/tasks', 'Task@create');
$router->post('/tasks/{id}/delete', 'Task@delete');
```

- [ ] **Step 7: Commit**
```bash
git add system/Repository/TaskRepository.php system/Controller/TaskController.php tests/unit/test_task_repo.php tests/unit/test_position_math.php public/index.php
git commit -m "feat: task repository with midpoint positions and create/delete API"
```

---

## Task 18: Kanban board UI with DnD

**Files:** Create `public/assets/js/kanban.js`; extend `views/projects/show.php` (Board tab); add `Task@move` action; add `tests/e2e/kanban.spec.ts`

- [ ] **Step 1: Board markup in `views/projects/show.php`** — render the kanban as PHP-escaped HTML:

```php
<div class="kanban" data-project-id="<?= (int)$project['id'] ?>">
  <?php foreach ($columns as $col): ?>
    <div class="kanban-col" data-column-id="<?= (int)$col['id'] ?>">
      <div class="kanban-col-head">
        <span class="dot" style="background: <?= e($col['color']) ?>"></span>
        <span class="name"><?= e($col['name']) ?></span>
        <span class="kanban-col-count"><?= count($tasksByCol[$col['id']] ?? []) ?></span>
        <button type="button" class="btn-icon col-settings" data-column-id="<?= (int)$col['id'] ?>" aria-label="Column settings"><i class="fa-solid fa-ellipsis-vertical"></i></button>
      </div>
      <div class="kanban-list" data-column-id="<?= (int)$col['id'] ?>">
        <?php foreach ($tasksByCol[$col['id']] ?? [] as $t): ?>
          <div class="kanban-card" data-task-id="<?= (int)$t['id'] ?>" data-task-url="/tasks/<?= (int)$t['id'] ?>" data-position="<?= (float)$t['position'] ?>">
            <div class="title"><?= e($t['title']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <form class="kanban-quickadd" data-column-id="<?= (int)$col['id'] ?>">
        <input type="text" name="title" placeholder="+ Add task" maxlength="200">
      </form>
    </div>
  <?php endforeach; ?>
  <button type="button" class="btn-secondary add-column"><i class="fa-solid fa-plus"></i> Column</button>
</div>
<script src="/assets/vendor/sortable.min.js"></script>
<script type="module" src="/assets/js/kanban.js"></script>
```

- [ ] **Step 2: `public/assets/js/kanban.js`** — ES module. Build cards via `document.createElement` + `textContent` only (no string-HTML insertion) to avoid XSS by construction.

Module shape:
```js
import { api, UI } from './ui.js';

const root = document.querySelector('.kanban');
if (root) initKanban(root);

function initKanban(root) {
  root.querySelectorAll('.kanban-list').forEach(initSortable);
  initCardClick(root);
  initQuickAdd(root);
  initAddColumn(root);
  initColumnSettings(root);
}

function midpoint(prev, next) {
  if (prev == null && next == null) return 1024;
  if (prev == null) return next - 1024;
  if (next == null) return prev + 1024;
  return (prev + next) / 2;
}

function recount(root) {
  root.querySelectorAll('.kanban-col').forEach(col => {
    col.querySelector('.kanban-col-count').textContent =
      col.querySelector('.kanban-list').children.length;
  });
}

function buildCard(task) {
  const card = document.createElement('div');
  card.className = 'kanban-card';
  card.dataset.taskId = task.id;
  card.dataset.taskUrl = '/tasks/' + task.id;
  card.dataset.position = task.position;
  const title = document.createElement('div');
  title.className = 'title';
  title.textContent = task.title;
  card.appendChild(title);
  return card;
}

function initSortable(list) {
  window.Sortable.create(list, {
    group: 'kanban',
    animation: 150,
    ghostClass: 'kanban-ghost',
    dragClass: 'kanban-dragging',
    onEnd: async (evt) => {
      const card = evt.item;
      const newCol = evt.to;
      const oldCol = evt.from;
      const id = +card.dataset.taskId;
      const columnId = +newCol.dataset.columnId;
      const prev = card.previousElementSibling?.dataset.position;
      const next = card.nextElementSibling?.dataset.position;
      const position = midpoint(prev ? +prev : null, next ? +next : null);
      card.dataset.position = position;
      try {
        await api('/api/tasks/' + id + '/move', {
          method: 'POST',
          body: JSON.stringify({ column_id: columnId, position }),
        });
        recount(root);
      } catch {
        oldCol.appendChild(card); // rollback
        recount(root);
      }
    },
  });
}

function initCardClick(root) {
  let downAt = null;
  root.addEventListener('pointerdown', e => {
    const card = e.target.closest('.kanban-card');
    if (!card) return;
    downAt = { x: e.clientX, y: e.clientY, card };
  });
  root.addEventListener('pointerup', e => {
    if (!downAt) return;
    const dx = Math.abs(e.clientX - downAt.x);
    const dy = Math.abs(e.clientY - downAt.y);
    if (dx < 5 && dy < 5) {
      const url = downAt.card.dataset.taskUrl;
      if (url) window.open(url, '_blank', 'noopener');
    }
    downAt = null;
  });
  root.addEventListener('auxclick', e => {
    const card = e.target.closest('.kanban-card');
    if (!card || e.button !== 1) return;
    window.open(card.dataset.taskUrl, '_blank', 'noopener');
  });
}

function initQuickAdd(root) {
  root.querySelectorAll('.kanban-quickadd').forEach(form => {
    form.addEventListener('submit', async e => {
      e.preventDefault();
      const input = form.querySelector('input[name=title]');
      const title = input.value.trim();
      if (!title) return;
      const projectId = root.dataset.projectId;
      const columnId = form.dataset.columnId;
      try {
        const res = await api('/projects/' + projectId + '/tasks', {
          method: 'POST',
          body: JSON.stringify({ column_id: +columnId, title }),
        });
        form.closest('.kanban-col').querySelector('.kanban-list').appendChild(buildCard(res.task));
        input.value = '';
        recount(root);
      } catch {}
    });
  });
}

function initAddColumn(root) {
  root.querySelector('.add-column')?.addEventListener('click', async () => {
    const name = await UI.prompt('Column name');
    if (!name) return;
    await api('/api/columns', {
      method: 'POST',
      body: JSON.stringify({ project_id: +root.dataset.projectId, name }),
    });
    location.reload();
  });
}

function initColumnSettings(root) {
  root.querySelectorAll('.col-settings').forEach(btn => {
    btn.addEventListener('click', () => openColumnSettings(+btn.dataset.columnId));
  });
}

async function openColumnSettings(columnId) {
  // Renders a modal with rename + color + delete-with-move-to.
  // Implemented fully in Task 24.
}
```

- [ ] **Step 3: Add `Task@move` action in `TaskController`**

```php
public function move(Request $req, array $params): void {
    $taskId = (int)$params['id'];
    $task = $this->tasks->findById($taskId);
    if (!$task) { Response::json(['error' => 'Not found'], 404); return; }
    $this->assertMember((int)$task['project_id']);
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $columnId = (int)($data['column_id'] ?? 0);
    $position = (float)($data['position'] ?? 0);
    if (!$columnId) { Response::json(['error' => 'column_id required'], 422); return; }
    $this->tasks->move($taskId, $columnId, $position);
    $this->events->fire('task.status_changed', [
        'task_id' => $taskId,
        'old_column_id' => (int)$task['column_id'],
        'new_column_id' => $columnId,
        'actor_id' => (int)$this->user['id'],
    ]);
    Response::json(['ok' => true]);
}
```

`assertMember(int $projectId)` — helper in `TaskController` that 403-JSON if not member/admin.

- [ ] **Step 4: Route**
```php
$router->post('/api/tasks/{id}/move', 'Task@move');
```

- [ ] **Step 5: E2E** — `tests/e2e/kanban.spec.ts`: admin creates a project, adds 2 tasks via quick-add into "To Do", drags one to "Done" using Playwright's `page.dragAndDrop` (selector: card → target list), reloads, asserts the dragged task is now in the "Done" column.

- [ ] **Step 6: Run** `npx playwright test tests/e2e/kanban.spec.ts` — expect pass.

- [ ] **Step 7: Commit**
```bash
git add public/assets/js/kanban.js views/projects/show.php system/Controller/TaskController.php public/index.php tests/e2e/kanban.spec.ts
git commit -m "feat: kanban board with SortableJS DnD and quick-add"
```

---

## Task 19: Project member management + EventBus stub

**Files:** Create `system/Service/EventBus.php`, `public/assets/js/members.js`; add member endpoints; modify `views/partials/members.php`

- [ ] **Step 1: `system/Service/EventBus.php`** — tiny pub-sub:
```php
<?php
declare(strict_types=1);
namespace App\Service;
final class EventBus {
    /** @var array<string, callable[]> */
    private array $listeners = [];
    public function on(string $event, callable $fn): void { $this->listeners[$event][] = $fn; }
    public function fire(string $event, array $payload): void {
        foreach ($this->listeners[$event] ?? [] as $fn) {
            try { $fn($payload); } catch (\Throwable $e) { error_log("EventBus[$event]: " . $e->getMessage()); }
        }
    }
}
```

Register as singleton in `index.php`: `App::singleton('events', fn() => new \App\Service\EventBus());`. Controllers pull it via `$this->app->make('events')` only where they need to fire.

- [ ] **Step 1b: Retrofit `AuthController::register`** — after the user is created and **before** the redirect, fire the event the spec promised:
```php
$this->app->make('events')->fire('user.registered', [
    'user_id' => $newUserId,
    'name'    => $name,
    'email'   => $email,
]);
```
This was deliberately deferred from Task 12; without it the Telegram pipeline in Task 25 has no `user.registered` source.

- [ ] **Step 2: `views/partials/members.php`** — reusable partial that takes `$projectId`, `$members`, `$allUsers` (approved only), `$canEdit`. Renders member chips with avatar + name + remove button; below: an autocomplete input that lists users not yet in the project.

- [ ] **Step 3: `public/assets/js/members.js`** — wires autocomplete: on input filter `$allUsers` shown in a dropdown, click adds via `POST /api/projects/{id}/members` with `{user_id}`; remove button calls `POST /api/projects/{id}/members/{userId}/delete`.

- [ ] **Step 4: `ProjectController` actions:**
  - `addMember(Request, ['id'=>$pid])` — owner/admin; verify user is approved; `ProjectMemberRepository::add`; JSON `{ok:true}`.
  - `removeMember(Request, ['id'=>$pid, 'userId'=>$uid])` — owner/admin; refuse if removing the last owner; `remove`; JSON `{ok:true}`.

- [ ] **Step 5: Routes**
```php
$router->post('/api/projects/{id}/members', 'Project@addMember');
$router->post('/api/projects/{id}/members/{userId}/delete', 'Project@removeMember');
```

- [ ] **Step 6: Wire partial into `projects/show.php` Overview tab** and pass `$members`, `$allUsers` (filtered approved), `$canEdit = (current user is owner or admin)`.

- [ ] **Step 7: Commit**
```bash
git add system/Service/EventBus.php views/partials/members.php public/assets/js/members.js system/Controller/ProjectController.php public/index.php views/projects/show.php
git commit -m "feat: project member management + EventBus stub"
```

---

## Task 20: Comments (project and task), shared partial

**Files:** Create `system/Repository/CommentRepository.php`, `system/Controller/CommentController.php`, `system/Service/Markdown.php`, `views/partials/comment-thread.php`, `public/assets/js/comments.js`, `tests/unit/test_markdown.php`

- [ ] **Step 1: Markdown failing tests** — `test_markdown.php`:
  - `Markdown::render('**bold**')` → `<p><strong>bold</strong></p>`
  - inline `` `code` `` wraps in `<code>`
  - `[link](https://example.com)` produces `<a href="https://example.com" rel="noopener">link</a>`
  - non-http/mailto links are rendered as plain text (security)
  - `*single asterisks*` rendered literally (no italic)
  - fenced ` ``` ` produces `<pre><code>...</code></pre>`
  - `<script>` in source escaped, never executed
  - `- a\n- b` → `<ul><li>a</li><li>b</li></ul>`
  - `1. a\n2. b` → `<ol><li>a</li><li>b</li></ol>`

- [ ] **Step 2: Implement `system/Service/Markdown.php`** — `render(string $src): string`. Algorithm:
  1. `$src = htmlspecialchars($src, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8')` first — this handles all HTML escaping.
  2. Split into blocks by blank lines.
  3. For each block: detect fenced code (` ``` `) → wrap in `<pre><code>`. Detect `^- ` lines → `<ul>`. Detect `^\d+\. ` → `<ol>`. Else `<p>...</p>`.
  4. Inside non-code blocks apply inline rules: `\*\*(.+?)\*\*` → `<strong>$1</strong>`; `` `([^`]+)` `` → `<code>$1</code>`; `\[([^\]]+)\]\(([^)]+)\)` — validate URL starts with `https://`, `http://`, or `mailto:`; if yes render `<a href="$2" rel="noopener">$1</a>`; if no render literal `[text](url)`.
  5. Note: input was already escaped in step 1, so we never inject raw user content.

- [ ] **Step 3: Run** — expect markdown tests pass.

- [ ] **Step 4: `system/Repository/CommentRepository.php`** — methods: `create(string $entityType, int $entityId, int $userId, string $body): int`, `listFor(string $entityType, int $entityId): array` (join users for author name, oldest first), `delete(int $id): void`, `findById(int $id): ?array`.

- [ ] **Step 5: `views/partials/comment-thread.php`** — takes `$entityType`, `$entityId`, `$comments`, `$canPost`. Renders thread + composer textarea + post button. Container has `data-entity-type` and `data-entity-id` for the JS.

- [ ] **Step 6: `public/assets/js/comments.js`** — on submit, POST `/api/comments` with `{entity_type, entity_id, body}`; on success render the new comment node at bottom (DOM-built, not innerHTML); delete button → `UI.confirm` → `POST /api/comments/{id}/delete`; on success remove node.

- [ ] **Step 7: `system/Controller/CommentController.php`** — actions:
  - `create(Request)` — parse JSON body; assert membership of project/task; insert; fire `comment.created`; return JSON with the new comment (id, body html-rendered, author name, created_at).
  - `delete($id)` — author or admin only; delete.

- [ ] **Step 8: Routes**
```php
$router->post('/api/comments', 'Comment@create');
$router->post('/api/comments/{id}/delete', 'Comment@delete');
```

- [ ] **Step 9: Include comment-thread partial in `projects/show.php` Overview tab** (entity_type='project', entity_id=$project['id']). Will also be used by Task 22 (task page).

- [ ] **Step 10: E2E** — `tests/e2e/comments.spec.ts`: create a project, post a comment "**hello**", reload, assert `<strong>hello</strong>` is present in the thread.

- [ ] **Step 11: Commit**
```bash
git add system/Repository/CommentRepository.php system/Controller/CommentController.php system/Service/Markdown.php views/partials/comment-thread.php public/assets/js/comments.js tests public/index.php views/projects/show.php
git commit -m "feat: comments (shared for project and task) with hand-rolled markdown"
```

---

## Task 21: Attachments with image lightbox

**Files:** Create `system/Repository/AttachmentRepository.php`, `system/Service/FileUploader.php`, `system/Controller/AttachmentController.php`, `views/partials/attachment-list.php`, `public/assets/js/attachments.js`, `tests/unit/test_file_uploader.php`, `public/uploads/.htaccess`

- [ ] **Step 0: Add upload-limit meta tags to `views/layouts/main.php`** — in `<head>`:
```php
<meta name="upload-max-image" content="<?= e(\App\App::env('UPLOAD_MAX_IMAGE', '5242880')) ?>">
<meta name="upload-max-file"  content="<?= e(\App\App::env('UPLOAD_MAX_FILE',  '52428800')) ?>">
```
The JS in step 9 reads these for client-side pre-check. (No need to add to `layouts/auth.php` — uploads only happen on authenticated pages.)

- [ ] **Step 1: `public/uploads/.htaccess`** — disable PHP execution, force download for non-image types:
```apache
<FilesMatch "\.(php|phtml|phar|pl|py|cgi|sh)$">
  Require all denied
</FilesMatch>
<FilesMatch "\.(jpg|jpeg|png|gif|webp)$">
  Header set Content-Disposition "inline"
</FilesMatch>
<FilesMatch "\.(pdf|doc|docx|xls|xlsx|zip|rar|7z|txt|csv|json|xml|mp4|mp3)$">
  Header set Content-Disposition "attachment"
</FilesMatch>
```

- [ ] **Step 2: Failing test** `test_file_uploader.php`:
  - `FileUploader::validate(['type'=>'image/jpeg','size'=>1_000_000])` returns null (valid)
  - `validate(['type'=>'image/jpeg','size'=>6_000_000])` returns `'Image exceeds 5 MB'`
  - `validate(['type'=>'application/pdf','size'=>60_000_000])` returns `'File exceeds 50 MB'`
  - `validate(['type'=>'image/svg+xml','size'=>1000])` returns `'SVG uploads are not allowed'`
  - `validate(['type'=>'application/x-php','size'=>1000])` returns `'File type not allowed'`

- [ ] **Step 3: Implement `system/Service/FileUploader.php`**:
  - `validate(array $file): ?string` — returns error string or null. Allowed mime prefixes/types listed explicitly: images (`image/jpeg`, `image/png`, `image/gif`, `image/webp`); docs/archives (`application/pdf`, `application/zip`, `application/x-7z-compressed`, `application/x-rar-compressed`, `application/vnd.openxmlformats-*`, `application/msword`, `application/vnd.ms-excel`, `text/plain`, `text/csv`, `application/json`, `application/xml`, `video/mp4`, `audio/mpeg`). Anything else → "File type not allowed". SVG rejected explicitly.
  - `isImage(string $mime): bool` — `in_array($mime, ['image/jpeg','image/png','image/gif','image/webp'], true)`.
  - `store(array $file): array` — returns `{filename, original_name, mime, size, is_image}`. Generates a UUID, builds path `uploads/YYYY/MM/{uuid}.{ext}`, ensures directory, moves via `move_uploaded_file`. Mime sniffed via `finfo_file` (not the client-provided `$file['type']`).

- [ ] **Step 4: Run** — expect file uploader tests pass.

- [ ] **Step 5: `system/Repository/AttachmentRepository.php`** — methods: `create(array $payload): int` (entity_type, entity_id, filename, original_name, mime, size, is_image, uploaded_by), `listFor(string $entityType, int $entityId): array`, `findById(int $id): ?array`, `delete(int $id): void`.

- [ ] **Step 6: `system/Controller/AttachmentController.php`**:
  - `upload(Request)` — multipart; expects `entity_type`, `entity_id` in POST and `file` in FILES; verify membership; validate via FileUploader; store; insert row; return JSON `{ok:true, attachment: {…}}`.
  - `delete($id)` — uploader or admin; unlink file from disk; delete row; JSON ok.

- [ ] **Step 7: Routes**
```php
$router->post('/api/attachments', 'Attachment@upload');
$router->post('/api/attachments/{id}/delete', 'Attachment@delete');
```

- [ ] **Step 8: `views/partials/attachment-list.php`** — takes `$entityType`, `$entityId`, `$attachments`, `$canEdit`. Renders `.attach-grid`: images as 120px thumbnails, non-images as FA icon + filename + `fmt_size`. Click image → `UI.lightbox([urls], i)`. Delete button with `UI.confirm`. Below grid: drop zone or `<input type="file" multiple>` (drag-drop is enhancement).

- [ ] **Step 9: `public/assets/js/attachments.js`** — on file select or drop: for each file POST as FormData via `api()`; append a new tile node (DOM-built) on success; delete handler calls API and removes node. Pre-check file size client-side using same limits read from `<meta name=upload-max-image>` and `<meta name=upload-max-file>` injected in `layouts/main.php` from `App::env(...)`.

- [ ] **Step 10: Include partial in `projects/show.php` Overview tab.**

- [ ] **Step 11: E2E** — `tests/e2e/attachments.spec.ts`: upload a small PNG via `setInputFiles`, assert thumbnail appears, click → lightbox visible; upload an over-limit file → toast error.

- [ ] **Step 12: Commit**
```bash
git add system/Repository/AttachmentRepository.php system/Service/FileUploader.php system/Controller/AttachmentController.php views/partials/attachment-list.php public/assets/js/attachments.js public/uploads/.htaccess tests public/index.php views/projects/show.php
git commit -m "feat: attachments with image lightbox; 5 MB images, 50 MB files; SVG blocked"
```

---

## Task 22: Task page (standalone tab) with full details

**Files:** Create `views/tasks/show.php`; add `Task@show`, `Task@update`; `system/Controller/TaskController.php`; `public/assets/js/task-page.js`

- [ ] **Step 1: `Task@show($id)`** — fetch task; resolve project; assert membership; fetch column list, project members (for assignee picker), tags (task scope) with mapping, comments (entity_type=task), attachments (entity_type=task); render `tasks/show` in `layouts/main`.

- [ ] **Step 2: `views/tasks/show.php`** — two-column layout:
  - Left main: breadcrumb `Project name / Task #ID`; editable title (h1 with `contenteditable="true"` + blur saves via `task-page.js`); markdown description (rendered) with "Edit" button toggling to textarea + Save; then `partials/attachment-list` with `$entityType='task'`; then `partials/comment-thread`.
  - Right sidebar: column select; assignee select (project members + "Unassigned"); due date input; tag picker; created-by + created-at meta; Delete button (UI.confirm → POST `/tasks/{id}/delete` → redirect to project board).

- [ ] **Step 3: `Task@update($id)`** — JSON body; whitelist title/description/column_id/assignee_id/due_date; persist via `TaskRepository::update`; fire `task.status_changed` when `column_id` changed; fire `task.assignee_changed` when assignee changed; return `{ok:true, task: refreshed}`.

- [ ] **Step 4: `public/assets/js/task-page.js`** — wires:
  - title blur → POST `/tasks/{id}` with `{title}`
  - description Save button → POST with `{description}`; re-render rendered HTML returned by server (so markdown stays canonical)
  - column select change → POST with `{column_id}`
  - assignee select change → POST with `{assignee_id}` (or empty for null)
  - due_date input change → POST with `{due_date}`
  - delete button → `UI.confirm` → POST `/tasks/{id}/delete` → `location.href = '/projects/{projectId}'`

- [ ] **Step 5: Routes**
```php
$router->get('/tasks/{id}', 'Task@show');
$router->post('/tasks/{id}', 'Task@update');
```

- [ ] **Step 6: E2E** — `tests/e2e/task-page.spec.ts`: from kanban, middle-click a card (or `evaluate(() => window.open)`), in the new tab change column via select, reload board → assert task is in the new column.

- [ ] **Step 7: Commit**
```bash
git add views/tasks system/Controller/TaskController.php public/assets/js/task-page.js public/index.php tests/e2e/task-page.spec.ts
git commit -m "feat: standalone task page with editable fields"
```

---

## Task 23: Tags (project and task scopes)

**Files:** Create `system/Repository/TagRepository.php`, `system/Controller/TagController.php`, `views/partials/tag-picker.php`, `public/assets/js/tags.js`

- [ ] **Step 1: `TagRepository`** — `create(string $scope, string $name, string $color='#8B7C68'): int` (unique on (scope,name) — fetch existing if duplicate), `listForScope(string $scope): array`, `attachToProject(int $projectId, int $tagId)`, `detachFromProject`, `attachToTask`, `detachFromTask`, `listForProject(int $projectId): array`, `listForTask(int $taskId): array`.

- [ ] **Step 2: `views/partials/tag-picker.php`** — takes `$scope`, `$entityType`, `$entityId`, `$current` (already attached tags), `$all` (all tags in scope), `$canEdit`. Renders attached chips with `×` remove + an input that opens a dropdown of available tags + a "Create '{input}'" row when no match.

- [ ] **Step 3: `public/assets/js/tags.js`** — autocomplete dropdown + add/remove API calls:
  - Add existing tag: `POST /api/projects/{id}/tags` with `{tag_id}` (or task variant)
  - Create + add new: `POST /api/tags` with `{scope, name}` → use returned id to attach
  - Remove: `POST /api/projects/{id}/tags/{tagId}/delete` (or task variant)

- [ ] **Step 4: `TagController`** actions: `create`, `attachToProject`, `detachFromProject`, `attachToTask`, `detachFromTask`. Verify membership for project/task ops.

- [ ] **Step 5: Routes**
```php
$router->post('/api/tags', 'Tag@create');
$router->post('/api/projects/{id}/tags', 'Tag@attachToProject');
$router->post('/api/projects/{id}/tags/{tagId}/delete', 'Tag@detachFromProject');
$router->post('/api/tasks/{id}/tags', 'Tag@attachToTask');
$router->post('/api/tasks/{id}/tags/{tagId}/delete', 'Tag@detachFromTask');
```

- [ ] **Step 6: Include partial in `projects/show.php` Overview and `tasks/show.php` sidebar.**

- [ ] **Step 7: Commit**
```bash
git add system/Repository/TagRepository.php system/Controller/TagController.php views/partials/tag-picker.php public/assets/js/tags.js public/index.php views/projects/show.php views/tasks/show.php
git commit -m "feat: tags (project and task scopes) with picker"
```

---

## Task 24: Kanban column management modal

**Files:** Modify `public/assets/js/kanban.js`; add `Column@create/update/delete`

- [ ] **Step 1: `ColumnController`** actions:
  - `create(Request)` — JSON `{project_id, name, color?}`; owner or member; create at end; return new column.
  - `update($id)` — JSON `{name?, color?, position?}`; whitelist; update.
  - `delete($id)` — JSON optional `{move_to: columnId}`; if column has tasks and `move_to` not given → 422 with `{error, has_tasks: true}`; else `TaskColumnRepository::delete($id, $moveTo)`.

- [ ] **Step 2: Fill in `openColumnSettings(columnId)` in `kanban.js`** — fetch column data inline from existing DOM (name from `.name`, color from `.dot` style), render a modal with three fields (name input, color input `type=color`, position read-only label) and two actions: Save (PUT-like POST to `/api/columns/{id}`) and Delete (UI.confirm; if server returns `has_tasks:true`, re-prompt with a select of other columns to move tasks to, then POST again with `move_to`).

- [ ] **Step 3: Routes**
```php
$router->post('/api/columns', 'Column@create');
$router->post('/api/columns/{id}', 'Column@update');
$router->post('/api/columns/{id}/delete', 'Column@delete');
```

- [ ] **Step 4: E2E** — rename a column, change its color, reload, assert new state persists.

- [ ] **Step 5: Commit**
```bash
git add public/assets/js/kanban.js system/Controller/ColumnController.php public/index.php tests/e2e/columns.spec.ts
git commit -m "feat: kanban column rename/recolor/delete-with-move"
```

---

## Task 25: Telegram notifier wired to all events

**Files:** Create `system/Service/TelegramNotifier.php`, `system/Service/NotificationLogger.php`, `system/Repository/NotificationLogRepository.php`; wire `EventBus` listeners in `public/index.php`

- [ ] **Step 1: `system/Service/TelegramNotifier.php`** — single method `send(string $text, ?string $url = null): array` returns `['ok'=>bool, 'error'=>?string]`. Uses cURL: `https://api.telegram.org/bot{TOKEN}/sendMessage` with `chat_id`, `text` (HTML-escaped manually since `parse_mode=HTML`), `parse_mode=HTML`, `disable_web_page_preview=true`. If URL given, append `\n\n<a href="$url">Open</a>`. Timeout 3s. On network failure: retry once. If `TG_BOT_TOKEN` or `TG_CHAT_ID` is empty → return `['ok'=>true, 'error'=>'skipped']` without calling out.

- [ ] **Step 2: `NotificationLogRepository`** — `log(string $event, array $payload, bool $ok, ?string $error): void`.

- [ ] **Step 3: `NotificationLogger`** — service that wraps notifier + repo: `notify(string $event, string $text, ?string $url, array $payload): void` — sends, logs the result.

- [ ] **Step 4: Wire listeners in `public/index.php`** after `EventBus` singleton:
```php
$events = App::make('events');
$tg = new \App\Service\NotificationLogger(
    new \App\Service\TelegramNotifier(App::env('TG_BOT_TOKEN'), App::env('TG_CHAT_ID')),
    new \App\Repository\NotificationLogRepository(App::make('db'))
);
$events->on('user.registered', fn($p) => $tg->notify('user.registered', "[NEW] Registration request: {$p['name']} <{$p['email']}>", null, $p));
$events->on('user.approved', fn($p) => $tg->notify('user.approved', "[USER] {$p['name']} approved by {$p['actor_name']}", null, $p));
$events->on('project.created', fn($p) => $tg->notify('project.created', "[PROJECT] {$p['actor_name']} created '{$p['name']}'", $p['url'], $p));
$events->on('project.updated', fn($p) => $tg->notify('project.updated', "[PROJECT] {$p['actor_name']} updated '{$p['name']}'", $p['url'], $p));
$events->on('task.created', fn($p) => $tg->notify('task.created', "[TASK] {$p['actor_name']} added '{$p['title']}' to {$p['project_name']}", $p['url'], $p));
$events->on('task.status_changed', fn($p) => $tg->notify('task.status_changed', "[TASK] {$p['actor_name']} moved '{$p['title']}' → {$p['new_column']}", $p['url'], $p));
$events->on('task.assignee_changed', fn($p) => $tg->notify('task.assignee_changed', "[TASK] {$p['actor_name']} assigned '{$p['title']}' to {$p['assignee_name']}", $p['url'], $p));
$events->on('comment.created', fn($p) => $tg->notify('comment.created', "[COMMENT] {$p['author']} on {$p['entity_label']} '{$p['target_name']}': " . mb_substr($p['body_text'], 0, 200), $p['url'], $p));
```

- [ ] **Step 5: Enrich event payloads** — update controllers to include `actor_name`, `url`, etc when firing events. For comment events: `body_text` is the plain text (markdown stripped) for the TG message.

- [ ] **Step 6: Smoke test** — set `TG_BOT_TOKEN` + `TG_CHAT_ID` in `.env` (use a real test bot), create a project from the UI, verify the message appears in the configured channel. Without those env vars, verify no calls are made (check `notifications_log` rows have `error='skipped'`).

- [ ] **Step 7: Commit**
```bash
git add system/Service/TelegramNotifier.php system/Service/NotificationLogger.php system/Repository/NotificationLogRepository.php public/index.php
git commit -m "feat: Telegram notifier wired to all 8 events"
```

---

## Task 26: Dashboard with summary

**Files:** Create `system/Controller/DashboardController.php`, `views/dashboard/index.php`; add to `ProjectRepository`/`TaskRepository`/`CommentRepository` minimal aggregate queries

- [ ] **Step 1: Repository helpers**
  - `ProjectRepository::countOpenForUser(int $userId, bool $isAdmin): int` (status='active')
  - `TaskRepository::countOpenForAssignee(int $userId): int` (column.is_done = 0)
  - `TaskRepository::listForAssignee(int $userId, int $limit=6): array`
  - `ProjectRepository::recentForUser(int $userId, bool $isAdmin, int $limit=3): array` (order by updated_at)
  - `CommentRepository::recentForUser(int $userId, bool $isAdmin, int $limit=10): array` (only from projects the user is a member of)

- [ ] **Step 2: `views/dashboard/index.php`** — sections (using design system primitives):
  1. Hero kicker "REQUEST · 01 / Welcome back" + h1.display "Hello, {name}" with mono stats line: "{N} open projects · {M} my tasks · {K} new comments this week"
  2. Section head "My tasks" + 6 cards (link to `/tasks/{id}` in same tab)
  3. Section head "Recent projects" + `.cards-row` grid
  4. Section head "Recent activity" + mono list of comments with `fmt_datetime` + author + entity name link

- [ ] **Step 3: `DashboardController@index`** — gather all the above, pass to view, render with `activeNav='dashboard'`.

- [ ] **Step 4: Route**
```php
$router->get('/', 'Dashboard@index');
```
(Replaces the smoke route; remove `Smoke` controller and route registration.)

- [ ] **Step 5: Commit**
```bash
git add system/Controller/DashboardController.php views/dashboard system/Repository public/index.php
git rm system/Controller/SmokeController.php
git commit -m "feat: dashboard with personalised summary"
```

---

## Task 27: Polish — error pages, CSP, manual QA, README

**Files:** Create `views/errors/{403,404,500}.php`; modify `public/index.php` to set CSP header; expand `README.md`; add `docs/QA-CHECKLIST.md`

- [ ] **Step 1: Error pages** — three minimal pages using `layouts/auth.php` (no sidebar) showing big code + message + back link. Wire from `Response::notFound` etc.

- [ ] **Step 2: Global exception handler** in `public/index.php`:
```php
set_exception_handler(function (\Throwable $e) {
    error_log($e); // logged to data/errors.log via ini_set('error_log', ...)
    if (App::env('APP_DEBUG') === 'true') {
        Response::html('<pre>' . htmlspecialchars((string)$e) . '</pre>', 500);
    } else {
        Response::html(App::make('view')->render('errors/500', [], 'layouts/auth'), 500);
    }
});
ini_set('error_log', APP_ROOT . '/data/errors.log');
```

- [ ] **Step 3: CSP header in `index.php`** before any output:
```php
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; font-src 'self'; connect-src 'self'; frame-ancestors 'none'");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
```
(`'unsafe-inline'` for styles is required by FontAwesome's `<i>` element style attributes — keep it. We can tighten later by inlining all FA CSS.)

- [ ] **Step 4: README.md** — expand with: requirements (PHP 8.2+, SQLite, Apache or `php -S`), setup steps, first-user-becomes-admin note, Telegram setup (create bot via `@BotFather`, get `chat_id` from `@userinfobot`, put both in `.env`), how to run tests (`php tests/run.php` and `npx playwright test`), file structure overview.

- [ ] **Step 5: `docs/QA-CHECKLIST.md`** — manual smoke checklist:
  - Register first user → becomes admin → redirected to dashboard
  - Register second user → goes to /pending; cannot log in
  - Admin approves → second user can log in
  - Create project → 3 default columns visible
  - Add tasks via quick-add → drag between columns → reload → state persists
  - Open task in new tab → edit title/description/assignee/due date → reload → persists
  - Post comment with **bold** + `code` → renders correctly
  - Upload 4 MB JPEG → thumbnail + lightbox; try 6 MB JPEG → toast error
  - Upload 30 MB PDF → file row; try 60 MB PDF → toast error
  - Try SVG upload → blocked with toast
  - Delete user that owns a project → blocked with toast
  - Logout → CSRF token regenerated; cannot reuse old AJAX
  - Telegram (with .env configured): each of the 8 events posts to the channel
  - All `UI.confirm` calls render custom modal (no native `confirm()` anywhere)

- [ ] **Step 6: Run all tests**
```bash
php tests/run.php
npx playwright test
```
Expected: green.

- [ ] **Step 7: Commit**
```bash
git add views/errors public/index.php README.md docs/QA-CHECKLIST.md
git commit -m "chore: error pages, CSP, README, QA checklist"
```

---

## Done

When all tasks above are complete and green, the project is ready for first manual user testing.

**Outstanding / deferred from v1 (explicit non-goals so they don't surprise the reader):**
- Pagination on dashboard activity list (spec §22)
- Admin tag management page `/admin/tags` (spec §13)
- **Attachments on comments** — the spec §11 mentioned linking attachments to comments (`entity_type='comment'`). v1 ships with attachments only on `project` and `task` entities. The `attachments.entity_type` column accepts `'comment'` schema-wise, but no UI or controller path creates such rows. Re-enable in a follow-up if users ask for it.
- Editing comments (delete-and-repost only, per spec §11)
- SVG uploads (rejected by FileUploader::validate)
- Real-time updates (no polling, no SSE)







