<?php
declare(strict_types=1);
namespace App\Database\Schema;

/**
 * One column in a Blueprint. The DSL type names follow docs/DATABASE.md §4:
 * `id`, `bigInteger`, `integer`, `string`, `text`, `boolean`, `real`,
 * `decimal`, `json`, `timestamp`, `date`. Drivers translate them to per-
 * dialect SQL in `compileCreateTable()` / `compileAddColumn()`.
 *
 * Public state intentionally — this is a value carrier between the
 * Blueprint and the driver compiler. No behaviour, no invariants beyond
 * "the methods you call return $this so the user can chain".
 */
final class Column
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly ?int $length = null,
        public readonly ?int $precision = null,
        public readonly ?int $scale = null,
    ) {}

    public bool $nullable = false;
    public bool $unique = false;
    public bool $primary = false;
    public bool $autoIncrement = false;
    /** Raw DEFAULT expression as the user wrote it; null = no DEFAULT clause. */
    public string|int|float|bool|null $default = null;
    /** Set to true when default() has been called (so we can distinguish "default = null" from "no default"). */
    public bool $hasDefault = false;

    public function nullable(bool $v = true): self { $this->nullable = $v; return $this; }
    public function unique(bool $v = true): self  { $this->unique = $v;  return $this; }

    /** Marks a single-column primary key. Use Blueprint::id() for auto-increment PKs. */
    public function primary(bool $v = true): self { $this->primary = $v; return $this; }

    public function default(string|int|float|bool|null $v): self
    {
        $this->default = $v;
        $this->hasDefault = true;
        return $this;
    }
}
