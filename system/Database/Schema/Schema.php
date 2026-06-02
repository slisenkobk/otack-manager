<?php
declare(strict_types=1);
namespace App\Database\Schema;

use App\Database\Driver\DriverInterface;

/**
 * Driver-aware façade passed to every migration. Migrations call into
 * this object instead of executing raw SQL against the PDO directly —
 * the driver decides what DDL to emit.
 *
 * Migrations that genuinely need raw access (seeding rows, complex
 * data migrations) reach for `$schema->pdo()`. Schema-shaping work
 * goes through createTable / alterTable / createIndex.
 */
final class Schema
{
    public function __construct(
        private \PDO $pdo,
        private DriverInterface $driver,
    ) {}

    public function pdo(): \PDO { return $this->pdo; }
    public function driver(): DriverInterface { return $this->driver; }
    public function driverName(): string { return $this->driver->name(); }

    /**
     * Build a brand-new table. The callback receives a Blueprint;
     * compile + execute happens once the callback returns.
     */
    public function createTable(string $name, callable $build): void
    {
        $bp = new Blueprint($name);
        $build($bp);
        $this->runStatements($this->driver->compileCreateTable($bp));
    }

    /** Convenience for the common pattern. */
    public function createTableIfNotExists(string $name, callable $build): void
    {
        $bp = new Blueprint($name);
        $bp->ifNotExists();
        $build($bp);
        $this->runStatements($this->driver->compileCreateTable($bp));
    }

    /**
     * Apply changes to an existing table. The callback can use the same
     * Blueprint surface as createTable, but only the column additions
     * and indexes are emitted as ALTER / CREATE INDEX statements.
     */
    public function alterTable(string $name, callable $build): void
    {
        $bp = new Blueprint($name);
        $build($bp);
        $this->runStatements($this->driver->compileAlterTable($bp));
    }

    /** Drop a table if it exists. Used very rarely (per data-preservation rule). */
    public function dropTableIfExists(string $name): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS ' . $name);
    }

    /**
     * Escape hatch for genuinely complex migrations that don't fit the
     * DSL (e.g. complex data backfills). Should be used sparingly — the
     * lint test in step 5 greps for usages to keep them visible.
     */
    public function execute(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    /** @param string[] $stmts */
    private function runStatements(array $stmts): void
    {
        foreach ($stmts as $sql) {
            if (trim($sql) === '') continue;
            $this->pdo->exec($sql);
        }
    }
}
