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
$cmd = '/usr/bin/env ' . implode(' ', array_map('escapeshellarg', $env))
     . ' php -S localhost:8765 -t ' . escapeshellarg($root . '/public')
     . ' ' . escapeshellarg($root . '/public/index.php')
     . ' > /tmp/otack-api-test-server.log 2>&1 & echo $!';
$pid = (int)trim(shell_exec($cmd));
register_shutdown_function(function () use ($pid) {
    if ($pid > 0 && function_exists('posix_kill')) {
        @posix_kill($pid, 15);
    } else {
        @shell_exec("kill $pid 2>/dev/null");
    }
});

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
