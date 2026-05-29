<?php
declare(strict_types=1);
namespace App\Database;

// Bookkeeping facade around the `schema_migrations` table. Owns the lock
// (BEGIN IMMEDIATE) for batched migration runs and exposes the small set of
// helpers Migrations::run() needs: applied-set lookup and runFile($path).
final class SchemaBootstrap
{
    // One-time backfill hook for installations that pre-date schema_migrations.
    // index.php / bin/migrate.php set this to APP_ROOT . '/data/.schema'; tests
    // and isolated runs leave it null so 0000_schema_migrations.php is a clean
    // no-op (no spurious "already applied" rows for a fresh DB).
    public static ?string $legacyMarkerDir = null;

    public function __construct(private \PDO $pdo) {}

    public function beginImmediate(): void
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
    }

    public function commit(): void
    {
        $this->pdo->exec('COMMIT');
    }

    public function rollBack(): void
    {
        try { $this->pdo->exec('ROLLBACK'); } catch (\Throwable $_) {}
    }

    /**
     * Returns a name => true map of migrations already applied. If the
     * schema_migrations table doesn't exist yet (very first boot, before
     * 0000_schema_migrations.php has run), returns an empty map.
     *
     * @return array<string, true>
     */
    public function appliedSet(): array
    {
        try {
            $rows = $this->pdo->query('SELECT name FROM schema_migrations')
                ->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\PDOException $_) {
            return [];
        }
        return array_flip($rows ?: []);
    }

    /**
     * Executes a single migration file and records it in schema_migrations.
     * The file must `return function (\PDO $pdo): void { ... };`.
     *
     * 0000_schema_migrations.php creates the bookkeeping table; this method
     * uses INSERT OR IGNORE so re-applying it is a no-op.
     */
    public function runFile(string $path): void
    {
        $name = basename($path, '.php');
        $closure = require $path;
        if (!is_callable($closure)) {
            throw new \RuntimeException(
                "Migration {$name} must return a Closure(PDO); got " . gettype($closure)
            );
        }
        $closure($this->pdo);

        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO schema_migrations (name, applied_at) VALUES (?, ?)'
        );
        $stmt->execute([$name, date('Y-m-d H:i:s')]);
    }

    /**
     * Pending = on-disk minus applied. Used by Compass + CLI status.
     *
     * @return list<array{name:string, applied:bool}>
     */
    public function status(string $migrationDir): array
    {
        $files = glob($migrationDir . '/*.php') ?: [];
        sort($files);
        $applied = $this->appliedSet();
        $out = [];
        foreach ($files as $f) {
            $name = basename($f, '.php');
            $out[] = ['name' => $name, 'applied' => isset($applied[$name])];
        }
        return $out;
    }
}
