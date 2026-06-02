<?php
declare(strict_types=1);
namespace App\Database\Driver;

/**
 * Picks a concrete driver from a raw DSN string. Lives in its own class
 * so callers don't grow a switch over driver names — they ask the
 * factory and forget.
 *
 * Accepted shapes:
 *   sqlite:/absolute/path/to/app.sqlite
 *   sqlite::memory:
 *   mysql:host=127.0.0.1;port=3306;dbname=otack;charset=utf8mb4
 *
 * Anything else (Postgres, SQL Server, etc.) raises a clear error.
 * docs/DATABASE.md §2 lists those as explicit non-goals for v1.
 */
final class DriverFactory
{
    /**
     * @param string $dsn   PDO DSN
     * @param array{username?:?string,password?:?string,charset?:string,collation?:string} $config
     */
    public static function make(string $dsn, array $config = []): DriverInterface
    {
        $scheme = strstr($dsn, ':', true);
        if ($scheme === false) {
            throw new \InvalidArgumentException("Malformed DSN (no scheme): $dsn");
        }
        return match ($scheme) {
            'sqlite' => new SqliteDriver($dsn),
            'mysql'  => new MysqlDriver(
                dsn:       $dsn,
                username:  $config['username']  ?? null,
                password:  $config['password']  ?? null,
                charset:   $config['charset']   ?? 'utf8mb4',
                collation: $config['collation'] ?? 'utf8mb4_0900_ai_ci',
            ),
            default => throw new \InvalidArgumentException(
                "Unsupported DB driver: '$scheme'. Supported: sqlite, mysql. "
                . "See docs/DATABASE.md §2."
            ),
        };
    }
}
