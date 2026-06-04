<?php
declare(strict_types=1);

// When used as PHP built-in server router script, serve static files directly.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $file = __DIR__ . $path;
    if ($path !== '/' && is_file($file)) return false;
}

require dirname(__DIR__) . '/system/bootstrap.php';

use App\App;
use App\Auth\SessionManager;
use App\Bootstrap\{Container, Events, Routes};
use App\Database\{Migrations, SchemaBootstrap};
use App\Http\{Csrf, Request, Response};

// Security headers. CSP `style-src` is split per CSP3 (Wave 9.1e / S-6):
//   - `style-src-elem 'self' 'nonce-X'`  — `<style>` elements: nonce-only.
//     Rogue `<style>` injection (the highest-value style XSS vector,
//     since attackers can ship arbitrary CSS incl. `@import`) is fully
//     blocked. The `app_brand_style_tag()` helper carries the same
//     nonce so the brand-color override still applies.
//   - `style-src-attr 'unsafe-inline'`  — inline `style=""` attrs and
//     JS DOM-style mutations (`el.style.X = …`, `setProperty(...)`).
//     The Wave 9.1e CSS-5 sweep + dynamic-style bridge removed every
//     non-nonced inline style at the SOURCE level, but legitimate
//     JS-driven style writes (tag-picker dropdowns, member-avatar bg,
//     task-page editor toggle, kanban filter `display:none`, comments
//     tree pseudo-arms, color-swatch previews, etc.) still need to
//     work. CSP3 distinguishes element vs attribute style — we keep
//     the strict half (element) and accept the lax half (attribute).
$nonce = csp_nonce();
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src-elem 'self' 'nonce-$nonce'; style-src-attr 'unsafe-inline'; script-src 'self'; font-src 'self'; connect-src 'self'; frame-ancestors 'none'");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Global exception handler
ini_set('error_log', APP_ROOT . '/data/errors.log');
set_exception_handler(function (\Throwable $e) {
    error_log((string)$e);
    if (\App\App::env('APP_DEBUG') === 'true') {
        \App\Http\Response::html('<pre>' . htmlspecialchars((string)$e) . '</pre>', 500);
    } else {
        try {
            \App\Http\Response::html(\App\App::make('view')->render('errors/500', [], 'layouts/auth'), 500);
        } catch (\Throwable $_) {
            \App\Http\Response::html('<h1>500</h1><p>Server error</p>', 500);
        }
    }
});

// Reset singleton instances on every request so that CLI-server test runs
// that delete and recreate the database file between suites get a fresh
// connection rather than a stale cached one.
App::reset();

$session = new SessionManager();
$session->start((int)App::env('SESSION_LIFETIME', '43200'));
$store = &$session->storage();
$csrf  = new Csrf($store);

Container::register($store, $csrf, $session);

SchemaBootstrap::$legacyMarkerDir = APP_ROOT . '/data/.schema';
try {
    Migrations::run(App::make('schema'));
} catch (\App\Database\MigrationsLockUnavailable $_) {
    // Another php-fpm worker holds the migration lock — it'll commit shortly
    // and the next request sees the up-to-date schema. Continuing here against
    // the previously-committed schema is the right call: 500-ing every
    // concurrent boot during a deploy would be far worse UX.
}

Events::register();

