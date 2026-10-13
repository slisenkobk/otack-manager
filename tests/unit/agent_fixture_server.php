<?php
if (!function_exists('_cliFixtureServer')) {

// --- fixture HTTP server: the CLI's OWN process makes real HTTP calls ------
//
// Generalises tests/unit/test_agent_client.php:49's
// agent_client_with_fixture_server() (background `php -S`, port poll,
// posix_kill in finally) into a small router keyed on method+path instead
// of one canned response: the CLI issues several different requests per
// command (GET /me, GET /tasks, GET /projects/{id}, GET /tasks/{id}, ...)
// and each needs its own canned reply. Routes are dumped to a JSON file
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
$rawBody = file_get_contents('php://input');
$key = $method . ' ' . $path;

file_put_contents($dir . '/requests.log', json_encode([
    'method' => $method,
    'path'   => $path,
    'query'  => $_GET,
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

    $port = random_int(45000, 49999);
    $cmd = '/usr/bin/env php -S 127.0.0.1:' . $port . ' ' . escapeshellarg($router)
        . ' > /dev/null 2>&1 & echo $!';
    $pid = (int)trim((string)shell_exec($cmd));
    for ($i = 0; $i < 50; $i++) {
        $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($s) { fclose($s); break; }
        usleep(50_000);
    }
    try {
        $fn($port, $dir . '/requests.log');
    } finally {
        if ($pid > 0) {
            if (function_exists('posix_kill')) { @posix_kill($pid, 15); }
            else { @shell_exec('kill ' . $pid . ' 2>/dev/null'); }
        }
        foreach (glob("$dir/*") ?: [] as $f) { @unlink($f); }
        @rmdir($dir);
    }
}
}

if (!function_exists('_requestLog')) {
/** requests.log lines as decoded arrays, in order. */
function _requestLog(string $logFile): array {
    $lines = array_filter(explode("\n", (string)file_get_contents($logFile)), fn($l) => $l !== '');
    return array_map(fn($l) => json_decode($l, true), $lines);
}
}
