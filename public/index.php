<?php
declare(strict_types=1);

// When used as PHP built-in server router script, serve static files directly.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH))) {
    return false;
}

require dirname(__DIR__) . '/system/bootstrap.php';

use App\App;
use App\Http\{Request, Response, Csrf};
use App\Routing\Router;
use App\Database\{Connection, SchemaBootstrap, Migrations};
use App\Auth\SessionManager;
use App\View\Renderer;

$session = new SessionManager();
$session->start((int)App::env('SESSION_LIFETIME', '43200'));
$store = &$session->storage();

App::singleton('db', fn() => Connection::open(APP_ROOT . '/' . App::env('DB_PATH', 'data/app.sqlite')));
App::singleton('schema', fn() => new SchemaBootstrap(App::make('db'), APP_ROOT . '/data/.schema'));
App::singleton('view', fn() => new Renderer(APP_ROOT . '/views'));
Migrations::run(App::make('schema'));
$csrf = new Csrf($store);

$router = new Router();
$router->get('/', 'Smoke@hello');

if (App::env('APP_DEBUG') === 'true') {
    $router->get('/ui-sandbox', 'Smoke@uiSandbox');
}

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
