# Architecture

A tour of the codebase for contributors. Read this first, then go to
[DATABASE.md](DATABASE.md) for the data model, [API.md](API.md) for the
HTTP API, and [SECURITY.md](SECURITY.md) for the security boundaries.

The design ethos is deliberate: no Composer, no framework, no bundler. Every
moving part is a plain PHP file you can read in a sitting.

## 1. High-level layout

```
public/             # web root + front controller (index.php)
  assets/           # vanilla JS modules, CSS, fonts
  uploads/          # user-uploaded files, UUID-named under YYYY/MM/
  index.php         # request entry point
  migrate.php       # web-callable migrator (gated by LOGIN_HASH)

system/             # all PHP source (no autoloader needed — bootstrap does it)
  App.php           # tiny DI container
  bootstrap.php     # constants, env loader, PSR-4 loader
  Auth/             # SessionManager, LoginThrottle, PasswordHasher
  Api/V1/           # ApiKernel, TokenAuthenticator, RateLimiter, JsonRequest
  Controller/       # web controllers; one class per resource
  Database/         # Connection, Migrations, Driver/, Schema/, migrations/
  Http/             # Request, Response, Csrf, AuthGuard
  i18n/             # en.php, pl.php, uk.php — parallel translation arrays
  Repository/       # PDO-bound data access, one class per table
  Routing/          # web router (regex-based)
  Service/          # cross-cutting services (Uploader, Sanitizer, Updater, …)
  View/             # Renderer + helper functions (t(), e(), csrf_field(), …)
  version.php       # current semver string, bumped per release

views/              # PHP templates (server-rendered, no Twig)
  layouts/          # main, auth — top-level chrome
  <resource>/       # one folder per controller

data/               # runtime state — NEVER web-accessible
  app.sqlite        # default DB
  sessions/         # PHP session files, mode 0700
  errors.log
  backups/{ts}/     # self-updater snapshots

bin/                # CLI scripts
  migrate.php       # apply pending migrations
  self-update.php   # CLI fallback for the in-app updater

tests/              # hand-rolled runner (run.php), no PHPUnit
  unit/             # 234 fast unit tests
  api/              # 84 API integration tests against a real DB
  e2e/              # Playwright specs (TypeScript)

docs/               # this directory
```

## 2. Request flow

For a typical authenticated web request like `POST /tasks/42`:

1. The web server hits `public/index.php`.
2. `system/bootstrap.php` runs: defines `APP_ROOT`, loads `.env`, registers a
   tiny PSR-4 autoloader for `App\…`.
3. Security headers go out first (CSP, nosniff, Referrer-Policy).
4. The global exception handler is installed.
5. `App::reset()` clears the singleton cache (for the CLI-server test
   isolation case).
6. `SessionManager::start()` boots the session.
7. `Connection::openFromEnv()` opens PDO; `Migrations::run()` applies any
   pending migrations on the first hit.
8. All repositories and services are registered as singletons on `App`.
9. Telegram listeners are bound to the `EventBus` (closures in
   `public/index.php`).
10. The web `Router` is populated, route table by route table.
11. **`/api/v1/*` short-circuits** to `ApiKernel::handle()` and exits.
12. The CSRF gate verifies `_csrf` / `X-CSRF-Token` for POSTs that are not
    public-form / public-poll routes.
13. `AuthGuard::require()` resolves the current user from the session.
14. The Router dispatches to a controller method, which renders a view or
    returns JSON via `Response::json()`.

The flow is intentionally linear — there is no boot phase, no event-driven
initialisation, no middleware stack. Anything that looks like middleware (CSRF
verification, auth guard) is an inline check in `public/index.php`.

## 3. Dependency injection

[`App`](../system/App.php) is a 30-line static singleton container:

```php
App::singleton('users', fn() => new \App\Repository\UserRepository(App::make('db')));
$users = App::make('users');
```

All registrations live in `public/index.php`. Factories are lazy — the closure
is only called the first time `make()` is asked for that ID. `App::reset()`
clears the resolved instances cache so that tests using the CLI server can
swap the underlying SQLite file between requests.

No interfaces, no autowiring, no compiled container. Adding a new service is
one line in `public/index.php`.

## 4. Routing

Two routing systems, deliberately divergent:

**Web — [`Routing\Router`](../system/Routing/Router.php).** Patterns use
`{name}` placeholders that compile to a regex. `get()` and `post()` register;
`match()` returns `['controller' => …, 'action' => …, 'params' => […]]`. The
class deliberately does not support middleware chains or method dispatch
beyond GET/POST — anything more complex lives in the controller.

**API — [`Api\V1\ApiKernel`](../system/Api/V1/ApiKernel.php).** The route table
is a flat associative array keyed on `"METHOD /path/{id}"`. The kernel handles
its own auth, rate-limit, JSON parsing, and error envelope. It supports
`PATCH` and `DELETE`, which the web router does not.

Splitting the two avoids the temptation to bolt middleware machinery onto the
web router just because the API needs it.

## 5. Repositories

Every table has a class named `XxxRepository` in
[`system/Repository/`](../system/Repository/). The shape is uniform:

```php
final class TaskRepository {
    public function __construct(private \PDO $pdo) {}

    public function find(int $id): ?array { /* SELECT … LIMIT 1 */ }
    public function listFor(int $projectId): array { /* SELECT … */ }
    public function create(array $data): int { /* INSERT … RETURN lastInsertId */ }
    public function update(int $id, array $data): void { /* UPDATE … */ }
    public function delete(int $id): void { /* DELETE … */ }
}
```

