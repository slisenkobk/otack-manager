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

// Security headers
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; font-src 'self'; connect-src 'self'; frame-ancestors 'none'");
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
use App\Http\{Request, Response, Csrf};
use App\Routing\Router;
use App\Database\{Connection, SchemaBootstrap, Migrations};
use App\Auth\SessionManager;
use App\View\Renderer;

$session = new SessionManager();
$session->start((int)App::env('SESSION_LIFETIME', '43200'));
$store = &$session->storage();

App::singleton('db',     fn() => Connection::open(APP_ROOT . '/' . App::env('DB_PATH', 'data/app.sqlite')));
App::singleton('schema', fn() => new SchemaBootstrap(App::make('db')));
App::singleton('view',   fn() => new Renderer(APP_ROOT . '/views'));
SchemaBootstrap::$legacyMarkerDir = APP_ROOT . '/data/.schema';
Migrations::run(App::make('schema'));

$csrf = new Csrf($store);

App::singleton('users',   fn() => new \App\Repository\UserRepository(App::make('db')));
App::singleton('projects', fn() => new \App\Repository\ProjectRepository(App::make('db')));
App::singleton('members',  fn() => new \App\Repository\ProjectMemberRepository(App::make('db')));
App::singleton('columns',  fn() => new \App\Repository\TaskColumnRepository(App::make('db')));
App::singleton('tasks',    fn() => new \App\Repository\TaskRepository(App::make('db')));
App::singleton('task_links', fn() => new \App\Repository\TaskLinkRepository(App::make('db')));
App::singleton('settings',  fn() => new \App\Repository\SettingsRepository(App::make('db')));
App::singleton('forms',     fn() => new \App\Repository\FormRepository(App::make('db')));
App::singleton('form_submissions', fn() => new \App\Repository\FormSubmissionRepository(App::make('db')));
App::singleton('hasher',  fn() => new \App\Auth\PasswordHasher());
App::singleton('events',   fn() => new \App\Service\EventBus());
App::singleton('comments', fn() => new \App\Repository\CommentRepository(App::make('db')));
App::singleton('notif_log', fn() => new \App\Repository\NotificationLogRepository(App::make('db')));
App::singleton('activity', fn() => new \App\Repository\ActivityLogRepository(App::make('db')));
App::singleton('compass', fn() => new \App\Service\CompassService(
    App::make('db'),
    App::make('schema'),
    App::make('settings'),
    APP_ROOT . '/data/sessions',
    APP_ROOT . '/' . App::env('UPLOAD_DIR', 'public/uploads'),
    APP_ROOT . '/data/errors.log',
    \App\Database\Migrations::DIR,
));

// Wire Telegram listeners after all singletons are registered
$events = App::make('events');
$tg = new \App\Service\NotificationLogger(
    new \App\Service\TelegramNotifier(App::env('TG_BOT_TOKEN'), App::env('TG_CHAT_ID')),
    App::make('notif_log')
);
$tgEsc  = fn ($s) => \App\Service\TelegramNotifier::escape((string)$s);
$tgBold = fn ($s) => '<b>' . \App\Service\TelegramNotifier::escape((string)$s) . '</b>';

