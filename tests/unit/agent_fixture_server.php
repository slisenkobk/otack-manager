<?php
if (!function_exists('_cliFixtureServer')) {

// --- fixture HTTP server: the CLI's OWN process makes real HTTP calls ------
//
// Background `php -S` on a kernel-picked free port, marker poll, reaped in
// finally — shared by the CLI, Board, Approval and Client tests. A small
// router keyed on method+path, not one canned response: the CLI issues several
// different requests per command (GET /me, GET /tasks, GET /projects/{id},
// GET /tasks/{id}, ...) and each needs its own canned reply. Routes are dumped to a JSON file
// beside the router script and re-read on every request (the built-in
// server re-execs the script per request, so this is just a file read, not
// a race); each request is also appended to a log file so a test can
// assert on what the CLI actually sent — the request body of a PATCH/POST,
// or that a call never happened at all (the allow-list gate tests rely on
// the latter).
//
// $routes: "METHOD /path" => list of {status, body} served in order (last
// one repeats once exhausted, so a route hit more times than fixtured
// doesn't crash the server — it just keeps answering with its last entry).
function _cliFixtureServer(array $routes, callable $fn): void
{
    $dir = sys_get_temp_dir() . '/otack-cli-fixture-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    file_put_contents($dir . '/routes.json', json_encode($routes));
    file_put_contents($dir . '/requests.log', '');

    $router = $dir . '/router.php';
    file_put_contents($router, <<<'PHP'
<?php
$dir = __DIR__;
$routes = json_decode((string)file_get_contents($dir . '/routes.json'), true) ?: [];
$method = $_SERVER['REQUEST_METHOD'];
$path = strtok($_SERVER['REQUEST_URI'], '?');
// Readiness probe: answers with this run's marker so the helper knows the
// server on the port is the one it started (not a previous test's server
// still shutting down). Not logged, so tests never see it.
if ($path === '/__otack_fixture_marker__') {
    echo (string)@file_get_contents($dir . '/marker.txt');
    exit;
}
$rawBody = file_get_contents('php://input');
$key = $method . ' ' . $path;

file_put_contents($dir . '/requests.log', json_encode([
    'method' => $method,
    'path'   => $path,
    'query'  => $_GET,
    'auth'   => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'body'   => $rawBody !== '' ? json_decode($rawBody, true) : null,
]) . "\n", FILE_APPEND);

$queue = $routes[$key] ?? null;
if ($queue === null) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'no route fixtured for ' . $key]);
    exit;
}
$counterFile = $dir . '/n-' . md5($key) . '.txt';
$idx = is_file($counterFile) ? (int)file_get_contents($counterFile) : 0;
$resp = $queue[$idx] ?? $queue[count($queue) - 1];
file_put_contents($counterFile, (string)($idx + 1));

http_response_code($resp['status'] ?? 200);
header('Content-Type: application/json');
echo $resp['body'] ?? '{}';
PHP
    );

    $marker = bin2hex(random_bytes(8));
    file_put_contents($dir . '/marker.txt', $marker);

    // Ask the kernel for a free port (bind to :0), release it and start the
    // server on it. The release->bind window is tiny but not zero, so the
    // start is retried on a fresh port if our server never answers with our
    // marker (it lost the port, or another server holds it).
    $proc = null;
    $port = 0;
    for ($attempt = 0; $attempt < 5 && $proc === null; $attempt++) {
        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) { continue; }
        $name = (string)stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int)substr($name, strrpos($name, ':') + 1);
        if ($port <= 0) { continue; }

        $p = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        if (!is_resource($p)) { continue; }
        if (_cliFixtureWaitForMarker($p, $port, $marker)) { $proc = $p; break; }
        _cliFixtureStop($p);
    }
    if ($proc === null) {
        foreach (glob("$dir/*") ?: [] as $f) { @unlink($f); }
        @rmdir($dir);
        throw new RuntimeException('fixture server did not start on a free port');
    }

    try {
        $fn($port, $dir . '/requests.log');
    } finally {
        _cliFixtureStop($proc);
        foreach (glob("$dir/*") ?: [] as $f) { @unlink($f); }
        @rmdir($dir);
    }
}

/** Poll until the server on $port answers with $marker; false if the child died or 5 s passed. */
function _cliFixtureWaitForMarker($proc, int $port, string $marker): bool
{
    $ctx = stream_context_create(['http' => ['timeout' => 0.5, 'ignore_errors' => true]]);
    for ($i = 0; $i < 100; $i++) {
        if (!(proc_get_status($proc)['running'] ?? false)) { return false; }
        $got = @file_get_contents('http://127.0.0.1:' . $port . '/__otack_fixture_marker__', false, $ctx);
        if ($got === $marker) { return true; }
        usleep(50_000);
    }
    return false;
}

/** SIGTERM the child (SIGKILL after 3 s) and reap it, so its port is free when we return. */
function _cliFixtureStop($proc): void
{
    if (!is_resource($proc)) { return; }
    proc_terminate($proc, 15);
    for ($i = 0; $i < 60 && (proc_get_status($proc)['running'] ?? false); $i++) { usleep(50_000); }
    if (proc_get_status($proc)['running'] ?? false) { proc_terminate($proc, 9); }
    proc_close($proc);
}
}

if (!function_exists('_requestLog')) {
/** requests.log lines as decoded arrays, in order. */
function _requestLog(string $logFile): array {
    $lines = array_filter(explode("\n", (string)file_get_contents($logFile)), fn($l) => $l !== '');
    return array_map(fn($l) => json_decode($l, true), $lines);
}
}