- Methods return associative arrays, not domain objects (the codebase has no
  ORM and no value-object layer).
- Joins live inside the repository that "owns" the primary table.
- Anything that needs both SQLite and MySQL behaviour branches on
  `Connection::driverFor($pdo)->name()` — see `LoginThrottle::recordFail` for
  a representative example.

## 6. Services

[`system/Service/`](../system/Service/) holds anything that isn't a controller,
repository, or HTTP plumbing. Each file is single-purpose:

- `EventBus` — `on($name, $listener)` / `emit($name, $payload)`. No
  persistence, no async.
- `FileUploader` — MIME sniff, validate, store, delete.
- `HtmlSanitizer` — DOMDocument-based allow-list scrubber.
- `Markdown` — wraps a tiny inline parser for comment bodies.
- `LinkPreview` — fetches OpenGraph for URLs pasted into comments.
- `TelegramNotifier` — `notify()` wraps `curl` to `api.telegram.org`.
- `NotificationLogger` — decorator over `TelegramNotifier` that records each
  attempt in `notifications_log`.
- `RolePolicy` — pure functions returning bool for "can this role do X on
  this resource".
- `CompassService` — the admin / Compass panel; reads stats, clears caches.
- `Updater` — GitHub releases poller, downloader, backup, apply, rollback.
- `DbMigrator` — in-app SQLite → MySQL migration.

## 7. Migrations

[`system/Database/migrations/`](../system/Database/migrations/) holds
`YYYYMMDD_NNN_description.php` files. Each file returns a function:

```php
return function (\App\Database\SchemaBootstrap $schema) {
    $schema->createTableIfNotExists('users', function (Blueprint $t) {
        $t->id();
        $t->string('email')->unique();
        $t->timestamps();
    });
};
```

The Blueprint DSL is a portable SQL builder — see
[`system/Database/Schema/Blueprint.php`](../system/Database/Schema/Blueprint.php).
[`Migrations::run()`](../system/Database/Migrations.php) loads the files in
filename order, skipping any whose basename (sans `.php`) is already in
`schema_migrations`. Filenames are permanent once shipped — fix-forward only.
See [MIGRATIONS.md](MIGRATIONS.md) for the full ruleset.

**Concurrent boot serialisation.** SQLite's `BEGIN IMMEDIATE` takes a reserved
write-lock on the database file, so concurrent first-hits race on the file
lock and lose the race deterministically. MySQL's `START TRANSACTION` is
weaker — multiple php-fpm workers booting against an empty
`schema_migrations` table could each see "nothing applied" and try to apply
the same `CREATE TABLE`s, blowing up on duplicate-key DDL errors. To avoid
that, `Migrations::run()` takes a named advisory lock
(`GET_LOCK('otack_migrations', 30)`) before the BEGIN on MySQL and releases
it in a `finally`. Workers that fail to acquire within 30 s return an empty
list — by the time they finish waiting, the holder has committed, and the
caller can re-resolve `appliedSet()` on the next code path that needs it.

## 8. Event bus

[`EventBus`](../system/Service/EventBus.php) is a synchronous in-process
dispatcher. `on($name, $callable)` registers a listener; `emit($name, $payload)`
calls every listener with the payload array. There is no ordering guarantee,
no async, no failure isolation — a throwing listener bubbles to the caller.

Current listeners are bound inline in `public/index.php` immediately after the
DI registration. The set of Telegram-bound events covers user lifecycle,
project + task CRUD, comments, links, public-form / poll submissions, and
admin actions. Moving these closures into a dedicated wiring file is a Tier 2
refactor — they are intentionally here for now so the surface is obvious.

## 9. Internationalisation

Server side:

- Translation catalogues at `system/i18n/{en,pl,uk}.php`. Each is a flat
  associative array keyed by dotted string keys (`auth.login.title`).
- [`View\helpers.php`](../system/View/helpers.php) exposes `t($key, $params)`
  for templates.
- A convention test enforces parity — every key in `en.php` must exist in
  `pl.php` and `uk.php`.

Client side:

- `views/layouts/main.php` emits `window.__t` as a JSON blob filtered to
  `js.*` keys only — never the full server catalogue.
- `public/assets/js/utils.js` exposes a `t(key, params)` helper with the same
  interpolation rules as the server-side `t()`.
- Confirmation toasts, prompts, and error messages all flow through the JS
  helper rather than hardcoded strings.

## 10. Authentication

Two parallel auth paths:

- **Web** — session cookie + CSRF. [`AuthGuard`](../system/Http/AuthGuard.php)
  reads `$_SESSION['user_id']` and `App::make('users')->find($id)`; on miss it
  redirects to `/login`. `LOGIN_HASH` can additionally gate the login form
  behind `?hash=…`.
- **API** — Bearer token. [`TokenAuthenticator`](../system/Api/V1/TokenAuthenticator.php)
  matches the `Authorization: Bearer otk_…` header against `sha256(plaintext)`
  in `api_tokens`, then loads the owning user.

Both paths converge on the same `users` row and the same `RolePolicy`
service. Tokens are scoped to whichever user issued them — no service
accounts, no API-only users.