$events->on('user.registered', function ($p) use ($tg, $tgEsc, $tgBold) {
    $tg->notify('user.registered', "[NEW] Registration request: " . $tgBold($p['name']) . " &lt;" . $tgEsc($p['email']) . "&gt;", null, $p);
});
$events->on('user.approved', function ($p) use ($tg, $tgEsc, $tgBold) {
    $tg->notify('user.approved', "[USER] " . $tgBold($p['name']) . " approved by " . $tgBold($p['actor_name']), null, $p);
});
$events->on('project.created', function ($p) use ($tg, $tgEsc, $tgBold) {
    $tg->notify('project.created', "[PROJECT] " . $tgBold($p['actor_name']) . " created \"" . $tgEsc($p['name']) . "\"", $p['url'] ?? null, $p);
});
$events->on('project.updated', function ($p) use ($tg, $tgEsc, $tgBold) {
    $tg->notify('project.updated', "[PROJECT] " . $tgBold($p['actor_name']) . " updated \"" . $tgEsc($p['name']) . "\"", $p['url'] ?? null, $p);
});
$events->on('task.created', function ($p) use ($tg, $tgEsc, $tgBold) {
    $tg->notify('task.created', "[TASK] " . $tgBold($p['actor_name']) . " added \"" . $tgEsc($p['title']) . "\" to " . $tgEsc($p['project_name']), $p['url'] ?? null, $p);
});
$events->on('task.status_changed', function ($p) use ($tg, $tgEsc, $tgBold) {
    $tg->notify('task.status_changed', "[TASK] " . $tgBold($p['actor_name']) . " moved \"" . $tgEsc($p['title']) . "\" → " . $tgEsc($p['new_column']), $p['url'] ?? null, $p);
});
$events->on('task.assignee_changed', function ($p) use ($tg, $tgEsc, $tgBold) {
    $tg->notify('task.assignee_changed', "[TASK] " . $tgBold($p['actor_name']) . " assigned \"" . $tgEsc($p['title']) . "\" to " . $tgBold($p['assignee_name']), $p['url'] ?? null, $p);
});
$events->on('comment.created', function ($p) use ($tg, $tgEsc, $tgBold) {
    $reply = !empty($p['reply_to_author'])
        ? " (reply to " . $tgBold($p['reply_to_author']) . ")"
        : "";
    $tg->notify('comment.created',
        "[COMMENT] " . $tgBold($p['author']) . " on " . $tgEsc($p['entity_label']) . " \"" . $tgEsc($p['target_name']) . "\"" . $reply . ": "
        . $tgEsc(mb_substr($p['body_text'] ?? '', 0, 200)),
        $p['url'] ?? null, $p);
});
$events->on('project.deleted', function ($p) use ($tg, $tgEsc, $tgBold) {
    $tg->notify('project.deleted', "[PROJECT] " . $tgBold($p['actor_name']) . " deleted \"" . $tgEsc($p['name']) . "\"", null, $p);
});
$events->on('task.deleted', function ($p) use ($tg, $tgEsc, $tgBold) {
    $tg->notify('task.deleted', "[TASK] " . $tgBold($p['actor_name']) . " deleted \"" . $tgEsc($p['title']) . "\" in " . $tgEsc($p['project_name']), null, $p);
});
$events->on('comment.deleted', function ($p) use ($tg, $tgEsc, $tgBold) {
    $tg->notify('comment.deleted', "[COMMENT] " . $tgBold($p['actor_name']) . " deleted a comment on " . $tgEsc($p['entity_label']) . " \"" . $tgEsc($p['target_name']) . "\"", $p['url'] ?? null, $p);
});
$events->on('task.linked', function ($p) use ($tg, $tgEsc, $tgBold) {
    $tg->notify('task.linked', "[LINK] " . $tgBold($p['actor_name']) . " linked \"" . $tgEsc($p['task_title']) . "\" ↔ \"" . $tgEsc($p['linked_title']) . "\" in " . $tgEsc($p['project_name']), $p['url'] ?? null, $p);
});
$events->on('task.unlinked', function ($p) use ($tg, $tgEsc, $tgBold) {
    $tg->notify('task.unlinked', "[LINK] " . $tgBold($p['actor_name']) . " unlinked \"" . $tgEsc($p['task_title']) . "\" ↔ \"" . $tgEsc($p['linked_title']) . "\" in " . $tgEsc($p['project_name']), $p['url'] ?? null, $p);
});
// Public form submissions — flagged IMPORTANT because they are customer
// touchpoints (and surface in /forms-data immediately).
$events->on('form.submitted', function ($p) use ($tg, $tgEsc, $tgBold) {
    $preview = !empty($p['preview']) ? "\n" . $tgEsc(mb_substr($p['preview'], 0, 280)) : '';
    $tg->notify(
        'form.submitted',
        "🔥 <b>IMPORTANT</b> · [FORM] new submission on \"" . $tgEsc($p['form_title']) . "\"" . $preview,
        $p['url'] ?? null,
        $p
    );
});
$events->on('form.created', function ($p) use ($tg, $tgEsc, $tgBold) {
    $tg->notify('form.created', "[FORM] " . $tgBold($p['actor_name']) . " created form \"" . $tgEsc($p['title']) . "\"", null, $p);
});
$events->on('form.updated', function ($p) use ($tg, $tgEsc, $tgBold) {
    $tg->notify('form.updated', "[FORM] " . $tgBold($p['actor_name']) . " updated form \"" . $tgEsc($p['title']) . "\"", null, $p);
});
$events->on('form.deleted', function ($p) use ($tg, $tgEsc, $tgBold) {
    $tg->notify('form.deleted', "[FORM] " . $tgBold($p['actor_name']) . " deleted form \"" . $tgEsc($p['title']) . "\"", null, $p);
});
// Compass: admin housekeeping actions (sessions cleared, asset bust, etc.).
$events->on('compass.action', function ($p) use ($tg, $tgEsc, $tgBold) {
    $tg->notify(
        'compass.action',
        "[COMPASS] " . $tgBold($p['actor_name']) . ' ' . $tgEsc($p['summary']),
        null,
        $p
    );
});
App::singleton('attachments', fn() => new \App\Repository\AttachmentRepository(App::make('db')));
App::singleton('tags',     fn() => new \App\Repository\TagRepository(App::make('db')));
App::singleton('uploader', fn() => new \App\Service\FileUploader(
    (int)App::env('UPLOAD_MAX_IMAGE', '5242880'),
    (int)App::env('UPLOAD_MAX_FILE', '52428800'),
    APP_ROOT . '/' . App::env('UPLOAD_DIR', 'public/uploads')
));
App::singleton('auth',    function () use (&$store) {
    return new \App\Auth\AuthManager(App::make('users'), App::make('hasher'), $store);
});
App::singleton('csrf',    function () use ($csrf) { return $csrf; });
App::singleton('session', function () use (&$store) {
    return new class($store) {
        public array $store;
        public function __construct(array &$s) { $this->store = &$s; }
    };
});
// Expose the SessionManager itself so the login flow can re-issue the
// session cookie with the right lifetime (24h vs 30d for remember-me).
App::singleton('session_manager', function () use ($session) { return $session; });

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

