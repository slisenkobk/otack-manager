<?php
declare(strict_types=1);
namespace App\Database;

use App\Database\Driver\DriverFactory;
use App\Database\Driver\DriverInterface;
use App\Database\Driver\SqliteDriver;

/**
 * Entry point for opening a DB connection. Step 1 of TODO #1
 * (docs/DATABASE.md): branches on DSN scheme and hands off to a
 * concrete driver — but keeps the historical `Connection::open($path)`
 * signature working for tests, bin/migrate.php, and any caller that
 * pre-dates dual-driver support.
 *
 * Two ways to open:
 *
 *   1. Connection::open('/path/to/app.sqlite') — backwards-compat:
 *      a raw filesystem path is implicitly wrapped as sqlite:{path}.
 *
 *   2. Connection::open('sqlite:…')   — fully-qualified DSN.
 *      Connection::open('mysql:…', ['username' => ..., 'password' => ...])
 *
 * Use `Connection::openFromEnv()` from bootstrap code that wants the
 * usual `DB_DSN` / `DB_PATH` env precedence in one call.
 *
 * The opened PDO is the same object the rest of the app already uses;
 * the driver is also exposed via `Connection::driverFor($pdo)` so code
 * that needs dialect-specific behaviour can ask for it.
 */
final class Connection
{
    /** @var \WeakMap<\PDO, DriverInterface>|null */
    private static ?\WeakMap $driverFor = null;

    /**
     * @param string $dsnOrPath  Either a real PDO DSN ('sqlite:…' / 'mysql:…')
     *                           or a bare filesystem path (legacy).
     * @param array{username?:?string,password?:?string,charset?:string,collation?:string} $config
     */
    public static function open(string $dsnOrPath, array $config = []): \PDO
    {
        $driver = self::resolveDriver($dsnOrPath, $config);
        return self::connectWithDriver($driver);
    }

    /**
     * Open a connection using `.env`-driven configuration. Precedence:
     *   1. DB_DSN  — full PDO DSN; wins when present (sqlite: or mysql:).
     *   2. DB_PATH — SQLite file path; the zero-config default for an
     *      out-of-the-box install (data/app.sqlite).
     */
    public static function openFromEnv(): \PDO
    {
        // App::env() requires a string default — read with '' and treat
        // empty as "not configured" downstream.
        $envOrNull = static function (string $k, string $default = ''): ?string {
            $v = \App\App::env($k, $default);
            return $v === '' ? null : $v;
        };

        $dsn = $envOrNull('DB_DSN');
        if ($dsn !== null) {
            return self::open($dsn, [
                'username'  => $envOrNull('DB_USER'),
                'password'  => $envOrNull('DB_PASSWORD'),
                'charset'   => $envOrNull('DB_CHARSET',   'utf8mb4')              ?? 'utf8mb4',
                'collation' => $envOrNull('DB_COLLATION', 'utf8mb4_0900_ai_ci')   ?? 'utf8mb4_0900_ai_ci',
            ]);
        }
        $path = $envOrNull('DB_PATH', 'data/app.sqlite') ?? 'data/app.sqlite';
        return self::open(APP_ROOT . '/' . $path);
    }

    /** @internal — used by tests and stdlib snapshot logic that wants the dialect. */
    public static function driverFor(\PDO $pdo): ?DriverInterface
    {
        if (self::$driverFor === null) return null;
        return self::$driverFor[$pdo] ?? null;
    }

    // ─── internals ──────────────────────────────────────────────────────

    private static function resolveDriver(string $dsnOrPath, array $config): DriverInterface
    {
        // "scheme:rest" — treat as a real DSN.
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $dsnOrPath) === 1) {
            return DriverFactory::make($dsnOrPath, $config);
        }
        // Legacy bare-path signature — wrap as sqlite, ensuring the parent
        // directory exists so a fresh checkout works without `mkdir data`.
        return SqliteDriver::fromPath($dsnOrPath);
    }

    private static function connectWithDriver(DriverInterface $driver): \PDO
    {
        $pdo = new \PDO(
            $driver->dsn(),
            $driver->username(),
            $driver->password(),
            $driver->pdoOptions()
        );
        $driver->postConnect($pdo);

        if (self::$driverFor === null) self::$driverFor = new \WeakMap();
        self::$driverFor[$pdo] = $driver;
        return $pdo;
    }
}
