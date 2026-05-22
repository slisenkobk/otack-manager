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
App::singleton('schema', fn() => new SchemaBootstrap(App::make('db'), APP_ROOT . '/data/.schema'));
App::singleton('view',   fn() => new Renderer(APP_ROOT . '/views'));
Migrations::run(App::make('schema'));

$csrf = new Csrf($store);

App::singleton('users',   fn() => new \App\Repository\UserRepository(App::make('db')));
App::singleton('projects', fn() => new \App\Repository\ProjectRepository(App::make('db')));
App::singleton('members',  fn() => new \App\Repository\ProjectMemberRepository(App::make('db')));
App::singleton('columns',  fn() => new \App\Repository\TaskColumnRepository(App::make('db')));
App::singleton('tasks',    fn() => new \App\Repository\TaskRepository(App::make('db')));
App::singleton('hasher',  fn() => new \App\Auth\PasswordHasher());
App::singleton('events',   fn() => new \App\Service\EventBus());
App::singleton('comments', fn() => new \App\Repository\CommentRepository(App::make('db')));
App::singleton('attachments', fn() => new \App\Repository\AttachmentRepository(App::make('db')));
App::singleton('tags',     fn() => new \App\Repository\TagRepository(App::make('db')));
App::singleton('uploader', fn() => new \App\Service\FileUploader(
    (int)App::env('UPLOAD_MAX_IMAGE', '5242880'),
    (int)App::env('UPLOAD_MAX_FILE', '52428800'),
    APP_ROOT . '/public/uploads'
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

$router = new Router();
$router->get('/', 'Smoke@hello');

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
$router->post('/users/{id}/approve', 'User@approve');
$router->post('/users/{id}/block', 'User@block');
$router->post('/users/{id}/role', 'User@setRole');
$router->post('/users/{id}/delete', 'User@delete');

$router->get('/profile', 'Profile@show');
$router->post('/profile', 'Profile@update');
$router->post('/profile/password', 'Profile@updatePassword');

$router->get('/projects', 'Project@index');
$router->get('/projects/new', 'Project@createForm');
$router->post('/projects', 'Project@create');
$router->get('/projects/{id}', 'Project@show');
$router->get('/projects/{id}/edit', 'Project@editForm');
$router->post('/projects/{id}', 'Project@update');
$router->post('/projects/{id}/delete', 'Project@delete');

$router->get('/tasks/{id}', 'Task@show');
$router->post('/tasks/{id}', 'Task@update');
$router->post('/projects/{id}/tasks', 'Task@create');
$router->post('/tasks/{id}/delete', 'Task@delete');
$router->post('/api/tasks/{id}/move', 'Task@move');

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

$router->post('/api/columns', 'Column@create');
$router->post('/api/columns/{id}', 'Column@update');
$router->post('/api/columns/{id}/delete', 'Column@delete');

$req   = Request::fromGlobals();
$match = $router->match($req->method, $req->path);
if (!$match) { Response::notFound(); exit; }

if ($req->method === 'POST') {
    $token = $req->post['_csrf'] ?? $req->header('x-csrf-token');
    if (!$csrf->verify($token)) { Response::json(['error' => 'CSRF mismatch'], 419); exit; }
}

// Public routes — never require auth
$publicGets = ['/login', '/register', '/pending'];
if (App::env('APP_DEBUG') === 'true') {
    $publicGets[] = '/ui-sandbox';
}
$publicPosts = ['/login', '/register'];
$isPublic = ($req->method === 'GET'  && in_array($req->path, $publicGets, true))
         || ($req->method === 'POST' && in_array($req->path, $publicPosts, true));

$currentUser = null;
if (!$isPublic) {
    $currentUser = \App\Http\AuthGuard::require($req);
}

$class = 'App\\Controller\\' . $match['controller'] . 'Controller';
if (!class_exists($class)) { Response::notFound("Controller missing"); exit; }
$ctrl = new $class(App::make('view'), $currentUser);
$ctrl->{$match['action']}($req, $match['params']);
