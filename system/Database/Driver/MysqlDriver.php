<?php
declare(strict_types=1);
namespace App\Database\Driver;

/**
 * MySQL driver. Pulls username/password/charset from the supplied .env
 * shape (docs/DATABASE.md §7) rather than parsing them out of the DSN —
 * the DSN itself stays a flat connection string.
 *
 * Targets MySQL 8.0+. The post-connect block locks down a strict sql_mode
 * + utf8mb4 charset so the app behaves the same on every host.
 */
final class MysqlDriver implements DriverInterface
{
    public function __construct(
        private string $dsn,
        private ?string $username,
        private ?string $password,
        private string $charset = 'utf8mb4',
        private string $collation = 'utf8mb4_0900_ai_ci',
    ) {}

    public function name(): string { return 'mysql'; }

    public function dsn(): string { return $this->dsn; }

    public function username(): ?string { return $this->username; }

    public function password(): ?string { return $this->password; }

    public function pdoOptions(): array
    {
        return [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            // Stop the driver from rewriting all parameters as strings
            // when a LIMIT placeholder needs to be an int.
            \PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
        ];
    }

    public function postConnect(\PDO $pdo): void
    {
        // Connection-level charset/collation. The DSN may also carry
        // charset=utf8mb4 — these statements make the choice explicit
        // regardless of how the DSN was assembled.
        $pdo->exec("SET NAMES '{$this->charset}' COLLATE '{$this->collation}'");
        // Strict mode that matches MySQL 8 defaults but pins them so a
        // permissively-configured server doesn't silently truncate.
        $pdo->exec(
            "SET SESSION sql_mode = "
            . "'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,"
            . "ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'"
        );
        // App-level timezone is UTC; SQLite stores ISO strings, MySQL
        // stores DATETIME values. Forcing the connection TZ to UTC means
        // CURRENT_TIMESTAMP and NOW() agree with the PHP-side ISO strings.
        $pdo->exec("SET time_zone = '+00:00'");
    }
}
