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

    public function compileCreateTable(\App\Database\Schema\Blueprint $bp): array
    {
        $colSql = [];
        foreach ($bp->columns as $c) {
            $colSql[] = $this->columnSql($c);
        }
        // Inline FK + composite UNIQUE constraints (SQLite supports them inline).
        foreach ($bp->foreignKeys as $fk) {
            $colSql[] = $this->foreignKeySql($fk);
        }
        foreach ($bp->indexes as $idx) {
            // SQLite inlines UNIQUE only when it's the column-level case;
            // composite UNIQUEs land here.
            if ($idx->unique && count($idx->columns) > 1) {
                $colSql[] = 'UNIQUE(' . implode(', ', $idx->columns) . ')';
            }
        }

        $head = 'CREATE TABLE' . ($bp->ifNotExists ? ' IF NOT EXISTS' : '') . ' ' . $bp->table;
        $stmts = [$head . " (\n  " . implode(",\n  ", $colSql) . "\n)"];

        foreach ($bp->indexes as $idx) {
            if ($idx->unique && count($idx->columns) > 1) continue; // already inlined
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

    private function columnSql(\App\Database\Schema\Column $c): string
    {
        $type = match ($c->type) {
            'id'         => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'integer'    => 'INTEGER',
            'bigInteger' => 'INTEGER',
            'string'     => 'TEXT',
            'text'       => 'TEXT',
            'boolean'    => 'INTEGER',
            'real'       => 'REAL',
            'decimal'    => 'TEXT', // SQLite stores as text to preserve precision
            'json'       => 'TEXT',
            'timestamp'  => 'TEXT',
            'date'       => 'TEXT',
            default      => throw new \RuntimeException("Unknown column type: {$c->type}"),
        };
        $parts = [$c->name, $type];
        if ($c->type !== 'id') {
            if (!$c->nullable) $parts[] = 'NOT NULL';
            if ($c->hasDefault) $parts[] = 'DEFAULT ' . $this->defaultLiteral($c->default);
            if ($c->unique)    $parts[] = 'UNIQUE';
            if ($c->primary)   $parts[] = 'PRIMARY KEY';
        }
        return implode(' ', $parts);
    }

    private function foreignKeySql(\App\Database\Schema\ForeignKey $fk): string
    {
        $sql = "FOREIGN KEY({$fk->column}) REFERENCES {$fk->referencedTable}({$fk->referencedColumn})";
        if ($fk->onDelete) $sql .= ' ON DELETE ' . $fk->onDelete;
        if ($fk->onUpdate) $sql .= ' ON UPDATE ' . $fk->onUpdate;
        return $sql;
    }

    private function indexSql(string $table, \App\Database\Schema\Index $idx): string
    {
        $name = $idx->inferredName($table);
        $kw = $idx->unique ? 'CREATE UNIQUE INDEX' : 'CREATE INDEX';
        if ($idx->ifNotExists) $kw .= ' IF NOT EXISTS';
        return "$kw $name ON $table(" . implode(', ', $idx->columns) . ')';
    }

    private function defaultLiteral(string|int|float|bool|null $v): string
    {
        if ($v === null)         return 'NULL';
        if (is_bool($v))         return $v ? '1' : '0';
        if (is_int($v) || is_float($v)) return (string)$v;
        // Quote the string with single-quote escaping.
        return "'" . str_replace("'", "''", (string)$v) . "'";
    }
}
