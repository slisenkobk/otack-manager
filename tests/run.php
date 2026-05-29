<?php
declare(strict_types=1);

// Suppress the default-admin seed migration when running tests against tmp
// SQLite DBs — the test suite needs to control the user count itself.
// bootstrap.php's .env loader honours pre-set $_ENV / getenv values, so this
// wins over any value in the real .env file.
$_ENV['SEED_DEFAULT_ADMIN_EMAIL'] = '';
putenv('SEED_DEFAULT_ADMIN_EMAIL=');

$root = dirname(__DIR__);
require $root . '/system/bootstrap.php';

$pass = 0;
$fail = 0;

function it(string $name, callable $fn): void
{
    global $pass, $fail;
    try {
        $fn();
        echo "  v $name\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  x $name\n    {$e->getMessage()}\n";
        $fail++;
    }
}

function assert_eq($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            ($msg ?: 'assert_eq failed') .
            ' — expected ' . var_export($expected, true) .
            ', got ' . var_export($actual, true)
        );
    }
}

function assert_true(bool $cond, string $msg = ''): void
{
    if (!$cond) {
        throw new RuntimeException($msg ?: 'expected true');
    }
}

$dir = $argv[1] ?? __DIR__ . '/unit';
foreach (glob($dir . '/test_*.php') as $f) {
    echo basename($f) . "\n";
    require $f;
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