$router = new Router();
$router->get('/', 'Dashboard@index');
$router->get('/api/activity', 'Dashboard@moreActivity');

if (App::env('APP_DEBUG') === 'true') {
    $router->get('/ui-sandbox', 'Smoke@uiSandbox');
}

$router->get('/login',    'Auth@loginForm');
$router->post('/login',   'Auth@login');
$router->get('/register', 'Auth@registerForm');
$router->post('/register','Auth@register');
$router->get('/pending',  'Auth@pending');
$router->post('/logout',  'Auth@logout');

$router->get('/users', 'User@index');
$router->post('/users', 'User@create');
$router->post('/users/{id}', 'User@update');
$router->post('/users/{id}/approve', 'User@approve');
$router->post('/users/{id}/block', 'User@block');
$router->post('/users/{id}/role', 'User@setRole');
$router->post('/users/{id}/delete', 'User@delete');

$router->get('/profile', 'Profile@show');
$router->post('/profile', 'Profile@update');
$router->post('/profile/password', 'Profile@updatePassword');
$router->post('/profile/avatar', 'Profile@updateAvatar');
$router->post('/profile/avatar/delete', 'Profile@removeAvatar');
$router->post('/profile/locale', 'Profile@updateLocale');

$router->get('/projects', 'Project@index');
$router->post('/projects', 'Project@create');
$router->get('/projects/{id}', 'Project@show');
$router->get('/projects/{id}/edit', 'Project@editForm');
$router->post('/projects/{id}', 'Project@update');
$router->post('/projects/{id}/delete', 'Project@delete');
$router->post('/api/projects/{id}/pin', 'Project@togglePin');

