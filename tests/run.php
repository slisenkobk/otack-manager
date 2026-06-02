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

/**
 * Apply a single migration file to a PDO, dispatching on the closure's
 * parameter type the same way SchemaBootstrap::runFile does. Test helper:
 * existing tests pass a PDO; new tests can drop the helper in directly.
 */
function apply_migration(PDO $pdo, string $migrationPath): void
{
    $closure = require $migrationPath;
    if (!is_callable($closure)) return;
    $ref = new ReflectionFunction(Closure::fromCallable($closure));
    $params = $ref->getParameters();
    $wantSchema = false;
    if ($params) {
        $type = $params[0]->getType();
        if ($type instanceof ReflectionNamedType
            && $type->getName() === \App\Database\Schema\Schema::class) {
            $wantSchema = true;
        }
    }
    if ($wantSchema) {
        $driver = \App\Database\Connection::driverFor($pdo)
            ?? new \App\Database\Driver\SqliteDriver('sqlite::memory:');
        $closure(new \App\Database\Schema\Schema($pdo, $driver));
    } else {
        $closure($pdo);
    }
}

$dir = $argv[1] ?? __DIR__ . '/unit';
foreach (glob($dir . '/test_*.php') as $f) {
    echo basename($f) . "\n";
    require $f;
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
