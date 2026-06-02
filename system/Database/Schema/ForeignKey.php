<?php
declare(strict_types=1);
namespace App\Database\Schema;

/**
 * A single foreign-key constraint. SQLite parses the inline-column form
 * (`REFERENCES users(id) ON DELETE CASCADE`); MySQL emits a separate
 * CONSTRAINT clause. Both forms are produced from the same definition.
 */
final class ForeignKey
{
    public ?string $referencedColumn = null;
    public ?string $referencedTable  = null;
    public ?string $onDelete = null;
    public ?string $onUpdate = null;

    public function __construct(public readonly string $column) {}

    public function references(string $column): self { $this->referencedColumn = $column; return $this; }
    public function on(string $table): self          { $this->referencedTable  = $table;  return $this; }
    public function onDelete(string $action): self   { $this->onDelete = strtoupper($action); return $this; }
    public function onUpdate(string $action): self   { $this->onUpdate = strtoupper($action); return $this; }
}
