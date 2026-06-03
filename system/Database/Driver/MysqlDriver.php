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

    public function compileCreateTable(\App\Database\Schema\Blueprint $bp): array
    {
        $defs = [];
        foreach ($bp->columns as $c) {
            $defs[] = $this->columnSql($c);
        }
        // UNIQUE composite constraints
        foreach ($bp->indexes as $idx) {
            if ($idx->unique && count($idx->columns) > 1) {
                $name = $idx->inferredName($bp->table);
                $defs[] = "UNIQUE KEY $name (" . implode(', ', $idx->columns) . ')';
            }
        }
        if ($bp->primaryColumns !== null) {
            $defs[] = 'PRIMARY KEY (' . implode(', ', $bp->primaryColumns) . ')';
        }
        // Foreign keys (MySQL needs the CONSTRAINT clause in the table body)
        foreach ($bp->foreignKeys as $fk) {
            $defs[] = $this->foreignKeySql($bp->table, $fk);
        }

        $head = 'CREATE TABLE' . ($bp->ifNotExists ? ' IF NOT EXISTS' : '') . ' ' . $bp->table;
        $tail = ' ENGINE=InnoDB DEFAULT CHARSET=' . $this->charset . ' COLLATE=' . $this->collation;
        $stmts = [$head . " (\n  " . implode(",\n  ", $defs) . "\n)" . $tail];

        foreach ($bp->indexes as $idx) {
            if ($idx->unique && count($idx->columns) > 1) continue; // already inline
            $stmts[] = $this->indexSql($bp->table, $idx);
        }

        return $stmts;
    }

    public function compileAlterTable(\App\Database\Schema\Blueprint $bp): array
    {
        $stmts = [];
        foreach ($bp->columns as $c) {
            $stmts[] = 'ALTER TABLE ' . $bp->table . ' ADD COLUMN ' . $this->columnSql($c);
        }
        foreach ($bp->indexes as $idx) {
            $stmts[] = $this->indexSql($bp->table, $idx);
        }
        return $stmts;
    }

    public function insertIgnoreVerb(): string { return 'INSERT IGNORE'; }
    public function paginationAllOffsetSql(): string
    {
        // MySQL requires a literal limit operand when OFFSET is present.
        // 2^64-1 is the documented "no upper bound" sentinel.
        return 'LIMIT 18446744073709551615 OFFSET ?';
    }
    public function listTablesSql(): string
    {
        return "SELECT TABLE_NAME AS name "
             . "FROM information_schema.tables "
             . "WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME";
    }

    public function snapshotFor(\PDO $pdo): \App\Database\Snapshot\SnapshotInterface
    {
        $parsed = $this->parseDsn();
        // Unix-socket DSNs must NOT silently fall back to --host=127.0.0.1
        // for mysqldump — many setups bind one but not the other and the
        // dump would fail with a confusing "can't connect" message.
        $socket = $parsed['unix_socket'] ?? null;
        return new \App\Database\Snapshot\MysqlSnapshot([
            'host'     => $socket === null ? ($parsed['host'] ?? '127.0.0.1') : null,
            'port'     => (int)($parsed['port'] ?? 3306),
            'db'       => $parsed['dbname'] ?? '',
            'user'     => $this->username,
            'password' => $this->password,
            'socket'   => $socket,
        ]);
    }

    /** @return array<string,string> name/value pairs parsed out of `key=val;…` */
    private function parseDsn(): array
    {
        $body = (string)preg_replace('/^mysql:/', '', $this->dsn);
        $out = [];
        foreach (explode(';', $body) as $pair) {
            if (!str_contains($pair, '=')) continue;
            [$k, $v] = explode('=', $pair, 2);
            $out[trim($k)] = trim($v);
        }
        return $out;
    }

    private function columnSql(\App\Database\Schema\Column $c): string
    {
        // For string we need the length; everything else is straightforward.
        $type = match ($c->type) {
            'id'         => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'integer'    => 'INT',
            // BIGINT UNSIGNED so FK references to `id` columns (which are
            // BIGINT UNSIGNED via the `id` mapping above) line up. MySQL
            // 8 refuses FKs across signed/unsigned mismatch. Application
            // columns of this type (entity_id, project_id, size, etc.)
            // are all non-negative, so UNSIGNED is the safer default.
            'bigInteger' => 'BIGINT UNSIGNED',
            'string'     => 'VARCHAR(' . ($c->length ?? 255) . ')',
            'text'       => 'MEDIUMTEXT',
            'boolean'    => 'TINYINT(1)',
            'real'       => 'DOUBLE',
            'decimal'    => 'DECIMAL(' . ($c->precision ?? 10) . ',' . ($c->scale ?? 2) . ')',
            'json'       => 'JSON',
            // Repositories write ISO8601 with a trailing `Z` (UTC marker)
            // which MySQL DATETIME does not accept. Storing as VARCHAR(32)
            // keeps SQLite/MySQL round-trip identical and lets the app
            // continue doing date math in PHP. Trades native DATETIME
            // indexing for portability — fine at our scale.
            'timestamp'  => 'VARCHAR(32)',
            'date'       => 'DATE',
            default      => throw new \RuntimeException("Unknown column type: {$c->type}"),
        };
        if ($c->type === 'id') return $c->name . ' ' . $type;

        $parts = [$c->name, $type];
        if (!$c->nullable)  $parts[] = 'NOT NULL';
        if ($c->hasDefault) {
            // MySQL 8.0.13+ requires DEFAULT (expr) for JSON/TEXT/BLOB.
            // Wrap unconditionally for those types; everything else takes
            // the plain literal form.
            $needsParens = in_array($c->type, ['json', 'text'], true);
            $lit = $this->defaultLiteral($c->default);
            $parts[] = 'DEFAULT ' . ($needsParens ? "($lit)" : $lit);
        }
        if ($c->unique)     $parts[] = 'UNIQUE';
        if ($c->primary)    $parts[] = 'PRIMARY KEY';
        return implode(' ', $parts);
    }

    private function foreignKeySql(string $table, \App\Database\Schema\ForeignKey $fk): string
    {
        $name = 'fk_' . $table . '_' . $fk->column;
        $sql = "CONSTRAINT $name FOREIGN KEY ({$fk->column}) "
             . "REFERENCES {$fk->referencedTable}({$fk->referencedColumn})";
        if ($fk->onDelete) $sql .= ' ON DELETE ' . $fk->onDelete;
        if ($fk->onUpdate) $sql .= ' ON UPDATE ' . $fk->onUpdate;
        return $sql;
    }

    private function indexSql(string $table, \App\Database\Schema\Index $idx): string
    {
        $name = $idx->inferredName($table);
        $kw = $idx->unique ? 'CREATE UNIQUE INDEX' : 'CREATE INDEX';
        // MySQL has no "CREATE INDEX IF NOT EXISTS" but supports
        // ALTER TABLE ... ADD INDEX which errors on dup. Keep simple
        // CREATE INDEX; migrations are idempotent via the runner's
        // applied-set check, so re-execution doesn't happen.
        return "$kw $name ON $table(" . implode(', ', $idx->columns) . ')';
    }

    private function defaultLiteral(string|int|float|bool|null $v): string
    {
        if ($v === null)         return 'NULL';
        if (is_bool($v))         return $v ? '1' : '0';
        if (is_int($v) || is_float($v)) return (string)$v;
        return "'" . str_replace("'", "''", (string)$v) . "'";
    }
}