$router->get('/tasks/{id}', 'Task@show');
$router->post('/tasks/{id}', 'Task@update');
$router->post('/projects/{id}/tasks', 'Task@create');
$router->post('/tasks/{id}/delete', 'Task@delete');
$router->post('/api/tasks/{id}/promote-to-project', 'Task@promoteToProject');
$router->post('/api/tasks/{id}/move', 'Task@move');
$router->get('/api/projects/{id}/tasks/search', 'Task@search');
$router->get('/api/projects/{id}/columns/{cid}/tasks', 'Task@listForColumn');
$router->get('/api/tasks/{id}/links/search', 'Task@searchLinkable');
$router->post('/api/tasks/{id}/links', 'Task@link');
$router->post('/api/tasks/{id}/links/{otherId}/delete', 'Task@unlink');

$router->post('/api/projects/{id}/members', 'Project@addMember');
$router->post('/api/projects/{id}/members/{userId}/delete', 'Project@removeMember');

$router->post('/api/comments', 'Comment@create');
$router->post('/api/comments/{id}/delete', 'Comment@delete');

$router->post('/api/attachments', 'Attachment@upload');
$router->post('/api/attachments/{id}/delete', 'Attachment@delete');

$router->post('/api/tags', 'Tag@create');
$router->post('/api/projects/{id}/tags', 'Tag@attachToProject');
$router->post('/api/projects/{id}/tags/{tagId}/delete', 'Tag@detachFromProject');
$router->post('/api/tasks/{id}/tags', 'Tag@attachToTask');
$router->post('/api/tasks/{id}/tags/{tagId}/delete', 'Tag@detachFromTask');

$router->get('/admin/settings', 'Settings@show');
$router->post('/admin/settings', 'Settings@update');

$router->get('/admin/compass',                          'Compass@index');
$router->get('/admin/compass/migrations',               'Compass@migrations');
$router->post('/admin/compass/migrations/run',          'Compass@runMigrations');
$router->get('/admin/compass/cache',                    'Compass@cache');
$router->post('/admin/compass/cache/sessions/clear',    'Compass@clearSessions');
$router->post('/admin/compass/cache/uploads/orphans/clear', 'Compass@clearOrphanUploads');
$router->post('/admin/compass/cache/bust',              'Compass@bustAssetCache');
$router->get('/admin/compass/db-stats',                 'Compass@stats');
$router->get('/admin/compass/logs',                     'Compass@logs');
$router->post('/admin/compass/logs/clear',              'Compass@clearErrorLog');

$router->get('/forms', 'Form@index');
$router->get('/forms/new', 'Form@builder');
$router->post('/forms', 'Form@save');
$router->get('/forms/{id}', 'Form@builder');
$router->post('/forms/{id}', 'Form@save');
$router->post('/forms/{id}/delete', 'Form@delete');
$router->post('/forms/{id}/rotate-hash', 'Form@regenerateHash');

$router->get('/forms-data', 'FormData@index');
$router->get('/forms-data/{id}', 'FormData@show');
$router->post('/api/forms-data/{id}/status', 'FormData@setStatus');
$router->post('/api/forms-data/{id}/promote', 'FormData@promote');
$router->post('/api/forms-data/{id}/delete', 'FormData@delete');

$router->get('/f/{hash}', 'PublicForm@show');
$router->post('/f/{hash}', 'PublicForm@submit');

$router->get('/admin/tags', 'TagAdmin@index');
$router->post('/api/admin/tags/{id}', 'TagAdmin@update');
$router->post('/api/admin/tags/{id}/delete', 'TagAdmin@delete');

$router->post('/api/columns', 'Column@create');
$router->post('/api/columns/{id}', 'Column@update');
$router->post('/api/columns/{id}/delete', 'Column@delete');
$router->post('/api/projects/{id}/columns/reorder', 'Column@reorder');

$req   = Request::fromGlobals();

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
// Public form rendering / submission lives under /f/{hash}
$isFormPath = str_starts_with($req->path, '/f/');
$isPublic = ($req->method === 'GET'  && (in_array($req->path, $publicGets, true)  || $isFormPath))
         || ($req->method === 'POST' && (in_array($req->path, $publicPosts, true) || $isFormPath));

if ($req->method === 'POST' && !$isFormPath) {
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
$ctrl = new $class(App::make('view'), $currentUser);
$ctrl->{$match['action']}($req, $match['params']);
