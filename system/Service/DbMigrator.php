<?php
declare(strict_types=1);
namespace App\Service;

use App\App;
use App\Database\Connection;
use App\Database\Driver\DriverFactory;
use App\Database\Migrations;
use App\Database\SchemaBootstrap;

/**
 * In-app SQLite → MySQL migrator (TODO #11, docs/DATABASE.md §11).
 *
 * The wizard is a thin layer over this class: collect MySQL credentials
 * → testConnection() → plan() → migrate() → admin pastes new env vars
 * → verifyEnv(). Source SQLite file is never modified.
 *
 * The migrator runs synchronously inside one HTTP request — we set a
 * generous time limit and ignore_user_abort. For a typical Otack
 * install (a few hundred MB) the wall-clock time is a few seconds.
 */
final class DbMigrator
{
    public const BATCH_SIZE = 500;

    /**
     * Test a MySQL connection without touching it further. Returns:
     *   {ok: true,  version: '8.0.36'}
     *   {ok: false, error:   '…'}
     *
     * @param array{host:string,port:int,db:string,user:string,password:string} $config
     */
    public function testConnection(array $config): array
    {
        try {
            $pdo = new \PDO($this->dsnFromConfig($config), $config['user'], $config['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 5,
            ]);
            $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
            // Reject MySQL < 8.0 (docs/DATABASE.md §13).
            if (version_compare($version, '8.0.0', '<')) {
                return ['ok' => false, 'error' => "MySQL $version found — 8.0+ required"];
            }
            // Reject non-empty databases (we expect to migrate INTO a fresh DB).
            $count = (int)$pdo->query(
                'SELECT COUNT(*) FROM information_schema.tables '
                . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME NOT LIKE 'sys_%'"
            )->fetchColumn();
            if ($count > 0) {
                return ['ok' => false, 'error' => "Target database is not empty ($count tables found)"];
            }
            return ['ok' => true, 'version' => $version];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Inspect the source SQLite and return per-table row counts +
     * total size. Surfaced to the admin as the "you're about to copy
     * N rows across M tables" summary before they click Start.
     *
     * @return array{tables: list<array{name:string, rows:int}>, total_rows:int, file_bytes:int}
     */
    public function plan(\PDO $source): array
    {
        $driver = Connection::driverFor($source);
        if ($driver === null || $driver->name() !== 'sqlite') {
            throw new \RuntimeException('DbMigrator::plan expects a SQLite source connection');
        }
        $tableNames = $source->query($driver->listTablesSql())->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        $tableNames = array_values(array_filter($tableNames, fn($n) => $n !== 'schema_migrations'));

        $tables = [];
        $total  = 0;
        foreach ($tableNames as $name) {
            $rows = (int)$source->query('SELECT COUNT(*) FROM ' . $this->q($name))->fetchColumn();
            $total += $rows;
            $tables[] = ['name' => $name, 'rows' => $rows];
        }

        $file = APP_ROOT . '/' . App::env('DB_PATH', 'data/app.sqlite');
        $size = @filesize($file) ?: 0;

        return ['tables' => $tables, 'total_rows' => $total, 'file_bytes' => $size];
    }

    /**
     * Copy the source SQLite into a fresh MySQL database.
     *
     * Steps:
     *   1. Open target via the same MysqlDriver the rest of the app uses.
     *   2. Run Migrations::run on the target — that builds the schema via
     *      the DSL, so the table shapes match what the app expects.
     *   3. Disable FK checks (so order of inserts doesn't matter).
     *   4. For each table: stream rows from SQLite in BATCH_SIZE chunks,
     *      INSERT into MySQL. Apply per-column coercion as needed (json
     *      stays as text — both drivers accept the JSON literal).
     *   5. Reset AUTO_INCREMENT on tables with an `id` column.
     *   6. Re-enable FK checks.
     *   7. Sanity-check row counts table-by-table.
     *
     * Returns a per-table report. Throws on any sanity-check mismatch
     * or driver error.
     *
     * @param array{host:string,port:int,db:string,user:string,password:string} $config
     * @return array{tables: list<array{name:string, source_rows:int, target_rows:int}>}
     */
    public function migrate(\PDO $source, array $config): array
    {
        @set_time_limit(0);
        @ignore_user_abort(true);

        $sourceDriver = Connection::driverFor($source);
        if ($sourceDriver?->name() !== 'sqlite') {
            throw new \RuntimeException('DbMigrator::migrate expects a SQLite source');
        }

        // 1. Open target
        $target = Connection::open($this->dsnFromConfig($config), [
            'username' => $config['user'],
            'password' => $config['password'],
        ]);

        // 2. Build schema on target
        SchemaBootstrap::$legacyMarkerDir = null;
        Migrations::run(new SchemaBootstrap($target));

        // 3. Disable FK checks
        $target->exec('SET FOREIGN_KEY_CHECKS = 0');

        // 4. Copy tables
        $tableNames = $source->query($sourceDriver->listTablesSql())->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        $tableNames = array_values(array_filter($tableNames, fn($n) => $n !== 'schema_migrations'));

        $report = [];
        foreach ($tableNames as $table) {
            $sourceRows = (int)$source->query('SELECT COUNT(*) FROM ' . $this->q($table))->fetchColumn();
            if ($sourceRows > 0) {
                $this->copyTable($source, $target, $table);
            }
            $report[] = [
                'name'        => $table,
                'source_rows' => $sourceRows,
                'target_rows' => (int)$target->query('SELECT COUNT(*) FROM ' . $this->q($table))->fetchColumn(),
            ];
        }

        // 5. Reset AUTO_INCREMENT on tables with an id column.
        foreach ($report as $row) {
            $this->resetAutoIncrement($target, $row['name']);
        }

        // 6. Re-enable FK checks
        $target->exec('SET FOREIGN_KEY_CHECKS = 1');

        // 7. Sanity check
        foreach ($report as $row) {
            if ($row['source_rows'] !== $row['target_rows']) {
                throw new \RuntimeException(
                    "Row-count mismatch on {$row['name']}: "
                    . "source={$row['source_rows']} target={$row['target_rows']}"
                );
            }
        }

        return ['tables' => $report];
    }

    /**
     * After the admin has pasted DB_DSN / DB_USER / DB_PASSWORD into
     * .env and reloaded, this opens the configured connection and
     * verifies the app sees the migrated data. Used by the wizard's
     * post-switch confirmation step.
     *
     * Returns {ok, message} — the wizard renders this verbatim.
     */
    public function verifyEnv(): array
    {
        try {
            $pdo = Connection::openFromEnv();
            $driver = Connection::driverFor($pdo);
            if ($driver?->name() !== 'mysql') {
                return ['ok' => false, 'message' => "Current driver is {$driver?->name()}, expected mysql — did you reload after editing .env?"];
            }
            $users = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $tables = (int)$pdo->query($driver->listTablesSql())->fetchAll();
            return ['ok' => true, 'message' => "Connected to MySQL — $users users visible"];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    // ─── internals ──────────────────────────────────────────────────────

    /** @param array{host:string,port:int,db:string,user:string,password:string} $c */
    private function dsnFromConfig(array $c): string
    {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $c['host'], (int)$c['port'], $c['db']
        );
    }

    /** Quote an identifier with backticks for MySQL and double-quotes for SQLite. */
    private function q(string $name): string
    {
        // Both SQLite and MySQL accept double-quote-quoted identifiers when
        // ANSI mode is on. MySQL's default has ANSI_QUOTES off, so double-
        // quotes are treated as string literals — keep backticks instead.
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * Stream rows from $source.$table into $target.$table in batches.
     * Uses unbuffered SQLite reads so very large tables don't load
     * everything into memory.
     */
    private function copyTable(\PDO $source, \PDO $target, string $table): void
    {
        // Discover the column list from the source — we INSERT in that
        // exact order so column-name ambiguity (different MySQL collations
        // around case) doesn't bite.
        $colsStmt = $source->query('SELECT * FROM ' . $this->q($table) . ' LIMIT 0');
        $colCount = $colsStmt->columnCount();
        $columns = [];
        for ($i = 0; $i < $colCount; $i++) {
            $columns[] = $colsStmt->getColumnMeta($i)['name'];
        }
        if (!$columns) return;

        $colList = implode(', ', array_map(fn($c) => $this->q($c), $columns));
        $placeholders = '(' . rtrim(str_repeat('?,', $colCount), ',') . ')';

        $stream = $source->query('SELECT ' . $colList . ' FROM ' . $this->q($table));
        $batch = [];
        $insert = static function (array $batch) use ($target, $table, $colList, $placeholders) {
            if (!$batch) return;
            $values = implode(', ', array_fill(0, count($batch), $placeholders));
            $sql = 'INSERT INTO ' . '`' . $table . '`' . " ($colList) VALUES $values";
            $stmt = $target->prepare($sql);
            $flat = [];
            foreach ($batch as $row) foreach ($row as $v) $flat[] = $v;
            $stmt->execute($flat);
        };

        foreach ($stream as $row) {
            $batch[] = array_values($row);
            if (count($batch) >= self::BATCH_SIZE) {
                $insert($batch);
                $batch = [];
            }
        }
        $insert($batch);
    }

    /**
     * If the table has an `id` column, set AUTO_INCREMENT to MAX(id)+1
     * so subsequent inserts don't collide with migrated rows.
     */
    private function resetAutoIncrement(\PDO $target, string $table): void
    {
        try {
            $hasId = $target->query(
                "SELECT 1 FROM information_schema.columns "
                . "WHERE TABLE_SCHEMA = DATABASE() "
                . "  AND TABLE_NAME = " . $target->quote($table)
                . "  AND COLUMN_NAME = 'id' LIMIT 1"
            )->fetchColumn();
            if (!$hasId) return;
            $max = (int)$target->query('SELECT IFNULL(MAX(id), 0) FROM ' . $this->q($table))->fetchColumn();
            $next = $max + 1;
            $target->exec('ALTER TABLE ' . $this->q($table) . ' AUTO_INCREMENT = ' . $next);
        } catch (\Throwable $_) {
            // Non-fatal — sanity check still runs.
        }
    }
}
