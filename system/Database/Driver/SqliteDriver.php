<?php
declare(strict_types=1);
namespace App\Database\Driver;

/**
 * SQLite driver. Mirrors the behaviour that lived directly in the old
 * Connection::open() — same PRAGMA setup (foreign_keys, WAL, busy_timeout),
 * same PDO attributes — so existing installations see no behaviour change
 * after step 1.
 *
 * Accepts either:
 *   sqlite:/abs/path/to/app.sqlite
 *   sqlite::memory:
 */
final class SqliteDriver implements DriverInterface
{
    public function __construct(private string $dsn) {}

    public function name(): string { return 'sqlite'; }

    public function dsn(): string { return $this->dsn; }

    public function username(): ?string { return null; }

    public function password(): ?string { return null; }

    public function pdoOptions(): array
    {
        return [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ];
    }

    public function postConnect(\PDO $pdo): void
    {
        foreach ([
            'PRAGMA foreign_keys = ON',
            'PRAGMA journal_mode = WAL',
            'PRAGMA busy_timeout = 5000',
        ] as $stmt) {
            $pdo->query($stmt);
        }
    }

    /**
     * Helper for callers that have just a filesystem path (the historical
     * Connection::open($path) signature, every test, bin/migrate.php).
     * Ensures the parent directory exists so a fresh checkout works
     * without manual `mkdir data`.
     */
    public static function fromPath(string $path): self
    {
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return new self('sqlite:' . $path);
    }
}
