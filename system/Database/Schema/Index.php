<?php
declare(strict_types=1);
namespace App\Database\Schema;

final class Index
{
    public bool $unique = false;
    public bool $ifNotExists = false;
    public ?string $name = null;

    /** @param string[] $columns */
    public function __construct(public readonly array $columns) {}

    public function unique(bool $v = true): self { $this->unique = $v; return $this; }
    public function ifNotExists(bool $v = true): self { $this->ifNotExists = $v; return $this; }
    public function name(string $n): self { $this->name = $n; return $this; }

    /** Derives a stable index name from the column list when none was set. */
    public function inferredName(string $table): string
    {
        if ($this->name !== null) return $this->name;
        $cols = implode('_', $this->columns);
        return ($this->unique ? 'uniq_' : 'idx_') . $table . '_' . $cols;
    }
}
