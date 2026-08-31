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

function assert_eq($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            ($msg ?: 'assert_eq failed') .
            ' — expected ' . var_export($expected, true) .
            ', got ' . var_export($actual, true)
        );
    }
}

function assert_true(bool $cond, string $msg = ''): void {
    if (!$cond) throw new RuntimeException($msg ?: 'expected true');
}

function api_request(string $method, string $path, array $opts = []): array {
    $url = 'http://localhost:8765' . $path;
    $headers = array_merge(['Accept: application/json'], $opts['headers'] ?? []);
    if (isset($opts['body'])) {
        $headers[] = 'Content-Type: application/json';
    }
    $ctx = [
        'http' => [
            'method' => $method,
            'header' => $headers,
            'ignore_errors' => true,
            'timeout' => 5,
            'content' => $opts['body'] ?? '',
        ],
    ];
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

/**
 * Multipart upload helper — stream_context_create can't easily compose
 * multipart bodies, so we shell out to curl. Used by test_attachments.php.
 *
 * $fields are POST form fields; $filePath is the local file to upload under
 * the $field name (default "file").
 */
function api_upload(string $path, string $token, array $fields, string $filePath, ?string $field = 'file'): array {
    $url  = 'http://localhost:8765' . $path;
    $tmp  = '/tmp/otack-api-upload-resp.txt';
    @unlink($tmp);
    $args = [
        'curl', '-s', '-o', $tmp, '-w', '%{http_code}',
        '-X', 'POST',
        '-H', 'Authorization: Bearer ' . $token,
        '-H', 'Accept: application/json',
    ];
    foreach ($fields as $k => $v) {
        $args[] = '-F';
        $args[] = "$k=$v";
    }
    $args[] = '-F';
    $args[] = "$field=@" . $filePath;
    $args[] = $url;
    $cmd = implode(' ', array_map('escapeshellarg', $args));
    $statusRaw = shell_exec($cmd);
    $status = (int)trim((string)$statusRaw);
    $body = @file_get_contents($tmp) ?: '';
    return [
        'status' => $status,
        'body'   => $body,
        'json'   => $body === '' ? null : json_decode($body, true),
    ];
}

// Defensive port cleanup — a previous aborted run may have left a server bound.
@shell_exec('kill $(lsof -ti:8765) 2>/dev/null');

// Start a one-off php -S pointed at the test DB.
$dataDb = $root . '/data/app.api-test.sqlite';
@unlink($dataDb); @unlink($dataDb . '-wal'); @unlink($dataDb . '-shm');

$env = [
    'DB_PATH=data/app.api-test.sqlite',
    'APP_URL=http://localhost:8765',
    'SEED_DEFAULT_ADMIN_EMAIL=',
    'SEED_DEFAULT_ADMIN_PASSWORD_HASH=',
    'PATH=' . getenv('PATH'),
];
// -d max_execution_time=0: `php -S` is single-process — one request that
// exceeds PHP's default 30s limit (a slow password_verify() in
// system/Auth/PasswordHasher.php) fatals the whole server, and every
// remaining request in the run then fails with connection-refused. The
// flag must precede the router-script argument or PHP treats it as script
// argv instead of an ini directive. Test harness only; production
// PHP-FPM config is untouched. Mirrors playwright.config.ts (37f713e).
$cmd = '/usr/bin/env ' . implode(' ', array_map('escapeshellarg', $env))
     . ' php -d max_execution_time=0 -S localhost:8765 -t ' . escapeshellarg($root . '/public')
     . ' ' . escapeshellarg($root . '/public/index.php')
     . ' > /tmp/otack-api-test-server.log 2>&1 & echo $!';
$pid = (int)trim(shell_exec($cmd));
$killServer = function () use ($pid) {
    if ($pid > 0 && function_exists('posix_kill')) {
        @posix_kill($pid, 15);
    } else {
        @shell_exec("kill $pid 2>/dev/null");
    }
};
register_shutdown_function($killServer);

// Catch Ctrl+C / SIGTERM so the php -S child doesn't outlive the runner.
// register_shutdown_function does not fire on uncaught signals, hence
// pcntl handlers in addition. ext-pcntl is CLI-only and may be absent on
// minimal containers — both branches skip cleanly if unavailable. (T-11)
if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $signalHandler = function (int $sig) use ($killServer) {
        $killServer();
        exit(128 + $sig);
    };
    pcntl_signal(SIGINT, $signalHandler);
    pcntl_signal(SIGTERM, $signalHandler);
    pcntl_signal(SIGHUP, $signalHandler);
}

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
