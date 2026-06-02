<?php
declare(strict_types=1);
namespace App\Database\Schema;

/**
 * Fluent table definition. Used in both CREATE TABLE and ALTER TABLE
 * contexts:
 *
 *   $schema->createTable('users', function (Blueprint $t) {
 *       $t->id();
 *       $t->string('email')->unique();
 *       $t->timestamp('created_at');
 *   });
 *
 *   $schema->alterTable('tasks', function (Blueprint $t) {
 *       $t->string('priority', 16)->default('none');
 *   });
 *
 * The driver compiler reads the public arrays directly — no behaviour
 * on this class beyond the chainable type helpers.
 */
final class Blueprint
{
    /** @var Column[] */
    public array $columns = [];
    /** @var Index[] */
    public array $indexes = [];
    /** @var ForeignKey[] */
    public array $foreignKeys = [];

    public bool $ifNotExists = false;
    /** @var string[]|null Composite PK columns; null means single-col PK lives on a Column. */
    public ?array $primaryColumns = null;

    public function __construct(public readonly string $table) {}

    public function ifNotExists(bool $v = true): self { $this->ifNotExists = $v; return $this; }

    /** Declare a composite PRIMARY KEY across multiple columns. */
    public function primary(array $columns): self
    {
        $this->primaryColumns = array_values($columns);
        return $this;
    }

    // ─── columns ────────────────────────────────────────────────────────

    public function id(string $name = 'id'): Column
    {
        $c = new Column($name, 'id');
        $c->primary = true;
        $c->autoIncrement = true;
        return $this->columns[] = $c;
    }

    public function integer(string $name): Column     { return $this->columns[] = new Column($name, 'integer'); }
    public function bigInteger(string $name): Column  { return $this->columns[] = new Column($name, 'bigInteger'); }
    public function string(string $name, int $length = 255): Column
                                                      { return $this->columns[] = new Column($name, 'string', length: $length); }
    public function text(string $name): Column        { return $this->columns[] = new Column($name, 'text'); }
    public function boolean(string $name): Column     { return $this->columns[] = new Column($name, 'boolean'); }
    public function real(string $name): Column        { return $this->columns[] = new Column($name, 'real'); }
    public function decimal(string $name, int $precision, int $scale): Column
                                                      { return $this->columns[] = new Column($name, 'decimal', precision: $precision, scale: $scale); }
    public function json(string $name): Column        { return $this->columns[] = new Column($name, 'json'); }
    public function timestamp(string $name): Column   { return $this->columns[] = new Column($name, 'timestamp'); }
    public function date(string $name): Column        { return $this->columns[] = new Column($name, 'date'); }

    // ─── indexes ────────────────────────────────────────────────────────

    /** @param string|string[] $columns */
    public function index(string|array $columns): Index
    {
        $idx = new Index(is_array($columns) ? array_values($columns) : [$columns]);
        $this->indexes[] = $idx;
        return $idx;
    }

    /** @param string|string[] $columns */
    public function unique(string|array $columns): Index
    {
        return $this->index($columns)->unique();
    }

    // ─── foreign keys ───────────────────────────────────────────────────

    public function foreign(string $column): ForeignKey
    {
        $fk = new ForeignKey($column);
        $this->foreignKeys[] = $fk;
        return $fk;
    }
}