// Install wizard gate (TODO #10). Behind a feature flag during the
// rollout so existing .env installs aren't disturbed mid-deploy.
// Default flips to true in step 7 of the implementation plan.
$gateEnabled = filter_var(\App\App::env('INSTALL_GATE_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
if ($gateEnabled) {
    // Parse path so `?foo=bar` and `#frag` don't bleed into the prefix
    // match — otherwise `/install-extra` would silently match `/install`.
    $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $bypassPrefixes = ['/assets/'];
    $bypassExact    = ['/favicon.ico', '/robots.txt', '/manifest.webmanifest'];
    $isStatic = in_array($reqPath, $bypassExact, true);
    foreach ($bypassPrefixes as $p) {
        if (str_starts_with($reqPath, $p)) { $isStatic = true; break; }
    }
    $isInstall = $reqPath === '/install' || str_starts_with($reqPath, '/install/');
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

// Slide the remember-me window forward on every authenticated request: each
// page view bumps the cookie expiry to "now + 30 days" so an active user
// stays signed in indefinitely; an inactive one ages out naturally.
if (!empty($store['user_id'])) {
    $session->extendCookie(
        !empty($store['__remember'])
            ? SessionManager::REMEMBER_LIFETIME
            : SessionManager::DEFAULT_LIFETIME
    );
}

$router = Routes::build();
$req    = Request::fromGlobals();

// ─── /api/v1/* hand-off ──────────────────────────────────────────────────────
// All API requests bypass the web Router. Bearer-auth, JSON-only, no CSRF.
if (str_starts_with($req->path, '/api/v1/')) {
    $services = [
        'projects'         => App::make('projects'),
        'members'          => App::make('members'),
        'columns'          => App::make('columns'),
        'tasks'            => App::make('tasks'),
        'task_links'       => App::make('task_links'),
        'comments'         => App::make('comments'),
        'attachments'      => App::make('attachments'),
        'tags'             => App::make('tags'),
        'forms'            => App::make('forms'),
        'form_submissions' => App::make('form_submissions'),
        'polls'            => App::make('polls'),
        'poll_votes'       => App::make('poll_votes'),
        'uploader'         => App::make('uploader'),
        'users'            => App::make('users'),
    ];
    $kernel = new \App\Api\V1\ApiKernel(
        new \App\Api\V1\TokenAuthenticator(App::make('api_tokens'), App::make('users')),
        new \App\Api\V1\RateLimiter(App::make('db'), max: 60, windowSeconds: 60),
        App::make('api_tokens'),
        App::make('activity'),
        App::make('db'),
        $services,
    );
    $kernel->handle($req);
    exit;
}

// ─── Public landing + login hash gate ────────────────────────────────────────
$hasSession = (App::make('session')->store['user_id'] ?? null) !== null;
$loginHash  = (string)App::env('LOGIN_HASH', '');

// Anonymous on "/" — show landing page instead of redirecting to login.
if ($req->method === 'GET' && $req->path === '/' && !$hasSession) {
    require APP_ROOT . '/views/landing.php';
    exit;
}

// `/login` is gated by ?hash=… (configured via LOGIN_HASH env). Already-logged-in
// users skip the check (they'd be redirected to /dashboard inside Auth@loginForm).
if ($req->method === 'GET' && $req->path === '/login' && !$hasSession && $loginHash !== '') {
    $provided = (string)($req->query['hash'] ?? '');
    if (!hash_equals($loginHash, $provided)) {
        Response::redirect('/');
        exit;
    }
}

$match = $router->match($req->method, $req->path);
if (!$match) {
    http_response_code(404);
    echo App::make('view')->render('errors/404', [], 'layouts/auth');
    exit;
}

// Public routes — never require auth
$publicGets = ['/login', '/register', '/pending'];
if (App::env('APP_DEBUG') === 'true') {
    $publicGets[] = '/ui-sandbox';
}
$publicPosts = ['/login', '/register'];
// Public form rendering / submission lives under /f/{hash};
// public short-link redirects live under /s/{slug} (GET only);
// public poll page (contact → vote → thanks) lives under /p/{hash}.
$isFormPath = str_starts_with($req->path, '/f/');
$isShortLinkPath = $req->method === 'GET' && str_starts_with($req->path, '/s/');
$isPollPath = str_starts_with($req->path, '/p/');
$isPublic = ($req->method === 'GET'  && (in_array($req->path, $publicGets, true)  || $isFormPath || $isShortLinkPath || $isPollPath))
         || ($req->method === 'POST' && (in_array($req->path, $publicPosts, true) || $isFormPath || $isPollPath));

if ($req->method === 'POST' && !$isFormPath && !$isPollPath) {
    $token = $req->post['_csrf'] ?? $req->header('x-csrf-token');
    if (!$csrf->verify($token)) { Response::json(['error' => 'CSRF mismatch'], 419); exit; }
}

$currentUser = null;
if (!$isPublic) {
    $currentUser = \App\Http\AuthGuard::require($req);
}

$class = 'App\\Controller\\' . $match['controller'] . 'Controller';
if (!class_exists($class)) {
    http_response_code(404);
    echo App::make('view')->render('errors/404', [], 'layouts/auth');
    exit;
}
$ctrl = \App\Controller\Factory::make($match['controller'], App::make('view'), $currentUser);
$ctrl->{$match['action']}($req, $match['params']);
